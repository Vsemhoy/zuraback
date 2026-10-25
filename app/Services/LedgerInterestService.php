<?php

namespace App\Services;

use App\Models\LedgerAccount;
use App\Models\LedgerTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class LedgerInterestService
{
    private const MAX_MINOR = 9_000_000_000_000_000;

    public function assertSafe(LedgerAccount ...$accounts): void
    {
        $this->enrich(new Collection(array_map(fn (LedgerAccount $account): LedgerAccount => clone $account, $accounts)));
    }

    /**
     * Enrich already authorized accounts in one grouped union query. Calculations never post payments.
     */
    public function enrich(Collection $accounts): Collection
    {
        $asOf = CarbonImmutable::today();
        $changes = $this->movements($accounts->filter(fn (LedgerAccount $account): bool => $account->balance_is_known), $asOf);
        foreach ($accounts as $account) {
            if (! $account->balance_is_known) {
                $account->forceFill(['balance_today_minor' => null, 'principal_today_minor' => null, 'interest_minor' => null, 'balance_as_of' => $asOf->toDateString()]);

                continue;
            }
            $result = $this->calculate($account, $changes[$account->id] ?? [], $asOf);
            foreach ($result['summary'] as $field => $value) {
                $account->setAttribute($field, $value);
            }
        }

        return $accounts;
    }

    /** Daily balances for already authorized accounts, using the same calculation as statements. */
    public function dailyBalances(Collection $accounts, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $asOf = $to->min(CarbonImmutable::today());
        if ($from->gt($asOf)) {
            return [];
        }
        $known = $accounts->filter(fn (LedgerAccount $account): bool => $account->balance_is_known);
        $changes = $this->movements($known, $asOf);
        $result = [];
        foreach ($known as $account) {
            $result[$account->id] = $this->calculate($account, $changes[$account->id] ?? [], $asOf, $from)['days'];
        }

        return $result;
    }

    public function statement(LedgerAccount $account, string $month): array
    {
        if (! $account->balance_is_known) {
            throw ValidationException::withMessages(['account_id' => 'Начальный остаток этого счёта неизвестен. Сначала подтвердите его в настройках.']);
        }
        $today = CarbonImmutable::today();
        $from = CarbonImmutable::createFromFormat('!Y-m', $month);
        $asOf = $from->endOfMonth()->min($today);
        $changes = $this->movements(new Collection([$account]), $asOf);
        $result = $this->calculate($account, $changes[$account->id] ?? [], $asOf, $from);
        $days = $result['days'];
        $movement = $interest = 0;
        foreach ($days as $day) {
            $movement = $this->add($movement, (int) $day['movement_minor']);
            $interest = $this->add($interest, (int) $day['interest_minor']);
        }

        return ['account_id' => $account->id, 'currency' => $account->currency, 'month' => $month, 'as_of' => $asOf->toDateString(),
            'method' => $account->type === 'credit' ? 'daily_compound_actual_year_half_away_from_zero' : 'posted_daily_balance', 'calculated' => true,
            'opening_minor' => $days[0]['opening_minor'], 'movement_minor' => (string) $movement, 'interest_minor' => (string) $interest,
            'closing_minor' => $days[array_key_last($days)]['closing_minor'], 'days' => $days];
    }

    /**
     * Rate in basis points, money in integer minor units; round halves away from zero.
     * Splitting the quotient avoids multiplying the full balance (which could overflow).
     */
    public function dailyInterest(int $balance, int $rateBps, int $daysInYear): int
    {
        $this->bounded($balance);
        if ($rateBps < 0 || $rateBps > 100000 || ! in_array($daysInYear, [365, 366], true)) {
            throw ValidationException::withMessages(['interest_rate_bps' => 'Некорректные условия начисления.']);
        }
        if ($balance >= 0 || $rateBps === 0) {
            return 0;
        }
        $divisor = 10000 * $daysInYear;
        $debt = -$balance;

        return -(intdiv($debt, $divisor) * $rateBps + intdiv(($debt % $divisor) * $rateBps + intdiv($divisor, 2), $divisor));
    }

    /** @return array<string, array<string, int>> */
    private function movements(Collection $accounts, CarbonImmutable $asOf): array
    {
        if ($accounts->isEmpty()) {
            return [];
        }
        $result = [];
        $query = LedgerTransaction::whereIn('scope_id', $accounts->pluck('scope_id')->unique())->where('status', 'posted')->where('occurred_on', '<=', $asOf->toDateString());
        $out = (clone $query)->whereIn('account_id', $accounts->modelKeys())
            ->selectRaw('account_id, occurred_on, kind, SUM(amount_minor) AS total_minor')->groupBy('account_id', 'occurred_on', 'kind');
        $in = (clone $query)->where('kind', 'transfer')->whereIn('target_account_id', $accounts->modelKeys())
            ->selectRaw("target_account_id AS account_id, occurred_on, 'income' AS kind, SUM(amount_minor) AS total_minor")->groupBy('target_account_id', 'occurred_on');
        foreach ($out->unionAll($in)->get() as $row) {
            $date = $row->occurred_on->toDateString();
            $amount = $this->minor((string) $row->total_minor);
            $delta = in_array($row->kind, ['income', 'adjustment_in'], true) ? $amount : -$amount;
            $result[$row->account_id][$date] = $this->add($result[$row->account_id][$date] ?? 0, $delta);
        }

        return $result;
    }

    private function calculate(LedgerAccount $account, array $changes, CarbonImmutable $asOf, ?CarbonImmutable $from = null): array
    {
        $principal = $balance = $this->minor($account->opening_minor);
        $interest = 0;
        $start = $account->type === 'credit' && $account->interest_rate_bps > 0 && $account->interest_start_on
            ? CarbonImmutable::parse($account->interest_start_on->toDateString()) : $asOf->addDay();
        $cursor = $from ? $start->min($from) : $start;
        $end = $account->interest_end_on?->toDateString();
        foreach ($changes as $date => $delta) {
            $principal = $this->add($principal, $delta);
            if ($date < $cursor->toDateString()) {
                $balance = $this->add($balance, $delta);
            }
        }
        $days = [];
        while ($cursor->lte($asOf)) {
            $date = $cursor->toDateString();
            $opening = $balance;
            $movement = $changes[$date] ?? 0;
            $balance = $this->add($balance, $movement);
            $charge = $cursor->gte($start) && ($end === null || $date <= $end)
                ? $this->dailyInterest($balance, $account->interest_rate_bps ?? 0, $cursor->isLeapYear() ? 366 : 365) : 0;
            $interest = $this->add($interest, $charge);
            $balance = $this->add($balance, $charge);
            if ($from && $cursor->gte($from)) {
                $days[] = ['date' => $date, 'opening_minor' => (string) $opening, 'movement_minor' => (string) $movement,
                    'interest_minor' => (string) $charge, 'closing_minor' => (string) $balance];
            }
            $cursor = $cursor->addDay();
        }

        return ['summary' => ['balance_today_minor' => (string) $balance, 'principal_today_minor' => (string) $principal,
            'interest_minor' => (string) $interest, 'balance_as_of' => $asOf->toDateString()], 'days' => $days];
    }

    private function minor(string $value): int
    {
        if (! preg_match('/^-?[0-9]{1,16}$/D', $value)) {
            throw ValidationException::withMessages(['account_id' => 'Сумма превышает безопасный диапазон расчёта Ledger.']);
        }

        return $this->bounded((int) $value);
    }

    private function add(int $left, int $right): int
    {
        return $this->bounded($left + $right);
    }

    private function bounded(int $value): int
    {
        if ($value < -self::MAX_MINOR || $value > self::MAX_MINOR) {
            throw ValidationException::withMessages(['account_id' => 'Сумма превышает безопасный диапазон расчёта Ledger.']);
        }

        return $value;
    }
}
