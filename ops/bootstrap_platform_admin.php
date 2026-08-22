<?php

require_once dirname(__DIR__) . '/app/config/config.php';
require_once dirname(__DIR__) . '/app/config/database.php';
require_once dirname(__DIR__) . '/app/services/PlatformAdminBootstrapService.php';

if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
$args = array_slice($argv, 1);
if (APP_ENV === 'staging' && !in_array('--confirm-staging', $args, true)) {
    fwrite(STDERR, "Falta --confirm-staging.\n"); exit(2);
}
if (APP_ENV === 'production' && !in_array('--confirm-production', $args, true)) {
    fwrite(STDERR, "Falta --confirm-production.\n"); exit(2);
}

try {
    $result = (new PlatformAdminBootstrapService(Database::getInstance()->getConnection()))->bootstrap([
        'name' => getenv('PLATFORM_ADMIN_NAME') ?: '',
        'surname' => getenv('PLATFORM_ADMIN_SURNAME') ?: '',
        'email' => getenv('PLATFORM_ADMIN_EMAIL') ?: '',
        'username' => getenv('PLATFORM_ADMIN_USERNAME') ?: '',
        'password' => getenv('PLATFORM_ADMIN_PASSWORD') ?: '',
    ]);
    echo json_encode(['status' => 'ok', 'created' => $result['created'], 'user_id' => $result['user_id']], JSON_THROW_ON_ERROR) . PHP_EOL;
    exit(0);
} catch (InvalidArgumentException|DomainException $error) {
    fwrite(STDERR, 'BOOTSTRAP RECHAZADO: ' . $error->getMessage() . PHP_EOL);
    exit(2);
} catch (Throwable) {
    fwrite(STDERR, "BOOTSTRAP FALLIDO: no se creó ninguna identidad parcial.\n");
    exit(1);
}
