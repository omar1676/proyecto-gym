<?php
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/models/MembresiaModel.php';
require_once dirname(__DIR__, 2) . '/app/models/SepaModel.php';

$operation = $argv[1] ?? '';
$barrier = $argv[2] ?? '';
$resourceId = (int) ($argv[3] ?? 0);
$key = (string) ($argv[4] ?? '');
$variant = (int) ($argv[5] ?? 0);
$deadline = microtime(true) + 10;
while (!is_file($barrier) && microtime(true) < $deadline) usleep(1000);
if (!is_file($barrier)) { fwrite(STDERR, 'barrier timeout'); exit(2); }

$error = '';
$id = null;
if ($operation === 'trial') {
    $id = (new MembresiaModel(1, 1))->iniciarPrueba($resourceId, $error, 1, $key);
} elseif ($operation === 'mandate') {
    $iban = $variant === 0 ? 'ES9121000418450200051332' : 'DE89370400440532013000';
    $id = (new SepaModel(1, 1))->crearMandato($resourceId, $iban, '2026-08-21', $error, 'recurrente', $key);
} elseif ($operation === 'remittance') {
    $id = (new SepaModel(1, 1))->crearRemesa([$resourceId], 'F20 concurrente', '2026-08-25', 1, $error, $key);
}
echo json_encode(['success' => $id !== null, 'id' => $id, 'error' => $error]);
exit(0);
