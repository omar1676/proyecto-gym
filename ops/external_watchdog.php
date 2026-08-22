<?php

/**
 * Sonda pensada para ejecutarse FUERA del VPS. No usa DB ni secretos de la
 * aplicación. El orquestador externo debe alertar cuando el exit sea distinto
 * de cero. WATCHDOG_URL se obtiene del entorno o del primer argumento.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
$base = rtrim((string) (getenv('WATCHDOG_URL') ?: ($argv[1] ?? '')), '/');
if (!filter_var($base, FILTER_VALIDATE_URL) || parse_url($base, PHP_URL_SCHEME) !== 'https') {
    fwrite(STDERR, "WATCHDOG_URL HTTPS obligatorio.\n"); exit(2);
}

function watchdogGet(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $start = hrtime(true); $body = curl_exec($ch); $error = curl_errno($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ['http' => $http, 'body' => (string) $body, 'error' => $error,
        'latency_ms' => round((hrtime(true) - $start) / 1e6, 1)];
}

$health = watchdogGet($base . '/health');
$heartbeat = watchdogGet($base . '/heartbeat');
$healthJson = json_decode($health['body'], true);
$heartbeatJson = json_decode($heartbeat['body'], true);
$host = (string) parse_url($base, PHP_URL_HOST);
$context = stream_context_create(['ssl' => [
    'capture_peer_cert' => true, 'verify_peer' => true, 'verify_peer_name' => true,
    'peer_name' => $host, 'SNI_enabled' => true,
]]);
$socket = @stream_socket_client('ssl://' . $host . ':443', $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $context);
$tlsDays = null;
if ($socket) {
    $params = stream_context_get_params($socket); fclose($socket);
    $certificate = $params['options']['ssl']['peer_certificate'] ?? null;
    $parsed = $certificate ? openssl_x509_parse($certificate) : false;
    if ($parsed && !empty($parsed['validTo_time_t'])) $tlsDays = (int) floor(((int) $parsed['validTo_time_t'] - time()) / 86400);
}
$ok = $health['error'] === 0 && $health['http'] === 200 && ($healthJson['status'] ?? '') === 'ok'
    && $heartbeat['error'] === 0 && $heartbeat['http'] === 200 && ($heartbeatJson['status'] ?? '') === 'ok'
    && (int) ($heartbeatJson['age_seconds'] ?? 999999) <= 300
    && $tlsDays !== null && $tlsDays >= 14;
$result = [
    'status' => $ok ? 'OK' : 'CRITICAL',
    'checked_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
    'health_http' => $health['http'],
    'heartbeat_http' => $heartbeat['http'],
    'heartbeat_age_seconds' => $heartbeatJson['age_seconds'] ?? null,
    'latency_ms' => $health['latency_ms'],
    'tls_days_remaining' => $tlsDays,
];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 2);
