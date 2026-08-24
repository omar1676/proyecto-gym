<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/services/AttendanceEventService.php';
require_once dirname(__DIR__, 2) . '/app/services/RetentionService.php';

$mode = (string)($argv[1] ?? '');
$barrier = (string)($argv[2] ?? '');
if ($mode === '' || $barrier === '') exit(2);
for ($i=0; $i<200 && !is_file($barrier); $i++) usleep(10000);
if (!is_file($barrier)) exit(3);
$db = Database::getInstance()->getConnection();

try {
    if ($mode === 'event') {
        $service = new AttendanceEventService($db, (int)$argv[3], new DateTimeZone('Europe/Madrid'));
        $result = $service->record(
            (int)$argv[4], (int)$argv[5], new DateTimeImmutable((string)$argv[6], new DateTimeZone('UTC')),
            'IMPORT', (string)$argv[7]
        );
        echo json_encode(['success'=>true,'created'=>$result['created']], JSON_THROW_ON_ERROR);
        exit(0);
    }
    if ($mode === 'job') {
        $result = (new RetentionService($db, (int)$argv[3]))->run((string)$argv[4]);
        echo json_encode(['success'=>true,'reused'=>$result['reused']], JSON_THROW_ON_ERROR);
        exit(0);
    }
    if ($mode === 'action') {
        $service = new RetentionService($db, (int)$argv[3], (int)$argv[4]);
        $ok = $service->act((int)$argv[5], (int)$argv[6], 'REVIEW', (string)$argv[7], (int)$argv[8]);
        echo json_encode(['success'=>$ok], JSON_THROW_ON_ERROR);
        exit($ok ? 0 : 1);
    }
    exit(2);
} catch (Throwable $error) {
    echo json_encode(['success'=>false,'error'=>get_class($error)], JSON_THROW_ON_ERROR);
    exit(1);
}
