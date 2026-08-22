<?php

require_once dirname(__DIR__, 2) . '/tests/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/helpers/TenantLifecyclePolicy.php';
require_once dirname(__DIR__, 2) . '/app/models/UserModel.php';

$company = (int) ($argv[1] ?? 0);
$site = (int) ($argv[2] ?? 0);
$ready = (string) ($argv[3] ?? '');
$suffix = preg_replace('/[^a-z0-9]/i', '', (string) ($argv[4] ?? 'worker')) ?: 'worker';
if ($company <= 0 || $site <= 0 || $ready === '') exit(2);

$db = Database::getInstance()->getConnection();
$lease = TenantLifecyclePolicy::acquireBusinessWrite($db, $company);
file_put_contents($ready, 'ready', LOCK_EX);
usleep(700000);
$created = (new UserModel($site, $company))->crear(
    'Carrera', 'Antes Cancelación', 'F221' . substr(hash('sha256', $suffix), 0, 8), null,
    'race.' . strtolower($suffix) . '@test.invalid', 'race.' . strtolower($suffix), 'Synthetic-only-F221!'
);
echo json_encode(['created'=>$created], JSON_THROW_ON_ERROR) . "\n";
exit($created ? 0 : 1);
