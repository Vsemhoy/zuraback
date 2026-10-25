<?php

namespace App\Services;

use App\Models\LedgerTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

class LedgerReportService
{
    public function __construct(private readonly LedgerInterestService $interest) {}

    /** The caller supplies only authorized, non-excluded accounts. Amounts stay in minor units. */
    public function monthly(Collection $accounts, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($accounts->isEmpty()) {
            return [];
        }
        $asOf = $to->min(CarbonImmutable::today());
        $daily = $this->interest->dailyBalances($accounts, $from, $asOf);
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
            ->where('status', 'posted')->whereBetween('occurred_on', [$from->toDateString(), $asOf->toDateString()]);
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

    private function sum(string $a, string $b): string
    {
        $sum = (int) $a + (int) $b;
        abort_unless(is_int($sum), 422, 'Сумма превышает диапазон расчёта Ledger.');

        return (string) $sum;
    }
}
