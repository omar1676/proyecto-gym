<?php
require_once dirname(__DIR__) . '/bootstrap.php';
$payload = "</script><script>alert('x')</script>";
$encoded = json_encode(['name' => $payload], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
check('JSON embebido no conserva etiquetas ejecutables', strpos($encoded, '<script>') === false);
check('JSON embebido codifica apóstrofos', strpos($encoded, "'") === false);
check('htmlspecialchars codifica salida HTML', htmlspecialchars($payload, ENT_QUOTES, 'UTF-8') !== $payload);
finishTests();
