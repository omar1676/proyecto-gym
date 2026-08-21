<?php

final class Money
{
    public static function cents($value): int
    {
        $value = str_replace(',', '.', trim((string) $value));
        if (!preg_match('/^-?\d+(?:\.\d{1,2})?$/', $value)) {
            throw new InvalidArgumentException('Importe monetario no válido.');
        }
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '-');
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $whole = ltrim($whole, '0') ?: '0';
        $maxWhole = (string) intdiv(PHP_INT_MAX, 100);
        if (strlen($whole) > strlen($maxWhole)
            || (strlen($whole) === strlen($maxWhole) && strcmp($whole, $maxWhole) > 0)) {
            throw new InvalidArgumentException('Importe monetario fuera de rango.');
        }
        $cents = ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
        if ($cents < 0 || $cents > PHP_INT_MAX) {
            throw new InvalidArgumentException('Importe monetario fuera de rango.');
        }
        return $negative ? -$cents : $cents;
    }

    public static function decimal(int $cents): string
    {
        $negative = $cents < 0 ? '-' : '';
        $cents = abs($cents);
        return $negative . intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function multiply($value, int $quantity): string
    {
        if ($quantity < 0) throw new InvalidArgumentException('Cantidad no válida.');
        return self::decimal(self::cents($value) * $quantity);
    }
}
