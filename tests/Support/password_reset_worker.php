<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/models/UserModel.php';

$barrier = (string) ($argv[1] ?? '');
$variant = (int) ($argv[2] ?? -1);
$input = json_decode((string) stream_get_contents(STDIN), true);
$token = is_array($input) ? (string) ($input['token'] ?? '') : '';
$passwords = [
    'F21.1-Atomic-Reset-A!72',
    'F21.1-Atomic-Reset-B!93',
];
if ($barrier === '' || $token === '' || !isset($passwords[$variant])) {
    fwrite(STDERR, 'invalid_worker_input');
    exit(2);
}
$deadline = microtime(true) + 10;
while (!is_file($barrier) && microtime(true) < $deadline) {
    usleep(1000);
}
if (!is_file($barrier)) {
    fwrite(STDERR, 'barrier_timeout');
    exit(2);
}

try {
    $user = (new UserModel())->consumirTokenReset($token, $passwords[$variant]);
    echo json_encode(['success' => $user !== null, 'variant' => $variant]);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'worker_failed');
    exit(1);
}
