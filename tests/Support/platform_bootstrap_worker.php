<?php

require_once dirname(__DIR__, 2) . '/tests/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/services/PlatformAdminBootstrapService.php';

$suffix = preg_replace('/[^a-z0-9]/i', '', (string) ($argv[1] ?? 'worker')) ?: 'worker';
try {
    $result = (new PlatformAdminBootstrapService(Database::getInstance()->getConnection()))->bootstrap([
        'name'=>'Operador', 'surname'=>'Concurrente ' . $suffix,
        'email'=>'platform.' . strtolower($suffix) . '@test.invalid',
        'username'=>'platform.' . strtolower($suffix),
        'password'=>'F221-Temporary-' . $suffix . '-98!',
    ]);
    echo json_encode(['created'=>(bool)$result['created'],'user_id'=>(int)$result['user_id']], JSON_THROW_ON_ERROR) . "\n";
    exit(0);
} catch (DomainException $error) {
    echo json_encode(['created'=>false,'rejected'=>true], JSON_THROW_ON_ERROR) . "\n";
    exit(11);
} catch (Throwable $error) {
    echo json_encode(['created'=>false,'internal'=>get_class($error)], JSON_THROW_ON_ERROR) . "\n";
    exit(12);
}
