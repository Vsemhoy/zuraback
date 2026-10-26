<?php

namespace App\Services;

use App\Models\LedgerTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

class LedgerReportService
{
    public function __construct(private readonly LedgerInterestService $interest) {}

    /** The caller supplies only authorized, non-excluded accounts. Amounts stay in minor units. */
    public function monthly(Collection $accounts, CarbonImmutable $from, CarbonImmutable $to, bool $forecast = false): array
    {
        if ($accounts->isEmpty()) {
            return [];
        }
        $asOf = $forecast ? $to : $to->min(CarbonImmutable::today());
        $daily = $this->interest->dailyBalances($accounts, $from, $asOf, $forecast);
        $rows = [];
        foreach ($accounts as $account) {
            for ($month = $from; $month->lte($asOf); $month = $month->addMonth()) {
                $key = $account->id.':'.$month->format('Y-m');
                $rows[$key] = ['account_id' => $account->id, 'month' => $month->format('Y-m'), 'currency' => $account->currency,
                    'opening_minor' => null, 'closing_minor' => null, 'interest' => '0',
                    'income' => '0', 'expense' => '0', 'sent' => '0', 'received' => '0', 'adjustment_in' => '0', 'adjustment_out' => '0'];
            }
            foreach ($daily[$account->id] ?? [] as $day) {
                $key = $account->id.':'.substr($day['date'], 0, 7);
                $rows[$key]['opening_minor'] ??= $day['opening_minor'];
                $rows[$key]['closing_minor'] = $day['closing_minor'];
                $rows[$key]['interest'] = $this->sum($rows[$key]['interest'], $day['interest_minor']);
            }
        }
        $base = LedgerTransaction::whereIn('scope_id', $accounts->pluck('scope_id')->unique())
            ->whereIn('status', $forecast ? ['posted', 'planned'] : ['posted'])->whereBetween('occurred_on', [$from->toDateString(), $asOf->toDateString()]);
        $out = (clone $base)->whereIn('account_id', $accounts->modelKeys())
            ->selectRaw('account_id, occurred_on, kind, SUM(amount_minor) as total_minor')->groupBy('account_id', 'occurred_on', 'kind');
        $in = (clone $base)->where('kind', 'transfer')->whereIn('target_account_id', $accounts->modelKeys())
            ->selectRaw("target_account_id as account_id, occurred_on, 'received' as kind, SUM(amount_minor) as total_minor")->groupBy('target_account_id', 'occurred_on');
        foreach ($out->unionAll($in)->get() as $movement) {
            $key = $movement->account_id.':'.$movement->occurred_on->format('Y-m');
            $field = $movement->kind === 'transfer' ? 'sent' : $movement->kind;
            $rows[$key][$field] = $this->sum($rows[$key][$field], (string) $movement->total_minor);
        }

        return array_values($rows);
    }

    /** Only expense postings count as spending; transfers, corrections and modeled interest are separate. */
    public function expenses(Collection $accounts, Collection $categories, Collection $projects, CarbonImmutable $from, CarbonImmutable $to, bool $forecast, ?string $allocationProject, bool $allTime): array
    {
        $asOf = $forecast ? $to : $to->min(CarbonImmutable::today());
        $query = LedgerTransaction::whereIn('scope_id', $accounts->pluck('scope_id')->unique())
            ->whereIn('account_id', $accounts->modelKeys())->where('kind', 'expense')
            ->whereIn('status', $forecast ? ['posted', 'planned'] : ['posted']);
        if ($allocationProject) {
            $query->where('allocation_project_id', $allocationProject);
        }
        if ($allTime) {
            if (! $forecast) {
                $query->where('occurred_on', '<=', CarbonImmutable::today()->toDateString());
            }
        } else {
            $query->whereBetween('occurred_on', [$from->toDateString(), $asOf->toDateString()]);
        }
        $categoryMap = $categories->keyBy('id');
        $projectMap = $projects->keyBy('id');
        $result = ['categories' => [], 'projects' => [], 'totals' => []];
        $rows = $query->selectRaw('category_id, allocation_project_id, currency, occurred_on, SUM(amount_minor) AS total_minor')
            ->groupBy('category_id', 'allocation_project_id', 'currency', 'occurred_on')->orderBy('occurred_on')->cursor();
        $firstDate = $lastDate = null;
        foreach ($rows as $row) {
            $date = $row->occurred_on->toDateString();
            $firstDate ??= $date;
            $lastDate = $date;
            $month = substr($date, 0, 7);
            $amount = (string) $row->total_minor;
            $category = $categoryMap->get($row->category_id);
            $project = $projectMap->get($row->allocation_project_id);
            $categoryTitle = $row->category_id ? 'Категория недоступна' : 'Без категории';
            if ($category) {
                $titles = [$category->title];
                $seen = [$category->id];
                $parent = $categoryMap->get($category->parent_id);
                while ($parent && ! in_array($parent->id, $seen, true)) {
                    array_unshift($titles, $parent->title);
                    $seen[] = $parent->id;
                    $parent = $categoryMap->get($parent->parent_id);
                }
                $categoryTitle = implode(' / ', $titles);
            }
            $groups = [
                'categories' => [$category?->id ?? ($row->category_id ? 'unavailable' : 'none'), $categoryTitle],
                'projects' => [$project?->id ?? ($row->allocation_project_id ? 'unavailable' : 'none'), $project ? $project->key.' · '.$project->title : ($row->allocation_project_id ? 'Проект недоступен' : 'Без проекта')],
            ];
            foreach ($groups as $type => [$id, $title]) {
                $key = $id.':'.$row->currency;
                $result[$type][$key] ??= ['id' => $id, 'title' => $title, 'currency' => $row->currency, 'total_minor' => '0', 'months' => []];
                $group = &$result[$type][$key];
                $group['total_minor'] = $this->sum($group['total_minor'], $amount);
                $group['months'][$month] = $this->sum($group['months'][$month] ?? '0', $amount);
                unset($group);
            }
            $result['totals'][$row->currency] = $this->sum($result['totals'][$row->currency] ?? '0', $amount);
        }
        foreach (['categories', 'projects'] as $type) {
            $result[$type] = array_values($result[$type]);
        }

        return [...$result, 'forecast' => $forecast, 'all_time' => $allTime,
            'from' => $allTime ? $firstDate : $from->toDateString(), 'as_of' => $allTime ? $lastDate : $asOf->toDateString()];
    }

    private function sum(string $a, string $b): string
    {
        $sum = (int) $a + (int) $b;
        abort_unless(is_int($sum), 422, 'Сумма превышает диапазон расчёта Ledger.');

        return (string) $sum;
    }
}
