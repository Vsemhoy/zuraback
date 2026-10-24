<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\ExploitationEntry;
use App\Models\LedgerAccount;
use App\Models\LedgerCategory;
use App\Models\LedgerTransaction;
use App\Models\Project;
use App\Models\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LedgerService
{
    public function __construct(private readonly LedgerAccessService $access, private readonly ContractorContext $context, private readonly EquipAccessService $equip) {}

    public function saveAccount(Request $request, Scope $scope, array $data, ?LedgerAccount $account = null): LedgerAccount
    {
        return DB::transaction(function () use ($request, $scope, $data, $account): LedgerAccount {
            $this->lock($scope);
            $actor = $this->context->actor($request);
            if ($account) {
                $account = LedgerAccount::where('scope_id', $scope->id)->lockForUpdate()->findOrFail($account->id);
                abort_unless($this->access->account($actor, $scope, $account), 404);
                abort_unless($this->access->account($actor, $scope, $account, 'ledger.manage'), 403);
                $this->revision($account->revision, $data['revision']);
            } else {
                $this->project($request, $scope, $data['project_id'], 'ledger.manage');
            }
            $before = $account?->attributesToArray();
            if (array_key_exists('opening_balance', $data)) {
                $data['opening_minor'] = (string) LedgerMoney::parse($data['opening_balance'], true, 'opening_balance');
                if ($account && $data['opening_minor'] !== $account->opening_minor && LedgerTransaction::withTrashed()->where(fn (Builder $q) => $q->where('account_id', $account->id)->orWhere('target_account_id', $account->id))->exists()) {
                    throw ValidationException::withMessages(['opening_balance' => 'После первой операции начальный остаток нельзя менять. Внесите отдельную корректирующую операцию.']);
                }
            }
            unset($data['opening_balance'], $data['revision']);
            $account ??= new LedgerAccount(['scope_id' => $scope->id, 'created_by' => $actor->id, 'creator_name' => $actor->name]);
            $account->fill($data);
            if ($before && ! $account->isDirty()) {
                return $account;
            }
            if ($before) {
                $account->revision++;
            }
            $account->save();
            $this->record($request, $scope, 'ledger_account', $account->id, $before ? 'account_updated' : 'account_created', $before, $account->attributesToArray());

            return $account;
        });
    }

    public function saveCategory(Request $request, Scope $scope, array $data, ?LedgerCategory $category = null): LedgerCategory
    {
        return DB::transaction(function () use ($request, $scope, $data, $category): LedgerCategory {
            $this->lock($scope);
            if ($category) {
                $category = LedgerCategory::where('scope_id', $scope->id)->lockForUpdate()->findOrFail($category->id);
                $this->project($request, $scope, $category->project_id, 'ledger.manage');
                $this->revision($category->revision, $data['revision']);
                if (isset($data['kind']) && $data['kind'] !== $category->kind && LedgerTransaction::withTrashed()->where('category_id', $category->id)->exists()) {
                    throw ValidationException::withMessages(['kind' => 'Тип использованной категории нельзя менять. Создайте новую категорию.']);
                }
            } else {
                $this->project($request, $scope, $data['project_id'], 'ledger.manage');
            }
            $before = $category?->attributesToArray();
            unset($data['revision']);
            $category ??= new LedgerCategory(['scope_id' => $scope->id]);
            $category->fill($data);
            if ($before && ! $category->isDirty()) {
                return $category;
            }
            if ($before) {
                $category->revision++;
            }
            $category->save();
            $this->record($request, $scope, 'ledger_category', $category->id, $before ? 'category_updated' : 'category_created', $before, $category->attributesToArray());

            return $category;
        });
    }

    public function saveTransaction(Request $request, Scope $scope, array $data, ?LedgerTransaction $transaction = null, ?ExploitationEntry $entry = null): LedgerTransaction
    {
        return DB::transaction(function () use ($request, $scope, $data, $transaction, $entry): LedgerTransaction {
            $this->lock($scope);
            $actor = $this->context->actor($request);
            if ($transaction) {
                $transaction = LedgerTransaction::where('scope_id', $scope->id)->lockForUpdate()->findOrFail($transaction->id);
                $transaction->load(['account', 'targetAccount']);
                abort_unless($this->access->transaction($actor, $scope, $transaction), 404);
                abort_unless($this->access->transaction($actor, $scope, $transaction, 'ledger.write'), 403);
                $this->revision($transaction->revision, $data['revision']);
            }
            $account = $this->writableAccount($request, $scope, $transaction?->account_id ?? $data['account_id']);
            $targetId = $transaction?->target_account_id ?? $data['target_account_id'] ?? null;
            $kind = $transaction?->kind ?? $data['kind'];
            if ($kind === 'transfer') {
                $target = $this->writableAccount($request, $scope, $targetId ?? '');
                if ($target->id === $account->id || $target->currency !== $account->currency) {
                    throw ValidationException::withMessages(['target_account_id' => 'Выберите другой счёт в той же валюте. Обмен валют не поддерживается.']);
                }
            } elseif ($targetId !== null) {
                throw ValidationException::withMessages(['target_account_id' => 'Второй счёт разрешён только для перевода.']);
            }
            $fingerprint = $data;
            if ($entry) {
                $fingerprint['exploitation_entry_id'] = $entry->id;
            }
            ksort($fingerprint);
            $hash = hash('sha256', json_encode($fingerprint, JSON_THROW_ON_ERROR));
            if (! $transaction) {
                $existing = LedgerTransaction::withTrashed()->where('scope_id', $scope->id)->where('idempotency_key', $data['idempotency_key'])->first();
                if ($existing) {
                    abort_unless($existing->created_by === $actor->id && $existing->request_hash === $hash && ! $existing->trashed(), 409, 'Этот ключ запроса уже использован. Получите сохранённую операцию, не создавайте дубль.');

                    return $existing;
                }
            }
            $before = $transaction?->attributesToArray();
            if (array_key_exists('amount', $data)) {
                $data['amount_minor'] = (string) LedgerMoney::parse($data['amount']);
                if ((int) $data['amount_minor'] <= 0) {
                    throw ValidationException::withMessages(['amount' => 'Сумма должна быть больше нуля.']);
                }
                if ($transaction?->exploitation_entry_id && $transaction->amount_minor !== $data['amount_minor']) {
                    throw ValidationException::withMessages(['amount' => 'Сумму связанного расхода меняйте в Эксплоитере: материалы и работы.']);
                }
            }
            unset($data['amount'], $data['revision']);
            $transaction ??= new LedgerTransaction(['scope_id' => $scope->id, 'created_by' => $actor->id, 'creator_name' => $actor->name, 'currency' => $account->currency, 'request_hash' => $hash, 'exploitation_entry_id' => $entry?->id]);
            $oldCategory = $transaction->category_id;
            $transaction->fill($data);
            $this->category($scope, $transaction, $account, $oldCategory);
            if ($before && ! $transaction->isDirty()) {
                return $transaction;
            }
            $transaction->updated_by = $actor->id;
            if ($before) {
                $transaction->revision++;
            }
            $transaction->save();
            $this->record($request, $scope, 'ledger_transaction', $transaction->id, $before ? 'transaction_updated' : 'transaction_created', $before, $transaction->attributesToArray());

            return $transaction;
        });
    }

    public function deleteTransaction(Request $request, Scope $scope, LedgerTransaction $transaction, int $revision): void
    {
        DB::transaction(function () use ($request, $scope, $transaction, $revision): void {
            $this->lock($scope);
            $transaction = LedgerTransaction::where('scope_id', $scope->id)->lockForUpdate()->findOrFail($transaction->id);
            $transaction->load(['account', 'targetAccount']);
            $actor = $this->context->actor($request);
            abort_unless($this->access->transaction($actor, $scope, $transaction), 404);
            abort_unless($this->access->transaction($actor, $scope, $transaction, 'ledger.manage'), 403);
            $this->revision($transaction->revision, $revision);
            $before = $transaction->attributesToArray();
            $transaction->revision++;
            $transaction->save();
            $transaction->delete();
            $this->record($request, $scope, 'ledger_transaction', $transaction->id, 'transaction_deleted', $before, $transaction->attributesToArray());
        });
    }

    public function restoreTransaction(Request $request, Scope $scope, LedgerTransaction $transaction, int $revision): LedgerTransaction
    {
        return DB::transaction(function () use ($request, $scope, $transaction, $revision): LedgerTransaction {
            $this->lock($scope);
            $transaction = LedgerTransaction::withTrashed()->where('scope_id', $scope->id)->lockForUpdate()->findOrFail($transaction->id);
            $transaction->load(['account', 'targetAccount']);
            $actor = $this->context->actor($request);
            abort_unless($this->access->transaction($actor, $scope, $transaction), 404);
            abort_unless($this->access->transaction($actor, $scope, $transaction, 'ledger.manage'), 403);
            $this->revision($transaction->revision, $revision);
            if (! $transaction->trashed()) {
                return $transaction;
            }
            $this->writableAccount($request, $scope, $transaction->account_id, 'ledger.manage');
            if ($transaction->target_account_id) {
                $this->writableAccount($request, $scope, $transaction->target_account_id, 'ledger.manage');
            }
            $before = $transaction->attributesToArray();
            if ($transaction->exploitation_entry_id) {
                $entry = $this->editableEntry($request, $scope, $transaction->exploitation_entry_id);
                $this->entryCurrency($entry, $transaction->account);
                $transaction->amount_minor = (string) $this->entryCost($entry);
            }
            $transaction->revision++;
            $transaction->updated_by = $actor->id;
            $transaction->restore();
            $this->record($request, $scope, 'ledger_transaction', $transaction->id, 'transaction_restored', $before, $transaction->attributesToArray());

            return $transaction;
        });
    }

    public function linkExploitation(Request $request, Scope $scope, string $entryId, array $data): LedgerTransaction
    {
        return DB::transaction(function () use ($request, $scope, $entryId, $data): LedgerTransaction {
            $this->lock($scope);
            $entry = $this->editableEntry($request, $scope, $entryId);
            $this->revision($entry->revision, $data['entry_revision']);
            $account = $this->writableAccount($request, $scope, $data['account_id']);
            $this->entryCurrency($entry, $account);
            $existing = LedgerTransaction::withTrashed()->where('exploitation_entry_id', $entry->id)->first();
            if ($existing) {
                abort_unless(! $existing->trashed() && $existing->idempotency_key === $data['idempotency_key'] && $existing->created_by === $this->context->actor($request)->id && $existing->account_id === $account->id, 409, 'У записи уже есть финансовая операция (в том числе в корзине). Откройте её вместо создания дубля.');
            }
            unset($data['entry_revision']);

            return $this->saveTransaction($request, $scope, [...$data, 'kind' => 'expense', 'title' => $entry->title,
                'amount' => LedgerMoney::decimal($this->entryCost($entry))], null, $entry);
        });
    }

    public function syncExploitation(Request $request, Scope $scope, ExploitationEntry $entry, ?array $before, ?int $ledgerRevision): void
    {
        if (! $before || ($before['part_cost'] === $entry->part_cost && $before['labor_cost'] === $entry->labor_cost && $before['currency'] === $entry->currency)) {
            return;
        }
        $transaction = LedgerTransaction::where('exploitation_entry_id', $entry->id)->lockForUpdate()->first();
        if (! $transaction) {
            return;
        }
        $actor = $this->context->actor($request);
        $transaction->load(['account', 'targetAccount']);
        abort_unless($this->access->transaction($actor, $scope, $transaction, 'ledger.write'), 403, 'Для изменения затрат требуется право записи Ledger в проекте связанного счёта.');
        $this->revision($transaction->revision, $ledgerRevision ?? 0);
        if ($transaction->account->is_archived) {
            throw ValidationException::withMessages(['ledger' => 'Сначала верните связанный счёт из архива.']);
        }
        $this->entryCurrency($entry, $transaction->account);
        $snapshot = $transaction->attributesToArray();
        $transaction->amount_minor = (string) $this->entryCost($entry);
        if (! $transaction->isDirty()) {
            return;
        }
        $transaction->revision++;
        $transaction->updated_by = $actor->id;
        $transaction->save();
        $this->record($request, $scope, 'ledger_transaction', $transaction->id, 'exploitation_cost_updated', $snapshot, $transaction->attributesToArray());
    }

    public function balances(Builder $query): Builder
    {
        return $query->withSum(['transactions as income_minor' => fn (Builder $q) => $q->where('kind', 'income')->where('status', 'posted')], 'amount_minor')
            ->withSum(['transactions as expense_minor' => fn (Builder $q) => $q->where('kind', 'expense')->where('status', 'posted')], 'amount_minor')
            ->withSum(['transactions as sent_minor' => fn (Builder $q) => $q->where('kind', 'transfer')->where('status', 'posted')], 'amount_minor')
            ->withSum(['receivedTransfers as received_minor' => fn (Builder $q) => $q->where('status', 'posted')], 'amount_minor')
            ->withCount(['transactions as operation_count' => fn (Builder $q) => $q->withTrashed(), 'receivedTransfers as received_count' => fn (Builder $q) => $q->withTrashed()]);
    }

    public function entryLink(Request $request, Scope $scope, ExploitationEntry $entry): ?array
    {
        $transaction = LedgerTransaction::withTrashed()->where('exploitation_entry_id', $entry->id)->with('account.project')->first();
        if (! $transaction) {
            return null;
        }
        $actor = $this->context->actor($request);
        if (! $this->access->account($actor, $scope, $transaction->account)) {
            return ['linked' => true, 'can_view' => false, 'can_write' => false];
        }

        return ['linked' => true, 'can_view' => true, 'can_write' => ! $transaction->trashed() && ! $transaction->account->is_archived && $this->access->account($actor, $scope, $transaction->account, 'ledger.write'),
            'id' => $transaction->id, 'revision' => $transaction->revision, 'account_title' => $transaction->account->title,
            'project_title' => $transaction->account->project?->title, 'status' => $transaction->status, 'deleted_at' => $transaction->deleted_at,
            'amount' => LedgerMoney::decimal($transaction->amount_minor), 'currency' => $transaction->currency];
    }

    private function editableEntry(Request $request, Scope $scope, string $id): ExploitationEntry
    {
        abort_unless($scope->moduleEnabled('exploiter'), 403, 'Модуль Эксплоитер отключён.');
        $entry = ExploitationEntry::where('scope_id', $scope->id)->lockForUpdate()->findOrFail($id);
        $actor = $this->context->actor($request);
        abort_unless($entry->thing && $this->equip->thing($actor, $scope, $entry->thing), 404);
        abort_unless($this->equip->thing($actor, $scope, $entry->thing, 'equip.update'), 403);

        return $entry;
    }

    private function entryCost(ExploitationEntry $entry): int
    {
        $amount = LedgerMoney::parse($entry->part_cost) + LedgerMoney::parse($entry->labor_cost);
        if ($amount <= 0 || $amount > 999999999999) {
            throw ValidationException::withMessages(['amount' => 'Сумма материалов и работ должна быть положительной и не превышать 9 999 999 999.99.']);
        }

        return $amount;
    }

    private function entryCurrency(ExploitationEntry $entry, LedgerAccount $account): void
    {
        if ($entry->currency !== $account->currency) {
            throw ValidationException::withMessages(['currency' => 'Валюта затрат должна совпадать с валютой счёта.']);
        }
    }

    private function category(Scope $scope, LedgerTransaction $transaction, LedgerAccount $account, ?string $oldCategory): void
    {
        if (! $transaction->category_id) {
            return;
        }
        $category = LedgerCategory::where('scope_id', $scope->id)->where('project_id', $account->project_id)->find($transaction->category_id);
        if (! $category || $transaction->kind === 'transfer' || ! in_array($category->kind, ['both', $transaction->kind], true) || ($category->is_archived && $oldCategory !== $category->id)) {
            throw ValidationException::withMessages(['category_id' => 'Выберите действующую категорию этого проекта, подходящую типу операции.']);
        }
    }

    private function writableAccount(Request $request, Scope $scope, string $id, string $ability = 'ledger.write'): LedgerAccount
    {
        $account = LedgerAccount::where('scope_id', $scope->id)->lockForUpdate()->findOrFail($id);
        $actor = $this->context->actor($request);
        abort_unless($this->access->account($actor, $scope, $account), 404);
        abort_unless($this->access->account($actor, $scope, $account, $ability), 403);
        if ($account->is_archived) {
            throw ValidationException::withMessages(['account_id' => 'Счёт архивный. Сначала верните его в работу.']);
        }

        return $account;
    }

    private function project(Request $request, Scope $scope, string $id, string $ability): Project
    {
        $actor = $this->context->actor($request);
        $project = $this->access->projects($actor, $scope)->whereKey($id)->firstOrFail();
        abort_unless($this->access->projects($actor, $scope, $ability)->whereKey($id)->exists(), 403);

        return $project;
    }

    private function lock(Scope $scope): void
    {
        Scope::whereKey($scope->id)->lockForUpdate()->firstOrFail();
    }

    private function revision(int $actual, int $expected): void
    {
        abort_unless($actual === $expected, 409, 'Запись уже изменена. Обновите данные и согласуйте изменения.');
    }

    private function record(Request $request, Scope $scope, string $type, string $id, string $action, ?array $before, array $after): void
    {
        foreach (['idempotency_key', 'request_hash'] as $field) {
            unset($before[$field], $after[$field]);
        }
        ActivityLog::create(['scope_id' => $scope->id, 'actor_id' => $this->context->actor($request)->id, 'subject_type' => $type, 'subject_id' => $id,
            'action' => 'ledger.'.$action, 'before' => $before, 'after' => $after, 'context' => $this->context->auditMetadata($request)]);
    }
}
