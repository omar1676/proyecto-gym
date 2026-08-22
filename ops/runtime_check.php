<?php

require_once dirname(__DIR__) . '/app/config/config.php';
require_once dirname(__DIR__) . '/app/helpers/HealthCheck.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

try {
    $health = HealthCheck::run();
    $payload = [
        'status' => $health['ok'] ? 'ok' : 'error',
        'component' => $health['ok'] ? null : ($health['component'] ?? 'runtime'),
    ];
    echo json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($health['ok'] ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, "RUNTIME NO DISPONIBLE: fallo de infraestructura.\n");
    exit(1);
}
