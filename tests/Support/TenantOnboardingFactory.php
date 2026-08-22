<?php

require_once dirname(__DIR__, 2) . '/app/services/TenantProvisioningService.php';

final class TenantOnboardingFactory
{
    public static function input(string $suffix, array $overrides = []): array
    {
        $safe = strtolower(preg_replace('/[^a-z0-9]+/i', '', $suffix)) ?: 'tenant';
        $hash = hash('sha256', $safe);
        $uuid = substr($hash, 0, 8) . '-' . substr($hash, 8, 4) . '-4' . substr($hash, 13, 3)
            . '-8' . substr($hash, 17, 3) . '-' . substr($hash, 20, 12);
        $digits = substr(preg_replace('/[^0-9]/', '', $hash) . '000000000', 0, 9);
        $base = [
            'idempotency_key' => $uuid,
            'company_name' => 'TEST F22 Empresa ' . $suffix,
            'commercial_name' => 'Gimnasio ' . $suffix,
            'company_email' => 'empresa.' . $safe . '@test.invalid',
            'phone' => '6' . $digits,
            'site_name' => $suffix . ' Centro',
            'site_access_email' => 'acceso.' . $safe . '@test.invalid',
            'owner_name' => 'Alicia',
            'owner_surname' => $suffix,
            'owner_email' => 'owner.' . $safe . '@test.invalid',
            'owner_username' => 'owner.' . $safe,
            'primary_color' => '#2457a7',
            'text_color' => '#ffffff',
            'timezone' => 'Europe/Madrid',
            'currency' => 'EUR',
            'categories' => ['Bebidas', 'Material'],
            'membership_types' => [[
                'name' => 'Mensual ' . $suffix,
                'description' => 'Tarifa sintética',
                'price' => '39.90',
                'duration_months' => 1,
                'vat' => '21.00',
            ]],
        ];
        return array_replace($base, $overrides);
    }

    public static function create(PDO $db, string $suffix, array $overrides = [], bool $activate = true): array
    {
        $actor = (int) $db->query("SELECT id_usuario FROM usuario WHERE rol='superadmin' AND activo=1 ORDER BY id_usuario LIMIT 1")->fetchColumn();
        $service = new TenantProvisioningService($db, $actor);
        $result = $service->provision(self::input($suffix, $overrides));
        if ($activate) $service->activate((int) $result['company_id']);
        return $result;
    }
}
