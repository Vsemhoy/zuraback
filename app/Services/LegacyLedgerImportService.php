<?php

namespace App\Services;

use App\Models\EntityLink;
use App\Models\ExploitationEntry;
use App\Models\LedgerAccount;
use App\Models\LedgerCategory;
use App\Models\LedgerTransaction;
use App\Models\LegacyImportRecord;
use App\Models\Project;
use App\Models\Scope;
use App\Models\Thing;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\Uid\Ulid;

class LegacyLedgerImportService
{
    private const SOURCE = 'home.zuratax.com';

    public function __construct(private readonly LedgerInterestService $interest) {}

    public function import(Scope $scope, array $snapshot, bool $commit = false): array
    {
        $rows = $this->validate($snapshot);

        return DB::transaction(function () use ($scope, $snapshot, $rows, $commit): array {
            $scope = Scope::whereKey($scope->id)->lockForUpdate()->firstOrFail();
            $owner = User::whereKey($scope->owner_id)->lockForUpdate()->firstOrFail();
            if (! $scope->is_private || ! $scope->is_active || ! $owner->is_active || $owner->status !== 'active' || ! $scope->moduleEnabled('ledger')
                || ! hash_equals($snapshot['owner_hash'], hash('sha256', Str::lower(trim((string) $owner->email))))) {
                throw new LogicException('An active private Ledger scope with the matching owner is required.');
            }
            $project = $this->op($scope, 'ledger_project', Str::lower($snapshot['owner_id']), Project::class, [
                'title' => 'Личные финансы', 'key' => 'FIN', 'visibility' => 'private', 'include_in_reports' => false,
                'show_in_tasker' => false, 'show_in_eventor' => false, 'status' => 'active', 'color' => '#7c6a46',
                'description' => 'Финансы из home.zuratax.com. Перенесены исходные операции; старые месячные итоги не используются. Начальные остатки требуют ручной сверки.',
            ], ['policy' => 'operations-only-v1', 'owner_id' => $snapshot['owner_id']]);
            $existingProject = Project::withTrashed()->where('scope_id', $scope->id)->whereRaw('UPPER(`key`) = ?', ['FIN'])->first();
            if ($existingProject && ($existingProject->id !== $project['target_id'] || $existingProject->trashed() || $existingProject->visibility !== 'private'
                || $existingProject->created_by !== $owner->id || $existingProject->include_in_reports)) {
                throw new LogicException('FIN is occupied or no longer private/report-excluded; reconcile it explicitly.');
            }
            $ops = [$project];
            $maps = ['project' => $project['target_id'], 'accounts' => [], 'categories' => [], 'transactions' => []];
            $warnings = ['ignored_month_totals' => count($snapshot['month_totals']), 'stale_transfer_references' => 0, 'orphan_accounts' => 0,
                'unpaired_deleted_transfers' => 0, 'unmapped_deleted_exploitations' => 0];
            $accounts = $rows['accounts'];
            $currencies = array_values(array_unique(array_column($accounts, 'currency')));
            foreach ($rows['transactions'] as $row) {
                $id = Str::lower($row['account_id']);
                if (! isset($accounts[$id])) {
                    if (count($currencies) !== 1) {
                        throw new LogicException('An orphan account currency cannot be inferred safely.');
                    }
                    $accounts[$id] = ['id' => $id, 'name' => 'Неустановленный счёт', 'type' => 'phantom', 'currency' => $currencies[0], 'opening_balance' => 0,
                        'is_archived' => true, 'sort_order' => PHP_INT_MAX, 'created_at' => $row['created_at'], 'updated_at' => $row['updated_at'], '_orphan' => true];
                    $warnings['orphan_accounts']++;
                }
            }
            uasort($accounts, fn ($a, $b): int => (int) $a['sort_order'] <=> (int) $b['sort_order'] ?: strcmp($a['id'], $b['id']));
            $rank = 0;
            foreach ($accounts as $id => $row) {
                $orphan = $row['_orphan'] ?? false;
                $notes = $orphan ? 'Исходный счёт отсутствует. Начальный остаток неизвестен, счёт исключён из итогов. Валюта взята по остальным счетам источника и требует сверки.'
                    : 'Импорт исходных операций. Проверьте начальный остаток по своей выписке; дальнейшие расхождения оформляйте отдельными корректировками.';
                if (! empty($row['literals'])) {
                    $notes .= "\nКод в источнике: ".$row['literals'];
                }
                $notes .= "\nТип в источнике: ".$row['type'];
                $payload = ['project_id' => $project['target_id'], 'creator_name' => $owner->name, 'title' => $row['name'],
                    'type' => ['card' => 'bank', 'cash' => 'cash', 'credit' => 'credit', 'phantom' => 'wallet'][$row['type']],
                    'currency' => $row['currency'], 'opening_minor' => (string) $row['opening_balance'], 'balance_is_known' => ! $orphan,
                    'description' => $notes, 'is_archived' => (bool) $row['is_archived'], 'color' => $this->color($row['color'] ?? null), 'sort_order' => $rank++,
                    ...Arr::only($row, ['created_at', 'updated_at'])];
                if ($row['type'] === 'credit') {
                    if (! isset($row['interest_rate'], $row['interest_start'])) {
                        throw new LogicException('A credit account requires explicit rate and start date.');
                    }
                    $payload += ['interest_rate_bps' => (int) $row['interest_rate'], 'interest_start_on' => $row['interest_start']];
                }
                $op = $this->op($scope, $orphan ? 'ledger_orphan_account' : 'ledger_account', $id, LedgerAccount::class, $payload, $row);
                if (! $op['registered'] && LedgerAccount::where('scope_id', $scope->id)->where('project_id', $project['target_id'])->where('title', $payload['title'])->exists()) {
                    throw new LogicException('An unregistered account match requires reconciliation.');
                }
                $maps['accounts'][$id] = $op['target_id'];
                $ops[] = $op;
            }
            $rank = 0;
            foreach ($rows['categories'] as $id => $row) {
                $names = [$row['name']];
                $parent = $row['parent_id'] ?? null;
                $seen = [$id => true];
                while ($parent) {
                    $parent = Str::lower($parent);
                    if (isset($seen[$parent]) || ! isset($rows['categories'][$parent])) {
                        throw new LogicException('Invalid legacy category hierarchy.');
                    }
                    $seen[$parent] = true;
                    array_unshift($names, $rows['categories'][$parent]['name']);
                    $parent = $rows['categories'][$parent]['parent_id'] ?? null;
                }
                $title = implode(' / ', $names);
                if (mb_strlen($title) > 120) {
                    throw new LogicException('A category path exceeds the native title limit.');
                }
                $op = $this->op($scope, 'ledger_category', $id, LedgerCategory::class, ['project_id' => $project['target_id'], 'title' => $title,
                    'kind' => 'both', 'sort_order' => $rank++, 'is_archived' => (bool) $row['is_archived'], ...Arr::only($row, ['created_at', 'updated_at'])], $row);
                $maps['categories'][$id] = $op['target_id'];
                $ops[] = $op;
            }
            $pairs = [];
            foreach ($rows['transactions'] as $id => $row) {
                if ($row['flow_kind'] !== 'transfer_in') {
                    continue;
                }
                $original = Str::lower($row['original_transaction_id'] ?? '');
                $out = $rows['transactions'][$original] ?? null;
                if (! $out || $out['flow_kind'] !== 'transfer_out' || isset($pairs[$original]) || $out['amount'] != $row['amount']
                    || $out['occurred_at'] !== $row['occurred_at'] || $out['status'] !== $row['status'] || (bool) $out['is_disabled'] !== (bool) $row['is_disabled']
                    || ($out['deleted_at'] === null) !== ($row['deleted_at'] === null) || Str::lower($out['account_id']) === Str::lower($row['account_id'])
                    || $accounts[Str::lower($out['account_id'])]['currency'] !== $accounts[Str::lower($row['account_id'])]['currency']) {
                    throw new LogicException('An invalid or unpaired transfer needs reconciliation.');
                }
                $pairs[$original] = $row;
            }
            foreach ($rows['transactions'] as $id => $row) {
                if ($row['flow_kind'] === 'transfer_in') {
                    continue;
                }
                $pair = $pairs[$id] ?? null;
                $kind = match ($row['flow_kind']) {
                    'income' => 'income', 'expense' => 'expense', 'adjustment' => 'adjustment_in',
                    'reconciliation' => $row['is_negative'] ? 'adjustment_out' : 'adjustment_in',
                    'transfer_out' => $pair ? 'transfer' : 'adjustment_out',
                };
                $note = $row['note'] ?? '';
                if ($row['flow_kind'] === 'transfer_out' && ! $pair) {
                    if ($row['deleted_at'] === null) {
                        throw new LogicException('An active unpaired transfer cannot be imported.');
                    }
                    $warnings['unpaired_deleted_transfers']++;
                    $note .= "\n\nИсторическая удалённая исходящая проводка без пары. Сохранена как удалённая корректировка минус; второй счёт не придуман.";
                }
                if ($pair && (Str::lower($row['target_account_id'] ?? '') !== Str::lower($pair['account_id']) || Str::lower($pair['target_account_id'] ?? '') !== Str::lower($row['account_id']))) {
                    $warnings['stale_transfer_references']++;
                }
                if ($pair && filled($pair['note'] ?? null) && $pair['note'] !== $note) {
                    $note .= "\n\nЗаметка встречной проводки:\n".$pair['note'];
                }
                if (in_array($kind, LedgerTransaction::ADJUSTMENTS, true)) {
                    $note .= "\n\nКорректировка из прежней системы: ".$row['flow_kind'].'. Сумма сохранена в копейках. Это не доход и не эксплуатационный расход.';
                }
                $entryId = $row['exploiter_event_id'] ?? (($row['linked_entity_type'] ?? '') === 'exploiter.event' ? $row['linked_entity_id'] : null);
                $entry = null;
                if ($entryId) {
                    $entry = $this->external($scope, 'equip_exploitation', $entryId, ExploitationEntry::class, 'exploitation_entry', $row['deleted_at'] !== null);
                    if (! $entry) {
                        $warnings['unmapped_deleted_exploitations']++;
                        $note .= "\n\nСвязь с удалённой эксплуатацией источника сохранена в журнале импорта: ".$entryId;
                    } elseif ($kind !== 'expense') {
                        throw new LogicException('An exploitation payment must remain an expense.');
                    }
                }
                $category = empty($row['category_id']) ? null : ($maps['categories'][Str::lower($row['category_id'])] ?? throw new LogicException('Missing transaction category.'));
                $payload = ['account_id' => $maps['accounts'][Str::lower($row['account_id'])], 'target_account_id' => $pair ? $maps['accounts'][Str::lower($pair['account_id'])] : null,
                    'category_id' => $kind === 'transfer' ? null : $category, 'creator_name' => $owner->name, 'updated_by' => $owner->id,
                    'kind' => $kind, 'amount_minor' => (string) $row['amount'], 'currency' => $accounts[Str::lower($row['account_id'])]['currency'],
                    'occurred_on' => $row['occurred_at'], 'status' => $row['is_disabled'] ? 'void' : ($row['status'] === 'pending' ? 'planned' : 'posted'),
                    'title' => trim($row['title'] ?? '') ?: 'Операция из прежней системы', 'note_md' => trim($note) ?: null, 'is_pinned' => (bool) $row['is_pinned'],
                    'exploitation_entry_id' => $entry?->id, 'cost_type' => $entry ? (['part' => 'parts', 'consumption' => 'consumables'][$row['cost_type'] ?? ''] ?? $row['cost_type'] ?? 'combined') : null,
                    'idempotency_key' => (string) Str::uuid5('9f98d94d-85f1-481c-aeba-3b898008f8f8', self::SOURCE.'|'.$scope->id.'|'.$id),
                    'request_hash' => hash('sha256', self::SOURCE.'|'.$id), ...Arr::only($row, ['created_at', 'updated_at', 'deleted_at'])];
                $op = $this->op($scope, $pair ? 'ledger_transfer' : 'ledger_transaction', $id, LedgerTransaction::class, $payload, ['row' => $row, 'pair' => $pair, 'policy' => 'operations-only-v1']);
                $ops[] = $op;
                $maps['transactions'][$id] = $op['target_id'];
                if ($pair) {
                    $maps['transactions'][Str::lower($pair['id'])] = $op['target_id'];
                }
                $linked = [];
                foreach (array_filter([$row, $pair]) as $side) {
                    if (empty($side['linked_entity_type'])) {
                        continue;
                    }
                    if (! in_array($side['linked_entity_type'], ['stuffer.thing', 'exploiter.event'], true)) {
                        throw new LogicException('An unsupported linked entity requires a reviewed mapping.');
                    }
                    if ($side['linked_entity_type'] !== 'stuffer.thing') {
                        continue;
                    }
                    $thing = $this->external($scope, 'equip_thing', $side['linked_entity_id'], Thing::class, 'thing');
                    if (isset($linked[$thing->id])) {
                        continue;
                    }
                    $linked[$thing->id] = true;
                    $linkId = substr(hash('sha256', $id.'|'.$thing->id), 0, 26);
                    $ops[] = $this->op($scope, 'ledger_thing_link', $linkId, EntityLink::class, [
                        'source_type' => 'ledger_transaction', 'source_id' => $op['target_id'], 'target_type' => 'thing', 'target_id' => $thing->id,
                        'relation' => 'related', ...Arr::only($row, ['created_at', 'updated_at'])], ['transaction' => $id, 'thing' => $side['linked_entity_id']]);
                }
            }
            $counts = ['create' => [], 'skip' => []];
            foreach ($ops as $op) {
                $action = $op['registered'] ? 'skip' : 'create';
                $counts[$action][$op['kind']] = ($counts[$action][$op['kind']] ?? 0) + 1;
            }
            if ($commit) {
                $batch = Str::lower((string) Str::ulid());
                foreach ($ops as $op) {
                    if ($op['registered']) {
                        continue;
                    }
                    $class = $op['class'];
                    $model = new $class;
                    $payload = [...$op['payload'], 'scope_id' => $scope->id, 'id' => $op['target_id']];
                    if ($class !== LedgerCategory::class) {
                        $payload['created_by'] = $owner->id;
                    }
                    $model->forceFill($payload)->saveQuietly();
                    LegacyImportRecord::create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'batch_id' => $batch, 'source' => self::SOURCE,
                        'source_type' => $op['kind'], 'source_id' => $op['source_id'], 'target_type' => $op['target_type'], 'target_id' => $op['target_id'],
                        'source_hash' => $op['hash'], 'snapshot' => $op['archive']]);
                }
                $this->interest->assertSafe(...LedgerAccount::whereIn('id', array_values($maps['accounts']))->get()->all());
            }

            return ['dry_run' => ! $commit, 'planned' => array_sum($counts['create']), 'created' => $commit ? array_sum($counts['create']) : 0,
                'counts' => $counts, 'warnings' => $warnings, 'source_transactions' => count($rows['transactions']), 'native_transactions' => count(array_unique($maps['transactions'])), 'maps' => $maps];
        });
    }

    private function op(Scope $scope, string $kind, string $id, string $class, array $payload, array $archive): array
    {
        $id = Str::lower($id);
        $hash = hash('sha256', json_encode($this->canonical($archive), JSON_THROW_ON_ERROR));
        $mapping = LegacyImportRecord::where('source', self::SOURCE)->where('source_type', $kind)->where('source_id', $id)->lockForUpdate()->first();
        $type = match ($class) { Project::class => 'project', LedgerAccount::class => 'ledger_account', LedgerCategory::class => 'ledger_category', LedgerTransaction::class => 'ledger_transaction', EntityLink::class => 'entity_link' };
        $binary = substr(Ulid::fromString(Ulid::isValid($id) ? $id : $scope->id)->toBinary(), 0, 6).substr(hash('sha256', self::SOURCE.'|'.$scope->id.'|'.$kind.'|'.$id, true), 0, 10);
        $targetId = $mapping?->target_id ?? Str::lower(Ulid::fromBinary($binary)->toBase32());
        $query = in_array(SoftDeletes::class, class_uses_recursive($class), true) ? $class::withTrashed() : $class::query();
        $target = $query->find($targetId);
        if ($mapping && ($mapping->scope_id !== $scope->id || $mapping->created_by !== $scope->owner_id || $mapping->target_type !== $type || ! hash_equals($mapping->source_hash, $hash) || ! $target)) {
            throw new LogicException('A Ledger mapping changed, lost its target, or points outside the private workspace.');
        }
        if ($target && (! $mapping || $target->scope_id !== $scope->id || ($class !== LedgerCategory::class && $target->created_by !== $scope->owner_id))) {
            throw new LogicException('A Ledger target ID is occupied or belongs to another owner.');
        }

        return ['kind' => $kind, 'source_id' => $id, 'target_id' => $targetId, 'target_type' => $type, 'class' => $class,
            'payload' => $payload, 'archive' => $archive, 'hash' => $hash, 'registered' => $mapping !== null];
    }

    private function external(Scope $scope, string $kind, string $id, string $class, string $type, bool $allowMissing = false): ?Model
    {
        $mapping = LegacyImportRecord::where('source', self::SOURCE)->where('source_type', $kind)->where('source_id', Str::lower($id))->first();
        if (! $mapping && $allowMissing) {
            return null;
        }
        $query = in_array(SoftDeletes::class, class_uses_recursive($class), true) ? $class::withTrashed() : $class::query();
        $target = $mapping ? $query->find($mapping->target_id) : null;
        if (! $mapping || ! $target || $mapping->scope_id !== $scope->id || $mapping->created_by !== $scope->owner_id || $mapping->target_type !== $type
            || $target->scope_id !== $scope->id || $target->created_by !== $scope->owner_id) {
            throw new LogicException('A linked record must first be imported into this private scope.');
        }

        return $target;
    }

    private function validate(array $snapshot): array
    {
        $rules = ['schema' => ['required', 'in:1'], 'source' => ['required', 'in:'.self::SOURCE], 'owner_id' => ['required', 'ulid'], 'owner_hash' => ['required', 'regex:/^[a-f0-9]{64}$/']];
        foreach (['layers', 'accounts', 'categories', 'transactions', 'month_totals', 'groups', 'contents', 'transaction_tags', 'tags'] as $kind) {
            $rules[$kind] = ['present', 'array', 'max:'.(in_array($kind, ['groups', 'contents', 'transaction_tags', 'tags'], true) ? 0 : 10000)];
        }
        if (Validator::make($snapshot, $rules)->fails() || count($snapshot['layers']) !== 1 || ($snapshot['layers'][0]['type'] ?? '') !== 'base') {
            throw new LogicException('Invalid Ledger source or unsupported layers/groups/content/tags.');
        }
        $rows = [];
        foreach (['layers', 'accounts', 'categories', 'transactions'] as $kind) {
            $rows[$kind] = [];
            foreach ($snapshot[$kind] as $row) {
                $fields = ['id' => ['required', 'ulid'], 'user_id' => ['required', 'ulid'], 'created_at' => ['required', 'date'], 'updated_at' => ['required', 'date']];
                if ($kind === 'accounts') {
                    $fields += ['name' => ['required', 'string', 'max:100'], 'type' => ['required', 'in:cash,card,credit,phantom'], 'currency' => ['required', 'in:'.implode(',', LedgerMoney::CURRENCIES)],
                        'opening_balance' => ['required', 'integer', 'between:-2147483648,2147483647'], 'is_archived' => ['required', 'boolean'], 'sort_order' => ['required', 'integer'],
                        'interest_rate' => ['nullable', 'integer', 'between:0,100000'], 'interest_start' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:2100-12-31']];
                } elseif ($kind === 'categories') {
                    $fields += ['name' => ['required', 'string', 'max:100'], 'parent_id' => ['nullable', 'ulid'], 'is_archived' => ['required', 'boolean']];
                } elseif ($kind === 'transactions') {
                    $fields += ['account_id' => ['required', 'ulid'], 'flow_kind' => ['required', 'in:income,expense,transfer_out,transfer_in,adjustment,reconciliation'],
                        'amount' => ['required', 'integer', 'between:1,2147483647'], 'occurred_at' => ['required', 'date_format:Y-m-d'], 'month_key' => ['required', 'date_format:Y-m'],
                        'is_negative' => ['required', 'boolean'], 'is_disabled' => ['required', 'boolean'], 'is_pinned' => ['required', 'boolean'], 'status' => ['required', 'in:cleared,pending'],
                        'title' => ['nullable', 'string', 'max:255'], 'note' => ['nullable', 'string', 'max:900000'], 'deleted_at' => ['nullable', 'date'],
                        'cost_type' => ['nullable', 'in:part,labor,consumption,service,delivery,other']];
                    foreach (['target_account_id', 'original_transaction_id', 'category_id', 'exploiter_event_id', 'linked_entity_id'] as $field) {
                        $fields[$field] = ['nullable', 'ulid'];
                    }
                }
                if (! is_array($row) || Validator::make($row, $fields)->fails() || Str::lower($row['user_id']) !== Str::lower($snapshot['owner_id'])) {
                    throw new LogicException('Invalid or mixed-owner Ledger rows in '.$kind.'.');
                }
                $id = Str::lower($row['id']);
                if (isset($rows[$kind][$id])) {
                    throw new LogicException('Duplicate source Ledger identities.');
                }
                if (in_array($kind, ['accounts', 'transactions'], true) && Str::lower($row['layer_id'] ?? '') !== Str::lower($snapshot['layers'][0]['id'])) {
                    throw new LogicException('A Ledger row references an excluded layer.');
                }
                if ($kind === 'transactions' && substr($row['occurred_at'], 0, 7) !== $row['month_key']) {
                    throw new LogicException('A stale transaction month key requires reconciliation.');
                }
                $rows[$kind][$id] = $row;
            }
        }

        return $rows;
    }

    private function color(?string $value): string
    {
        $hex = ltrim($value ?? '', '#');
        if (preg_match('/^[a-f0-9]{3}$/i', $hex)) {
            $hex = implode('', array_map(fn ($part) => $part.$part, str_split($hex)));
        }

        return preg_match('/^[a-f0-9]{6}$/i', $hex) ? '#'.$hex : '#7c6a46';
    }

    private function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($item) => is_array($item) ? $this->canonical($item) : $item, $value);
    }
}
