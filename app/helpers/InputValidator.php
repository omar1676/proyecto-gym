<?php

final class InputValidator
{
    public static function id($value): ?int
    {
        $filtered = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $filtered === false ? null : (int) $filtered;
    }

    public static function email($value): ?string
    {
        $email = mb_strtolower(trim((string) $value));
        return strlen($email) <= 190 && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    public static function text($value, int $max, bool $required = true): ?string
    {
        $raw = (string) $value;
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $raw)) return null;
        $text = trim($raw);
        if (($required && $text === '') || mb_strlen($text) > $max) return null;
        return $text;
    }

    public static function phone($value): ?string
    {
        $phone = preg_replace('/[\s().-]+/', '', trim((string) $value));
        return preg_match('/^\+?[0-9]{7,15}$/', $phone) ? $phone : null;
    }

    /** Límite por defecto alineado con los precios DECIMAL(8,2) del catálogo. */
    public static function money($value, int $maxCents = 99999999): ?string
    {
        require_once __DIR__ . '/Money.php';
        try {
            $cents = Money::cents($value);
            return $cents >= 0 && $cents <= $maxCents ? Money::decimal($cents) : null;
        } catch (InvalidArgumentException $e) {
            return null;
        }
    }

    public static function date($value): ?string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $value);
        return $date && $date->format('Y-m-d') === $value ? $value : null;
    }

    public static function dniNie($value): ?string
    {
        $id = strtoupper(preg_replace('/[\s-]+/', '', trim((string) $value)));
        if (!preg_match('/^(?:[XYZ]\d{7}[A-Z]|\d{8}[A-Z])$/', $id)) return null;

        $letras = 'TRWAGMYFPDXBNJZSQVHLCKE';
        $numero = substr($id, 0, -1);
        if (preg_match('/^[XYZ]/', $numero)) {
            $numero = strtr($numero, ['X' => '0', 'Y' => '1', 'Z' => '2']);
        }
        $esperada = $letras[(int) $numero % 23];
        return substr($id, -1) === $esperada ? $id : null;
    }

    /** Identificador minimizado para auditoría; nunca devuelve el DNI/NIE completo. */
    public static function maskDniNie($value): string
    {
        $id = strtoupper(preg_replace('/[\s-]+/', '', trim((string) $value)));
        if ($id === '') return '';
        if (strlen($id) <= 3) return str_repeat('*', strlen($id));
        return str_repeat('*', strlen($id) - 3) . substr($id, -3);
    }
}
