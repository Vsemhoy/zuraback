<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class LedgerMoney
{
    public const CURRENCIES = ['RUB', 'USD', 'EUR', 'CNY'];

    public static function parse(mixed $value, bool $signed = false, string $field = 'amount'): int
    {
        $text = is_scalar($value) ? (string) $value : '';
        if (! preg_match('/^(-?)([0-9]{1,10})(?:\.([0-9]{1,2}))?$/D', $text, $parts) || (! $signed && str_starts_with($text, '-'))) {
            throw ValidationException::withMessages([$field => 'Введите сумму до 9 999 999 999.99, не более двух знаков после точки.']);
        }
        $minor = ((int) $parts[2]) * 100 + (int) str_pad($parts[3] ?? '', 2, '0');

        return ($parts[1] ?? '') === '-' ? -$minor : $minor;
    }

    public static function decimal(int|string $minor): string
    {
        $value = (int) $minor;

        return ($value < 0 ? '-' : '').intdiv(abs($value), 100).'.'.str_pad((string) (abs($value) % 100), 2, '0', STR_PAD_LEFT);
    }
}
