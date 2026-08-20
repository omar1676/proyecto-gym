<?php
/** Medición HTTP real del listado paginado contra el servidor local de test. */

require_once __DIR__ . '/_arranque.php';

$base = getenv('TEST_BASE_URL') ?: 'http://127.0.0.1:8094/index.php';
$db = Database::getInstance()->getConnection();
$idEmpresa = (int) $db->query('SELECT MIN(id_empresa) FROM empresa')->fetchColumn();
$stmt = $db->prepare("SELECT nombre_usuario FROM usuario WHERE id_empresa = :empresa AND rol = 'direccion' AND nombre_usuario LIKE 'direccion_piloto_%' ORDER BY id_usuario LIMIT 1");
$stmt->execute([':empresa' => $idEmpresa]);
$usuario = (string) $stmt->fetchColumn();
if ($usuario === '') {
    fwrite(STDERR, "Falta la dirección sintética creada por carga_piloto.php.\n");
    exit(1);
}

function httpFase7(string $url, string $cookie, ?array $post = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 30,
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $respuesta = (string) curl_exec($ch);
    $estado = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cabeceraBytes = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $tiempoMs = (float) curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000;
    $error = curl_error($ch);
    curl_close($ch);
    if ($respuesta === '' && $error !== '') throw new RuntimeException($error);
    return [
        'estado' => $estado,
        'cabeceras' => substr($respuesta, 0, $cabeceraBytes),
        'cuerpo' => substr($respuesta, $cabeceraBytes),
        'tiempo_ms' => $tiempoMs,
    ];
}

function csrfFase7(string $cuerpo): string
{
    return preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $cuerpo, $m) ? $m[1] : '';
}

$cookie = tempnam(sys_get_temp_dir(), 'f7http');
try {
    $login = httpFase7($base . '?action=login', $cookie);
    if ($login['estado'] !== 200 || stripos($login['cabeceras'], 'X-App-Environment: test') === false) {
        throw new RuntimeException('El servidor no acredita APP_ENV=test.');
    }
    $csrf = csrfFase7($login['cuerpo']);
    $nivel1 = httpFase7($base . '?action=autenticar_gimnasio', $cookie, [
        '_csrf' => $csrf,
        'email' => 'cleto.reyes.villaviciosa@gmail.com',
        'contrasena' => 'cleto2026',
    ]);
    if ($nivel1['estado'] !== 302) throw new RuntimeException('Falló el acceso del gimnasio sintético.');

    $loginEmpleado = httpFase7($base . '?action=login_gimnasio', $cookie);
    $csrf = csrfFase7($loginEmpleado['cuerpo']);
    $nivel2 = httpFase7($base . '?action=autenticar', $cookie, [
        '_csrf' => $csrf,
        'usuario' => $usuario,
        'contrasena' => 'admin123',
    ]);
    if ($nivel2['estado'] !== 302) throw new RuntimeException('Falló el acceso de dirección sintético.');

    $medir = static function (string $nombre, array $params) use ($base, $cookie): array {
        $url = $base . '?' . http_build_query(array_merge(['action' => 'admin_socios'], $params));
        httpFase7($url, $cookie);
        $tiempos = $bytes = [];
        $filas = 0;
        $cuerpo = '';
        for ($i = 0; $i < 9; $i++) {
            $r = httpFase7($url, $cookie);
            if ($r['estado'] !== 200) throw new RuntimeException("HTTP {$r['estado']} en {$nombre}");
            $tiempos[] = $r['tiempo_ms'];
            $bytes[] = strlen($r['cuerpo']);
            $cuerpo = $r['cuerpo'];
            $filas = substr_count($cuerpo, '<tr class="border-t');
        }
        sort($tiempos, SORT_NUMERIC);
        sort($bytes, SORT_NUMERIC);
        return [
            'escenario' => $nombre,
            'filas_html' => $filas,
            'p50_ms' => $tiempos[4],
            'p95_ms' => $tiempos[8],
            'bytes_p50' => $bytes[4],
            'pagina_50' => strpos($cuerpo, 'Mostrando 1–50') !== false,
        ];
    };

    $mediciones = [
        $medir('Ámbito con 5.000 socios', []),
        $medir('Búsqueda con 50 resultados', ['buscar' => 'F7V050']),
        $medir('Búsqueda con 500 resultados', ['buscar' => 'F7V500']),
        $medir('Búsqueda exacta', ['buscar' => 'fase7.volumen.01001@test.invalid']),
        $medir('Búsqueda amplia', ['buscar' => 'F7V']),
    ];

    printf("%-34s %8s %11s %11s %12s %10s\n", 'ESCENARIO', 'FILAS', 'P50 ms', 'P95 ms', 'BYTES P50', 'PÁGINA 50');
    foreach ($mediciones as $m) {
        printf(
            "%-34s %8d %11.2f %11.2f %12d %10s\n",
            $m['escenario'], $m['filas_html'], $m['p50_ms'], $m['p95_ms'], $m['bytes_p50'], $m['pagina_50'] ? 'sí' : 'no'
        );
    }
    echo PHP_EOL . json_encode($mediciones, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Benchmark HTTP cancelado: ' . $e->getMessage() . PHP_EOL);
    @unlink($cookie);
    exit(1);
}

@unlink($cookie);
