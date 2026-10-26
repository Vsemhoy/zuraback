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
    public function __construct(private readonly LedgerAccessService $access, private readonly ContractorContext $context, private readonly EquipAccessService $equip, private readonly LedgerInterestService $interest) {}

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
            if ($account && ! $account->balance_is_known && ($data['balance_is_known'] ?? false) && ! array_key_exists('opening_balance', $data)) {
                throw ValidationException::withMessages(['opening_balance' => 'Для подтверждения неизвестного остатка укажите начальную сумму явно.']);
            }
            if ($account?->balance_is_known && array_key_exists('balance_is_known', $data) && ! $data['balance_is_known']) {
                throw ValidationException::withMessages(['balance_is_known' => 'Подтверждённый остаток нельзя сделать неизвестным. Используйте корректирующую операцию.']);
            }
            $this->interestTerms($data, $account);
            $before = $account?->attributesToArray();
            $credit = ($data['type'] ?? $account?->type) === 'credit';
            if ($credit && (! $account || array_key_exists('type', $data) || array_key_exists('opening_balance', $data))) {
                $opening = array_key_exists('opening_balance', $data)
                    ? LedgerMoney::parse($data['opening_balance'], true, 'opening_balance')
                    : (int) ($account?->opening_minor ?? 0);
                if ($opening !== 0) {
                    throw ValidationException::withMessages(['opening_balance' => 'Кредитный счёт открывается с нулевым остатком. Долг оформляется проведённой операцией.']);
                }
            }
            if (array_key_exists('opening_balance', $data)) {
                $data['opening_minor'] = (string) LedgerMoney::parse($data['opening_balance'], true, 'opening_balance');
                $correctCreditOpening = $credit && $account?->type === 'credit' && $data['opening_minor'] === '0';
                if (! $correctCreditOpening && $account && $account->balance_is_known && $data['opening_minor'] !== $account->opening_minor && LedgerTransaction::withTrashed()->where(fn (Builder $q) => $q->where('account_id', $account->id)->orWhere('target_account_id', $account->id))->exists()) {
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
            $this->interest->assertSafe($account);
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
            if (in_array($kind, LedgerTransaction::ADJUSTMENTS, true)) {
                abort_unless($this->access->account($actor, $scope, $account, 'ledger.manage'), 403);
                if (trim((string) (array_key_exists('note_md', $data) ? $data['note_md'] : $transaction?->note_md)) === '') {
                    throw ValidationException::withMessages(['note_md' => 'Укажите причину корректировки. Она сохранится в истории.']);
                }
                if ($entry) {
                    throw ValidationException::withMessages(['kind' => 'Корректировка остатка не является расходом эксплуатации.']);
                }
            }
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
            }
            if (array_key_exists('cost_type', $data) && ! ($transaction?->exploitation_entry_id || $entry)) {
                throw ValidationException::withMessages(['cost_type' => 'Тип затрат задаётся только для расхода эксплуатации.']);
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
            $this->interest->assertSafe($account, ...(isset($target) ? [$target] : []));
            $this->record($request, $scope, 'ledger_transaction', $transaction->id, $before ? 'transaction_updated' : 'transaction_created', $before, $transaction->attributesToArray());

            return $transaction;
        });
    }

    public function moveTransaction(Request $request, Scope $scope, LedgerTransaction $transaction, array $data): LedgerTransaction
    {
        return DB::transaction(function () use ($request, $scope, $transaction, $data): LedgerTransaction {
            $this->lock($scope);
            $transaction = LedgerTransaction::where('scope_id', $scope->id)->with(['account', 'targetAccount'])->lockForUpdate()->findOrFail($transaction->id);
            $actor = $this->context->actor($request);
            abort_unless($this->access->transaction($actor, $scope, $transaction), 404);
            $ability = in_array($transaction->kind, LedgerTransaction::ADJUSTMENTS, true) ? 'ledger.manage' : 'ledger.write';
            abort_unless($this->access->transaction($actor, $scope, $transaction, $ability), 403);
            $this->revision($transaction->revision, $data['revision']);
            $oldAccounts = [$this->writableAccount($request, $scope, $transaction->account_id, $ability)];
            if ($transaction->target_account_id) {
                $oldAccounts[] = $this->writableAccount($request, $scope, $transaction->target_account_id, $ability);
            }
            if ($data['side'] === 'target' && $transaction->kind !== 'transfer') {
                throw ValidationException::withMessages(['side' => 'Вторая сторона есть только у перевода.']);
            }
            $field = $data['side'] === 'target' ? 'target_account_id' : 'account_id';
            $oldAccount = $data['side'] === 'target' ? $oldAccounts[1] : $oldAccounts[0];
            $destination = $this->writableAccount($request, $scope, $data['account_id'], $ability);
            if ($destination->currency !== $transaction->currency || $destination->project_id !== $oldAccount->project_id) {
                throw ValidationException::withMessages(['account_id' => 'Перенос возможен между счетами одного проекта и одной валюты.']);
            }
            $otherId = $data['side'] === 'target' ? $transaction->account_id : $transaction->target_account_id;
            if ($destination->id === $otherId) {
                throw ValidationException::withMessages(['account_id' => 'Счета перевода должны различаться.']);
            }
            $before = $transaction->attributesToArray();
            $transaction->fill([$field => $destination->id, 'occurred_on' => $data['occurred_on']]);
            if (! $transaction->isDirty()) {
                return $transaction;
            }
            $transaction->updated_by = $actor->id;
            $transaction->revision++;
            $transaction->save();
            $this->interest->assertSafe(...[...$oldAccounts, $destination]);
            $this->record($request, $scope, 'ledger_transaction', $transaction->id, 'transaction_moved', $before, $transaction->attributesToArray());

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
            $this->interest->assertSafe($transaction->account, ...($transaction->targetAccount ? [$transaction->targetAccount] : []));
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
            $transaction->revision++;
            $transaction->updated_by = $actor->id;
            $transaction->restore();
            $this->interest->assertSafe($transaction->account, ...($transaction->targetAccount ? [$transaction->targetAccount] : []));
            $this->record($request, $scope, 'ledger_transaction', $transaction->id, 'transaction_restored', $before, $transaction->attributesToArray());

            return $transaction;
        });
    }

    public function linkExploitation(Request $request, Scope $scope, string $entryId, array $data): LedgerTransaction
    {
        return DB::transaction(function () use ($request, $scope, $entryId, $data): LedgerTransaction {
            $this->lock($scope);
            $entry = $this->editableEntry($request, $scope, $entryId);
            $existing = LedgerTransaction::withTrashed()->where('scope_id', $scope->id)->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                abort_unless($existing->exploitation_entry_id === $entry->id, 409, 'Ключ запроса уже использован другой операцией.');
            } else {
                $this->revision($entry->revision, $data['entry_revision']);
            }
            unset($data['entry_revision']);

            return $this->saveTransaction($request, $scope, [...$data, 'kind' => 'expense',
                'title' => $data['title'] ?? $existing?->title ?? $entry->title], null, $entry);
        });
    }

    public function balances(Builder $query): Builder
    {
        return $query->withSum(['transactions as income_minor' => fn (Builder $q) => $q->where('kind', 'income')->where('status', 'posted')], 'amount_minor')
            ->withSum(['transactions as adjustment_in_minor' => fn (Builder $q) => $q->where('kind', 'adjustment_in')->where('status', 'posted')], 'amount_minor')
            ->withSum(['transactions as adjustment_out_minor' => fn (Builder $q) => $q->where('kind', 'adjustment_out')->where('status', 'posted')], 'amount_minor')
            ->withSum(['transactions as expense_minor' => fn (Builder $q) => $q->where('kind', 'expense')->where('status', 'posted')], 'amount_minor')
            ->withSum(['transactions as sent_minor' => fn (Builder $q) => $q->where('kind', 'transfer')->where('status', 'posted')], 'amount_minor')
            ->withSum(['receivedTransfers as received_minor' => fn (Builder $q) => $q->where('status', 'posted')], 'amount_minor')
            ->withCount(['transactions as operation_count' => fn (Builder $q) => $q->withTrashed(), 'receivedTransfers as received_count' => fn (Builder $q) => $q->withTrashed()]);
    }

    private function interestTerms(array $data, ?LedgerAccount $account): void
    {
        $fields = ['type', 'interest_rate_bps', 'interest_start_on', 'interest_end_on'];
        $terms = array_replace($account?->attributesToArray() ?? ['type' => 'bank', 'interest_rate_bps' => null, 'interest_start_on' => null, 'interest_end_on' => null], array_intersect_key($data, array_flip($fields)));
        if ($terms['type'] === 'credit') {
            if (($terms['interest_rate_bps'] ?? null) === null || ($terms['interest_start_on'] ?? null) === null) {
                throw ValidationException::withMessages(['interest_rate_bps' => 'Для кредитного счёта задайте годовую ставку и дату начала начисления.']);
            }
            if (($terms['interest_end_on'] ?? null) !== null && $terms['interest_end_on'] < $terms['interest_start_on']) {
                throw ValidationException::withMessages(['interest_end_on' => 'Конец начисления не может быть раньше начала.']);
            }
        } elseif (($terms['interest_rate_bps'] ?? null) !== null || ($terms['interest_start_on'] ?? null) !== null || ($terms['interest_end_on'] ?? null) !== null) {
            throw ValidationException::withMessages(['interest_rate_bps' => 'Проценты доступны только кредитному счёту.']);
        }
        if (! $account || ! LedgerTransaction::withTrashed()->where(fn (Builder $query) => $query->where('account_id', $account->id)->orWhere('target_account_id', $account->id))->exists()) {
            return;
        }
        foreach ($fields as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }
            $old = $account->getRawOriginal($field);
            $changed = $field === 'interest_rate_bps' ? ($data[$field] === null ? null : (int) $data[$field]) !== ($old === null ? null : (int) $old) : $data[$field] !== $old;
            if ($changed && ($account->type === 'credit' || $terms['type'] === 'credit')) {
                throw ValidationException::withMessages([$field => 'После первой операции кредитные условия фиксируются, чтобы не переписать историю начислений.']);
            }
        }
    }

    public function entrySummary(ExploitationEntry $entry): array
    {
        return ['linked' => $entry->ledgerTransactions()->withTrashed()->exists()];
    }

    public function readableEntry(Request $request, Scope $scope, string $id): ExploitationEntry
    {
        abort_unless($scope->moduleEnabled('exploiter'), 403, 'Модуль Эксплоитер отключён.');
        $entry = ExploitationEntry::where('scope_id', $scope->id)->with('thing')->findOrFail($id);
        abort_unless($entry->thing && $this->equip->thing($this->context->actor($request), $scope, $entry->thing), 404);

        return $entry;
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
        if (! $account->balance_is_known) {
            throw ValidationException::withMessages(['account_id' => 'Сначала подтвердите начальный остаток неизвестного счёта в его настройках.']);
        }
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
