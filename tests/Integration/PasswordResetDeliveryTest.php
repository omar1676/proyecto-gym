<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/services/PasswordResetDeliveryService.php';

$db = Database::getInstance()->getConnection();
$users = new UserModel();
$service = new PasswordResetDeliveryService();
$user = $users->buscarPorId(1);
$events = [];
$failed = $service->issue(
    $users,
    $user,
    static fn(string $email, string $name, string $url): bool => false,
    static function (string $result, string $reason) use (&$events): void { $events[] = [$result, $reason]; }
);
$tokenAfterFailure = $db->query('SELECT reset_token FROM usuario WHERE id_usuario=1')->fetchColumn();
check('fallo SMTP se devuelve como fallo real', $failed === false);
check('fallo SMTP invalida el token no entregado', $tokenAfterFailure === null);
check('fallo SMTP queda separado y auditable', $events === [['fallo', 'EMAIL_DELIVERY_FAILED']]);

$events = [];
$capturedUrl = '';
$delivered = $service->issue(
    $users,
    $user,
    static function (string $email, string $name, string $url) use (&$capturedUrl): bool {
        $capturedUrl = $url;
        return true;
    },
    static function (string $result, string $reason) use (&$events): void { $events[] = [$result, $reason]; }
);
$stored = (string) $db->query('SELECT reset_token FROM usuario WHERE id_usuario=1')->fetchColumn();
parse_str((string) parse_url($capturedUrl, PHP_URL_QUERY), $query);
$rawToken = (string) ($query['token'] ?? '');
check('entrega SMTP exitosa queda diferenciada', $delivered && $events === [['exito', 'EMAIL_SENT']]);
check('token generado conserva 256 bits y solo se persiste su hash', strlen($rawToken) === 64 && preg_match('/^[a-f0-9]{64}$/', $rawToken) === 1
    && strlen($stored) === 64 && hash_equals($stored, hash('sha256', $rawToken)) && !hash_equals($stored, $rawToken));

$db->exec('UPDATE usuario SET reset_token=NULL,reset_expira=NULL WHERE id_usuario=1');
finishTests();
