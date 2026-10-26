<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\LedgerPeriodRequest;
use App\Http\Requests\Api\MoveLedgerTransactionRequest;
use App\Http\Requests\Api\SaveLedgerAccountRequest;
use App\Http\Requests\Api\SaveLedgerCategoryRequest;
use App\Http\Requests\Api\SaveLedgerTransactionRequest;
use App\Http\Requests\Api\StoreExploitationExpenseRequest;
use App\Models\ActivityLog;
use App\Models\EntityLink;
use App\Models\LedgerAccount;
use App\Models\LedgerCategory;
use App\Models\LedgerTransaction;
use App\Models\Scope;
use App\Models\Thing;
use App\Services\ContractorContext;
use App\Services\EquipAccessService;
use App\Services\LedgerAccessService;
use App\Services\LedgerInterestService;
use App\Services\LedgerMoney;
use App\Services\LedgerReportService;
use App\Services\LedgerService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LedgerController extends Controller
{
    private const RELATIONS = ['account.project', 'targetAccount.project', 'allocationProject', 'category', 'exploitationEntry.thing'];

    public function __construct(private readonly LedgerAccessService $access, private readonly ContractorContext $context, private readonly LedgerService $service, private readonly EquipAccessService $equip, private readonly LedgerInterestService $interest) {}

    public function options(Request $request, Scope $scope): JsonResponse
    {
        $actor = $this->context->actor($request);
        $permissions = $this->permissions($request, $scope);
        $writeProjects = $this->access->projects($actor, $scope, 'ledger.write')->pluck('id')->all();
        $manageProjects = $this->access->projects($actor, $scope, 'ledger.manage')->pluck('id')->all();
        $projects = $this->access->projects($actor, $scope)->orderBy('sort_order')->orderBy('id')->get(['id', 'title', 'key', 'deleted_at']);
        $accounts = $this->service->balances($this->access->accounts($actor, $scope))->with('project:id,title,key,deleted_at')->orderBy('sort_order')->orderBy('id')->get();

        return response()->json(['data' => [
            'projects' => $projects->map(fn ($project): array => [...$project->toArray(), 'can_write' => in_array($project->id, $writeProjects, true), 'can_manage' => in_array($project->id, $manageProjects, true)])->values(),
            'accounts' => $this->interest->forecast($this->interest->enrich($accounts))->map(fn (LedgerAccount $account): array => $this->presentAccount($account, $permissions))->values(),
            'categories' => $this->access->categories($actor, $scope)->orderBy('sort_order')->orderBy('id')->get(),
            'currencies' => LedgerMoney::CURRENCIES,
        ]]);
    }

    public function flow(LedgerPeriodRequest $request, Scope $scope): JsonResponse
    {
        $accounts = $this->periodAccounts($request, $scope, false);
        [$from, $to] = $this->period($request);
        $ids = $accounts->modelKeys();
        $query = LedgerTransaction::where('scope_id', $scope->id)->whereBetween('occurred_on', [$from->toDateString(), $to->toDateString()])
            ->where(fn (Builder $query) => $query->whereIn('account_id', $ids)->orWhereIn('target_account_id', $ids));
        abort_if((clone $query)->count() > 5000, 422, 'В периоде больше 5000 операций. Выберите меньше счетов или меньший период.');
        $permissions = $this->permissions($request, $scope);
        $transactions = $query->select(['id', 'scope_id', 'account_id', 'target_account_id', 'category_id', 'allocation_project_id', 'exploitation_entry_id', 'cost_type', 'created_by', 'creator_name', 'kind', 'amount_minor', 'currency', 'occurred_on', 'status', 'title', 'is_pinned', 'revision', 'created_at', 'updated_at', 'deleted_at'])
            ->with(self::RELATIONS)->orderBy('occurred_on')->orderBy('id')->get();

        return response()->json(['data' => [
            'accounts' => $accounts->map(fn (LedgerAccount $account): array => [
                ...$account->only(['id', 'project_id', 'title', 'currency', 'color', 'is_archived', 'balance_is_known', 'exclude_from_reports']),
                'project' => $account->project?->only(['id', 'title', 'key']),
                'can_write' => isset($permissions['write'][$account->id]) && ! $account->is_archived && $account->balance_is_known,
                'can_manage' => isset($permissions['manage'][$account->id]),
            ])->values(),
            'transactions' => $transactions->map(fn (LedgerTransaction $transaction): array => $this->presentTransaction($request, $scope, $transaction, $permissions, false))->values(),
            'daily' => $this->interest->dailyBalances($accounts, $from, $to, $request->boolean('forecast')), 'as_of' => now()->toDateString(),
            'forecast' => $request->boolean('forecast'),
            'calculated_through' => ($request->boolean('forecast') ? $to : $to->min(CarbonImmutable::today()))->toDateString(),
        ]]);
    }

    public function report(LedgerPeriodRequest $request, Scope $scope, LedgerReportService $reports): JsonResponse
    {
        $accounts = $this->periodAccounts($request, $scope, true);
        [$from, $to] = $this->period($request);
        if (in_array($request->input('group_by'), ['categories', 'projects'], true)) {
            $actor = $this->context->actor($request);
            $projects = $this->access->projects($actor, $scope)->get(['id', 'title', 'key']);
            $categories = $this->access->categories($actor, $scope)->get(['id', 'title', 'parent_id']);
            $allocation = $request->input('allocation_project_id');
            abort_if($allocation && ! $projects->contains('id', $allocation), 404);

            return response()->json(['data' => $reports->expenses($accounts, $categories, $projects, $from, $to, $request->boolean('forecast'), $allocation, $request->boolean('all_time'))]);
        }

        return response()->json(['data' => [
            'accounts' => $accounts->map(fn (LedgerAccount $account): array => $account->only(['id', 'title', 'project_id', 'currency', 'color', 'is_archived', 'balance_is_known']))->values(),
            'months' => $reports->monthly($accounts, $from, $to, $request->boolean('forecast')),
            'forecast' => $request->boolean('forecast'),
            'as_of' => ($request->boolean('forecast') ? $to : $to->min(CarbonImmutable::today()))->toDateString(),
        ]]);
    }

    private function periodAccounts(LedgerPeriodRequest $request, Scope $scope, bool $report): Collection
    {
        $data = $request->validated();
        $query = $this->access->accounts($this->context->actor($request), $scope);
        if (! empty($data['project_id'])) {
            $query->where('project_id', $data['project_id']);
        }
        if (! empty($data['account_id'])) {
            abort_unless((clone $query)->whereKey($data['account_id'])->exists(), 404);
            $query->whereKey($data['account_id']);
        }
        if (! empty($data['account_ids'])) {
            abort_unless((clone $query)->whereIn('id', $data['account_ids'])->count() === count($data['account_ids']), 404);
            $query->whereIn('id', $data['account_ids']);
        }
        if ($report) {
            $query->where('exclude_from_reports', false);
        }
        if (! $request->boolean('archived')) {
            $query->where('is_archived', false);
        }

        return $query->with('project:id,title,key,deleted_at')->orderBy('sort_order')->orderBy('id')->get();
    }

    private function period(LedgerPeriodRequest $request): array
    {
        $data = $request->validated();

        return [CarbonImmutable::createFromFormat('!Y-m', $data['start']), CarbonImmutable::createFromFormat('!Y-m', $data['end'])->endOfMonth()];
    }

    public function moveTransaction(MoveLedgerTransactionRequest $request, Scope $scope, LedgerTransaction $transaction): JsonResponse
    {
        $transaction = $this->service->moveTransaction($request, $scope, $transaction, $request->validated())->load(self::RELATIONS);

        return response()->json(['data' => $this->presentTransaction($request, $scope, $transaction, $this->permissions($request, $scope))]);
    }

    public function accounts(Request $request, Scope $scope): JsonResponse
    {
        $filters = $request->validate(['project_id' => ['nullable', 'ulid'], 'page' => ['nullable', 'integer', 'min:1'], 'archived' => ['nullable', 'boolean']]);
        $query = $this->access->accounts($this->context->actor($request), $scope)->with('project:id,title,key,deleted_at');
        if (! empty($filters['project_id'])) {
            $query->where('project_id', $filters['project_id']);
        }
        if (! $request->boolean('archived')) {
            $query->where('is_archived', false);
        }
        $permissions = $this->permissions($request, $scope);
        $page = $this->service->balances($query)->orderBy('sort_order')->orderBy('id')->paginate(40);
        $this->interest->enrich(new Collection($page->items()));

        return response()->json(['data' => collect($page->items())->map(fn (LedgerAccount $account): array => $this->presentAccount($account, $permissions)), 'meta' => $this->pageMeta($page)]);
    }

    public function storeAccount(SaveLedgerAccountRequest $request, Scope $scope): JsonResponse
    {
        $account = $this->service->saveAccount($request, $scope, $request->validated());

        return response()->json(['data' => $this->accountResponse($request, $scope, $account)], 201);
    }

    public function accountStatement(Request $request, Scope $scope, LedgerAccount $account): JsonResponse
    {
        abort_unless($this->access->account($this->context->actor($request), $scope, $account), 404);
        $data = $request->validate(['month' => ['required', 'date_format:Y-m', 'after_or_equal:1900-01', 'before_or_equal:'.now()->format('Y-m')]]);

        return response()->json(['data' => $this->interest->statement($account, $data['month'])]);
    }

    public function updateAccount(SaveLedgerAccountRequest $request, Scope $scope, LedgerAccount $account): JsonResponse
    {
        $account = $this->service->saveAccount($request, $scope, $request->validated(), $account);

        return response()->json(['data' => $this->accountResponse($request, $scope, $account)]);
    }

    public function categories(Request $request, Scope $scope): JsonResponse
    {
        $filters = $request->validate(['project_id' => ['nullable', 'ulid']]);
        $query = $this->access->categories($this->context->actor($request), $scope);
        if (! empty($filters['project_id'])) {
            $query->where('project_id', $filters['project_id']);
        }

        return response()->json(['data' => $query->orderBy('sort_order')->orderBy('id')->get()]);
    }

    public function storeCategory(SaveLedgerCategoryRequest $request, Scope $scope): JsonResponse
    {
        return response()->json(['data' => $this->service->saveCategory($request, $scope, $request->validated())], 201);
    }

    public function updateCategory(SaveLedgerCategoryRequest $request, Scope $scope, LedgerCategory $category): JsonResponse
    {
        return response()->json(['data' => $this->service->saveCategory($request, $scope, $request->validated(), $category)]);
    }

    public function transactions(Request $request, Scope $scope): JsonResponse
    {
        $filters = $request->validate([
            'allocation_project_id' => ['nullable', 'ulid'],
            'project_id' => ['nullable', 'ulid'], 'account_id' => ['nullable', 'ulid'], 'category_id' => ['nullable', 'ulid'],
            'kind' => ['nullable', Rule::in(LedgerTransaction::KINDS)], 'status' => ['nullable', Rule::in(['planned', 'posted', 'void'])],
            'q' => ['nullable', 'string', 'max:120'], 'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', Rule::when($request->filled('from'), 'after_or_equal:from')],
            'page' => ['nullable', 'integer', 'min:1'], 'trash' => ['nullable', 'boolean'],
            'exploitation_entry_id' => ['nullable', 'ulid'], 'cost_type' => ['nullable', Rule::in(LedgerTransaction::COST_TYPES)],
        ]);
        $actor = $this->context->actor($request);
        $accounts = $this->access->accounts($actor, $scope);
        if (! empty($filters['project_id'])) {
            $accounts->where('project_id', $filters['project_id']);
        }
        if (! empty($filters['account_id'])) {
            abort_unless((clone $accounts)->whereKey($filters['account_id'])->exists(), 404);
            $accounts->whereKey($filters['account_id']);
        }
        $ids = (clone $accounts)->select('id');
        $query = LedgerTransaction::where('scope_id', $scope->id)->where(fn (Builder $q) => $q->whereIn('account_id', clone $ids)->orWhereIn('target_account_id', clone $ids));
        if (! empty($filters['allocation_project_id'])) {
            abort_unless($this->access->projects($actor, $scope)->whereKey($filters['allocation_project_id'])->exists(), 404);
            $query->where('allocation_project_id', $filters['allocation_project_id']);
        }
        if (! empty($filters['exploitation_entry_id'])) {
            $entry = $this->service->readableEntry($request, $scope, $filters['exploitation_entry_id']);
            $query->where('exploitation_entry_id', $entry->id);
        }
        if (! empty($filters['cost_type'])) {
            $query->where(fn (Builder $cost) => $cost->where('cost_type', $filters['cost_type'])
                ->when($filters['cost_type'] === 'combined', fn (Builder $legacy) => $legacy->orWhere(fn (Builder $old) => $old->whereNotNull('exploitation_entry_id')->whereNull('cost_type'))));
        }
        if ($request->boolean('trash')) {
            $query->onlyTrashed();
        }
        if (! empty($filters['category_id'])) {
            $categories = $this->access->categories($actor, $scope)->get(['id', 'parent_id']);
            abort_unless($categories->contains('id', $filters['category_id']), 404);
            $selected = [$filters['category_id']];
            do {
                $count = count($selected);
                foreach ($categories as $category) {
                    if (in_array($category->parent_id, $selected, true) && ! in_array($category->id, $selected, true)) {
                        $selected[] = $category->id;
                    }
                }
            } while (count($selected) !== $count);
            $query->whereIn('category_id', $selected);
        }
        foreach (['kind', 'status'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (! empty($filters['q'])) {
            $query->where('title', 'like', '%'.$filters['q'].'%');
        }
        if (! empty($filters['from'])) {
            $query->where('occurred_on', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->where('occurred_on', '<=', $filters['to']);
        }
        $totals = [];
        if (! $request->boolean('trash')) {
            foreach (['income', 'expense', 'sent', 'received', 'adjustment_in', 'adjustment_out'] as $name) {
                $total = (clone $query)->where('status', 'posted')->where('kind', in_array($name, ['sent', 'received'], true) ? 'transfer' : $name)
                    ->whereIn($name === 'received' ? 'target_account_id' : 'account_id', clone $ids)
                    ->selectRaw('currency, SUM(amount_minor) as total_minor')->groupBy('currency')->get();
                foreach ($total as $row) {
                    $totals[$row->currency] ??= ['currency' => $row->currency, 'income' => '0', 'expense' => '0', 'sent' => '0', 'received' => '0'];
                    $totals[$row->currency][$name] = (string) $row->total_minor;
                }
            }
        }
        $costTotals = [];
        if (! empty($filters['exploitation_entry_id']) && ! $request->boolean('trash')) {
            $costTotals = (clone $query)->whereIn('status', ['planned', 'posted'])
                ->selectRaw("currency, COALESCE(cost_type, 'combined') as cost_type, status, SUM(amount_minor) as amount_minor")
                ->groupByRaw("currency, COALESCE(cost_type, 'combined'), status")->get()
                ->map(fn ($row): array => ['currency' => $row->currency, 'cost_type' => $row->cost_type, 'status' => $row->status, 'amount_minor' => (string) $row->amount_minor])->all();
        }
        $permissions = $this->permissions($request, $scope);
        $page = $query->select(['id', 'scope_id', 'account_id', 'target_account_id', 'category_id', 'allocation_project_id', 'exploitation_entry_id', 'cost_type', 'created_by', 'creator_name', 'kind', 'amount_minor', 'currency', 'occurred_on', 'status', 'title', 'is_pinned', 'revision', 'created_at', 'updated_at', 'deleted_at'])
            ->with(self::RELATIONS)->orderByDesc('is_pinned')->orderByDesc('occurred_on')->orderByDesc('id')->paginate(40);

        return response()->json(['data' => collect($page->items())->map(fn (LedgerTransaction $transaction): array => $this->presentTransaction($request, $scope, $transaction, $permissions, false)),
            'meta' => [...$this->pageMeta($page), 'totals' => array_values($totals), 'cost_totals' => $costTotals]]);
    }

    public function show(Request $request, Scope $scope, LedgerTransaction $transaction): JsonResponse
    {
        $transaction->load(self::RELATIONS);
        abort_unless($this->access->transaction($this->context->actor($request), $scope, $transaction), 404);

        return response()->json(['data' => $this->presentTransaction($request, $scope, $transaction, $this->permissions($request, $scope))]);
    }

    public function storeTransaction(SaveLedgerTransactionRequest $request, Scope $scope): JsonResponse
    {
        $transaction = $this->service->saveTransaction($request, $scope, $request->validated())->load(self::RELATIONS);

        return response()->json(['data' => $this->presentTransaction($request, $scope, $transaction, $this->permissions($request, $scope))], 201);
    }

    public function updateTransaction(SaveLedgerTransactionRequest $request, Scope $scope, LedgerTransaction $transaction): JsonResponse
    {
        $transaction = $this->service->saveTransaction($request, $scope, $request->validated(), $transaction)->load(self::RELATIONS);

        return response()->json(['data' => $this->presentTransaction($request, $scope, $transaction, $this->permissions($request, $scope))]);
    }

    public function destroyTransaction(Request $request, Scope $scope, LedgerTransaction $transaction): JsonResponse
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1']]);
        $this->service->deleteTransaction($request, $scope, $transaction, $data['revision']);

        return response()->json(['data' => ['id' => $transaction->id]]);
    }

    public function restoreTransaction(Request $request, Scope $scope, LedgerTransaction $transaction): JsonResponse
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1']]);
        $transaction = $this->service->restoreTransaction($request, $scope, $transaction, $data['revision'])->load(self::RELATIONS);

        return response()->json(['data' => $this->presentTransaction($request, $scope, $transaction, $this->permissions($request, $scope))]);
    }

    public function history(Request $request, Scope $scope, LedgerTransaction $transaction): JsonResponse
    {
        $actor = $this->context->actor($request);
        $transaction->load(['account', 'targetAccount']);
        abort_unless($this->access->transaction($actor, $scope, $transaction), 404);
        abort_unless($this->access->transaction($actor, $scope, $transaction, 'ledger.manage'), 403);
        $request->validate(['page' => ['nullable', 'integer', 'min:1']]);
        $page = ActivityLog::where('scope_id', $scope->id)->where('subject_type', 'ledger_transaction')->where('subject_id', $transaction->id)->latest('id')->paginate(20);

        return response()->json(['data' => $page->items(), 'meta' => $this->pageMeta($page)]);
    }

    public function linkExploitation(StoreExploitationExpenseRequest $request, Scope $scope, string $entryId): JsonResponse
    {
        $transaction = $this->service->linkExploitation($request, $scope, $entryId, $request->validated())->load(self::RELATIONS);

        return response()->json(['data' => $this->presentTransaction($request, $scope, $transaction, $this->permissions($request, $scope))], 201);
    }

    private function permissions(Request $request, Scope $scope): array
    {
        $actor = $this->context->actor($request);
        $result = ['projects' => array_fill_keys($this->access->projects($actor, $scope)->pluck('id')->all(), true)];
        foreach (['view', 'write', 'manage'] as $ability) {
            $result[$ability] = array_fill_keys($this->access->accounts($actor, $scope, 'ledger.'.$ability)->pluck('id')->all(), true);
        }

        return $result;
    }

    private function accountResponse(Request $request, Scope $scope, LedgerAccount $account): array
    {
        $account = $this->service->balances(LedgerAccount::whereKey($account->id))->with('project:id,title,key,deleted_at')->firstOrFail();
        $this->interest->enrich(new Collection([$account]));

        return $this->presentAccount($account, $this->permissions($request, $scope));
    }

    private function presentAccount(LedgerAccount $account, array $permissions): array
    {
        $data = $account->attributesToArray();
        foreach (['income_minor', 'expense_minor', 'sent_minor', 'received_minor', 'adjustment_in_minor', 'adjustment_out_minor'] as $key) {
            $data[$key] = (string) ($account->$key ?? 0);
        }
        $balance = (int) $account->opening_minor + (int) $data['income_minor'] - (int) $data['expense_minor'] - (int) $data['sent_minor'] + (int) $data['received_minor'] + (int) $data['adjustment_in_minor'] - (int) $data['adjustment_out_minor'];

        return [...$data, 'opening_balance' => LedgerMoney::decimal($account->opening_minor), 'balance_minor' => $account->balance_is_known ? (string) $balance : null,
            'project' => $account->project?->only(['id', 'title', 'key', 'deleted_at']), 'opening_locked' => $account->balance_is_known && (($account->operation_count ?? 0) + ($account->received_count ?? 0)) > 0, 'can_write' => isset($permissions['write'][$account->id]) && ! $account->is_archived && $account->balance_is_known, 'can_manage' => isset($permissions['manage'][$account->id])];
    }

    private function presentTransaction(Request $request, Scope $scope, LedgerTransaction $transaction, array $permissions, bool $body = true): array
    {
        $sourceVisible = isset($permissions['view'][$transaction->account_id]);
        $targetVisible = $transaction->target_account_id && isset($permissions['view'][$transaction->target_account_id]);
        $both = fn (string $ability): bool => isset($permissions[$ability][$transaction->account_id]) && (! $transaction->target_account_id || isset($permissions[$ability][$transaction->target_account_id]));
        $side = fn (LedgerAccount $account): array => [...$account->only(['id', 'title', 'project_id', 'currency', 'is_archived']), 'project' => $account->project?->only(['id', 'key', 'title'])];
        $allocation = $transaction->allocationProject;
        $allocationVisible = $allocation && isset($permissions['projects'][$allocation->id]);
        $entry = $transaction->exploitationEntry;
        $entryVisible = $entry && $scope->moduleEnabled('exploiter') && $entry->thing && $this->equip->thing($this->context->actor($request), $scope, $entry->thing);

        return [
            ...$transaction->only(['id', 'scope_id', 'created_by', 'creator_name', 'kind', 'amount_minor', 'currency', 'occurred_on', 'status', 'title', 'is_pinned', 'revision', 'created_at', 'updated_at', 'deleted_at']),
            'occurred_on' => $transaction->occurred_on->toDateString(),
            'amount' => LedgerMoney::decimal($transaction->amount_minor), 'account_id' => $sourceVisible ? $transaction->account_id : null,
            'target_account_id' => $targetVisible ? $transaction->target_account_id : null,
            'account' => $sourceVisible ? $side($transaction->account) : null, 'target_account' => $targetVisible ? $side($transaction->targetAccount) : null,
            'category_id' => $sourceVisible ? $transaction->category_id : null, 'category' => $sourceVisible ? $transaction->category : null,
            'allocation_project_id' => $allocationVisible ? $allocation->id : null,
            'allocation_project' => $allocationVisible ? $allocation->only(['id', 'key', 'title', 'deleted_at']) : null,
            'has_allocation_project' => $transaction->allocation_project_id !== null,
            'can_view_photos' => ! $transaction->trashed() && $both('view'),
            'has_exploitation' => $transaction->exploitation_entry_id !== null,
            'cost_type' => $transaction->exploitation_entry_id ? ($transaction->cost_type ?? 'combined') : null,
            'exploitation' => $entryVisible ? $entry->only(['id', 'thing_id', 'title', 'revision']) : null,
            'can_write' => ! $transaction->trashed() && $both('write') && (! in_array($transaction->kind, LedgerTransaction::ADJUSTMENTS, true) || $both('manage')) && ! $transaction->account->is_archived && ! $transaction->targetAccount?->is_archived && $transaction->account->balance_is_known && ($transaction->targetAccount?->balance_is_known ?? true),
            'can_manage' => $both('manage'), ...($body ? ['note_md' => $transaction->note_md, 'related_things' => $this->relatedThings($request, $scope, $transaction)] : []),
        ];
    }

    private function relatedThings(Request $request, Scope $scope, LedgerTransaction $transaction): array
    {
        if (! $scope->moduleEnabled('equip')) {
            return [];
        }
        $ids = EntityLink::where('scope_id', $scope->id)->where('source_type', 'ledger_transaction')->where('source_id', $transaction->id)->where('target_type', 'thing')->pluck('target_id');
        $query = $this->equip->constrain(Thing::whereIn('id', $ids), $this->context->actor($request), $scope);

        return $query->get(['id', 'name'])->map(fn (Thing $thing): array => ['id' => $thing->id, 'name' => $thing->name])->all();
    }

    private function pageMeta(mixed $page): array
    {
        return ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()];
    }
}
