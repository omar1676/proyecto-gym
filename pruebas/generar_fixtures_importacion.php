<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$dir = __DIR__ . '/fixtures/importaciones';
if (!is_dir($dir) && !mkdir($dir, 0750, true)) {
    fwrite(STDERR, "No se pudo crear el directorio de fixtures.\n"); exit(1);
}

function dniFor(int $number): string {
    $letters = 'TRWAGMYFPDXBNJZSQVHLCKE';
    $digits = str_pad((string) $number, 8, '0', STR_PAD_LEFT);
    return $digits[$number < 0 ? 0 : 0] !== '' ? $digits . $letters[$number % 23] : '';
}

function csvFile(string $path, array $headers, iterable $rows, string $delimiter = ',', bool $bom = false): int {
    $handle = fopen($path, 'wb');
    if (!$handle) throw new RuntimeException('No se pudo escribir ' . $path);
    if ($bom) fwrite($handle, "\xEF\xBB\xBF");
    fputcsv($handle, $headers, $delimiter, '"', '\\');
    $count = 0;
    foreach ($rows as $row) {
        fputcsv($handle, $row, $delimiter, '"', '\\');
        $count++;
        if ($count === 50 && str_contains($path, 'socios_100.csv')) fwrite($handle, "\n");
    }
    fclose($handle);
    return $count;
}

function members(int $count, int $start, string $prefix): Generator {
    $names = ['Álvaro','Lucía','Iñigo','María José','Óscar','Nuria','Raúl','Sofía'];
    $surnames = ['Muñoz García','Pérez Núñez','Martín Sáez','Gómez León'];
    for ($i = 1; $i <= $count; $i++) {
        $number = $start + $i;
        $localPhone = sprintf('6%08d', $i);
        yield [
            sprintf('%s-%05d', $prefix, $i),
            $names[$i % count($names)],
            $surnames[$i % count($surnames)],
            dniFor($number),
            sprintf('%s.%05d@example.invalid', strtolower($prefix), $i),
            $i % 2 ? '+34 ' . $localPhone : substr($localPhone,0,3).'-'.substr($localPhone,3,3).'-'.substr($localPhone,6,3),
            sprintf('2026-%02d-%02d', (($i - 1) % 12) + 1, (($i - 1) % 28) + 1),
            $i % 11 === 0 ? 'inactivo' : 'activo',
            'Sede sintética',
            '999999',
        ];
    }
}

$memberHeaders = ['external_id','nombre','apellidos','dni','email','telefono','fecha_alta','estado','sede_externa','empresa_id'];
$written = [];
$written['socios_100.csv'] = csvFile($dir . '/socios_100.csv', $memberHeaders, members(100, 50000000, 'S100'), ';', true);
$written['socios_5000.csv'] = csvFile($dir . '/socios_5000.csv', $memberHeaders, members(5000, 60000000, 'S5000'));

$errors = [
    ['ERR-1','Nombre','Apellidos','1234','correo-invalido','abc','03/04/2026','desconocido','Sede sintética','2'],
    ['ERR-2','Lucía','',dniFor(71000002),'fase8.error2@example.invalid','600 100 200','2026-02-30','activo','Sede sintética','2'],
    ['ERR-3','José','Núñez',dniFor(71000003),'fase8.error3@example.invalid','+34 600 100 201','2026-04-03','activo','Sede sintética','2'],
];
$written['socios_errores.csv'] = csvFile($dir . '/socios_errores.csv', $memberHeaders, $errors, ';', true);

$duplicateDni = dniFor(72000001);
$duplicates = [
    ['DUP-1','Ana','Prueba',$duplicateDni,'fase8.dup1@example.invalid','600111001','2026-01-01','activo','Sede sintética','1'],
    ['DUP-1','Ana','Prueba',dniFor(72000002),'fase8.dup2@example.invalid','600111002','2026-01-01','activo','Sede sintética','1'],
    ['DUP-3','Otra','Persona',$duplicateDni,'fase8.dup3@example.invalid','600111003','2026-01-01','activo','Sede sintética','1'],
    ['DUP-4','Teléfono','Repetido',dniFor(72000004),'fase8.dup4@example.invalid','600111001','2026-01-01','activo','Sede sintética','1'],
];
$written['socios_duplicados.csv'] = csvFile($dir . '/socios_duplicados.csv', $memberHeaders, $duplicates);

$products = (function (): Generator {
    for ($i = 1; $i <= 500; $i++) {
        yield [sprintf('PROD-%04d',$i),'Producto sintético '.$i,$i % 3 === 0 ? 'Categoría no mapeada' : ($i % 2 ? 'Bebidas' : 'Nutrición'),
            number_format(1 + ($i % 100) + (($i * 7) % 100) / 100, 2, '.', ''),$i % 250,$i % 17 === 0 ? 'inactivo' : 'activo','Descripción sintética con ñ '.$i,'123'];
    }
})();
$written['productos_500.csv'] = csvFile($dir . '/productos_500.csv',
    ['external_id','nombre','categoria','precio','stock','estado','descripcion','sede_id'],$products,';',true);

$memberships = [
    ['MEM-0001','S100-00001','Tarifa migración','2026-01-01','2026-01-31','40.00','vencida'],
    ['MEM-0002','S100-00002','Tarifa migración','2026-02-01','2026-02-28','40.00','vencida'],
    ['MEM-0003','S100-00003','Tarifa migración','2026-08-01','2026-08-31','40.00','activa'],
];
$written['membresias_dry_run.csv'] = csvFile($dir . '/membresias_dry_run.csv',
    ['external_id','socio_external_id','tarifa','fecha_inicio','fecha_fin','precio_historico','estado'],$memberships);

foreach ($written as $file => $rows) echo $file . ': ' . $rows . " filas\n";
