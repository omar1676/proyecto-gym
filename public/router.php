<?php
// Router exclusivo del servidor integrado de PHP para staging/local.
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
if ($path === '/health' || $path === '/health/') { require __DIR__ . '/index.php'; return true; }
$candidate = realpath(__DIR__ . $path);
if ($candidate && str_starts_with(strtolower(str_replace('\\','/',$candidate)), strtolower(str_replace('\\','/',__DIR__)) . '/') && is_file($candidate)) return false;
http_response_code(404);
header('Content-Type: text/plain; charset=UTF-8');
echo "Not Found\n";
return true;
