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

    public static function money($value, int $maxCents = 999999999): ?string
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
        $id = strtoupper(preg_replace('/\s+/', '', (string) $value));
        return preg_match('/^(?:[XYZ]\d{7}[A-Z]|\d{8}[A-Z])$/', $id) ? $id : null;
    }
}
