<?php

/** Flash allowlisted para devolver un formulario de socio inválido sin secretos. */
final class SocioFormState
{
    private const KEY = 'socio_form_flash';
    private const TTL = 600;
    private const ALLOWED = [
        'nombre' => 100, 'apellidos' => 150, 'dni' => 20, 'email' => 190,
        'telefono' => 20, 'usuario' => 60, 'iban' => 40,
        'id_tipo_membresia' => 12, 'metodo_pago' => 20, 'id_suplemento' => 12,
        'id_socio' => 12, 'profile_version' => 12,
    ];

    public static function put(
        string $mode,
        array $values,
        array $errors,
        string $summary,
        int $userId,
        int $companyId,
        ?int $siteId
    ): void {
        if (!in_array($mode, ['alta', 'editar'], true)) return;
        $safeValues = [];
        foreach (self::ALLOWED as $field => $max) {
            if (!array_key_exists($field, $values)) continue;
            $raw = is_scalar($values[$field]) || $values[$field] === null ? (string) ($values[$field] ?? '') : '';
            if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $raw)) $raw = '';
            $safeValues[$field] = mb_substr($raw, 0, $max);
        }
        $safeErrors = [];
        foreach ($errors as $field => $message) {
            if (!isset(self::ALLOWED[$field]) && $field !== 'contrasena' && $field !== '_form') continue;
            $safeErrors[(string) $field] = mb_substr(strip_tags((string) $message), 0, 240);
        }
        $_SESSION[self::KEY] = [
            'mode' => $mode,
            'values' => $safeValues,
            'errors' => $safeErrors,
            'summary' => mb_substr(strip_tags($summary), 0, 300),
            'user_id' => $userId,
            'company_id' => $companyId,
            'site_id' => $siteId,
            'created_at' => time(),
        ];
    }

    /** @return array{mode:string,values:array,errors:array,summary:string}|null */
    public static function consume(int $userId, int $companyId, ?int $siteId): ?array
    {
        $state = $_SESSION[self::KEY] ?? null;
        unset($_SESSION[self::KEY]);
        if (!is_array($state)
            || (int) ($state['user_id'] ?? 0) !== $userId
            || (int) ($state['company_id'] ?? 0) !== $companyId
            || (($state['site_id'] ?? null) === null ? null : (int) $state['site_id']) !== $siteId
            || time() - (int) ($state['created_at'] ?? 0) > self::TTL) {
            return null;
        }
        return [
            'mode' => (string) ($state['mode'] ?? ''),
            'values' => is_array($state['values'] ?? null) ? $state['values'] : [],
            'errors' => is_array($state['errors'] ?? null) ? $state['errors'] : [],
            'summary' => (string) ($state['summary'] ?? ''),
        ];
    }
}
