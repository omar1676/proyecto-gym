<?php
/**
 * tareas.php — lo que el gimnasio necesita que pase solo, todos los días.
 *
 *   php cron/tareas.php            todo
 *   php cron/tareas.php renovar    solo las renovaciones
 *   php cron/tareas.php avisar     solo los avisos de vencimiento
 *   php cron/tareas.php remesa     solo la remesa del mes
 *   php cron/tareas.php --simular  enseña lo que haría, sin tocar nada
 *
 * Cron sugerido (todos los días a las 6:00):
 *   0 6 * * * /usr/bin/php /ruta/al/proyecto/cron/tareas.php >> /ruta/logs/cron.log 2>&1
 *
 * Por qué existe: sin esto, cobrar la cuota del mes siguiente significaba
 * entrar socio por socio a renovar a mano. Con 300 socios eso son dos tardes
 * al mes y, el día que a alguien se le pasa, ese socio deja de pagar sin que
 * nadie se entere.
 *
 * Las tres tareas se hacen sede por sede: cada gimnasio cobra con SUS datos
 * bancarios y avisa con SU marca.
 */

require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/models/GimnasioModel.php';
require_once __DIR__ . '/../app/models/MembresiaModel.php';
require_once __DIR__ . '/../app/models/SepaModel.php';
require_once __DIR__ . '/../app/models/LogModel.php';
require_once __DIR__ . '/../app/helpers/Mailer.php';
require_once __DIR__ . '/../app/helpers/RequestContext.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Solo por línea de comandos.\n");
}
RequestContext::bootstrap('CRON');

/** Día del mes en que se prepara la remesa de las cuotas domiciliadas. */
const DIA_REMESA = 1;

/** Días de antelación con que se avisa al socio de que su cuota vence. */
const DIAS_AVISO = 7;

$argumentos = array_slice($argv, 1);
$simulacionStaging = APP_ENV === 'staging';
$simular    = $simulacionStaging || in_array('--simular', $argumentos, true);
$tareas     = array_values(array_filter($argumentos, function ($a) { return substr($a, 0, 2) !== '--'; }));
if (empty($tareas)) {
    $tareas = ['renovar', 'avisar', 'remesa'];
}

$log = new LogModel();
if ($simulacionStaging) {
    registrar('STAGING: simulación económica forzada; no se renovará, enviará correo ni creará remesa.');
}
registrar('Tareas automáticas · ' . date('d/m/Y H:i') . ($simular ? ' (SIMULACIÓN)' : ''));

$sedes = (new GimnasioModel())->listarActivas();
if (empty($sedes)) {
    registrar('No hay sedes activas. Nada que hacer.');
    exit(0);
}

$resumen = ['renovadas' => 0, 'avisos' => 0, 'remesas' => 0, 'errores' => 0];

foreach ($sedes as $sede) {
    $idSede = (int) $sede['id_gimnasio'];
    registrar('');
    registrar('── ' . $sede['nombre']);

    if (in_array('renovar', $tareas, true)) renovarCuotas($idSede, $simular, $resumen, $log);
    if (in_array('avisar',  $tareas, true)) avisarVencimientos($idSede, $simular, $resumen);
    if (in_array('remesa',  $tareas, true)) prepararRemesa($idSede, $simular, $resumen, $log);
}

registrar('');
registrar(sprintf(
    'Total: %d renovaciones, %d avisos, %d remesas, %d errores.',
    $resumen['renovadas'], $resumen['avisos'], $resumen['remesas'], $resumen['errores']
));

exit($resumen['errores'] > 0 ? 1 : 0);

/* ========================================================================= */

/**
 * Renueva las cuotas domiciliadas que están a punto de vencer.
 *
 * Solo las domiciliadas: las de efectivo o datáfono hay que cobrarlas en el
 * mostrador, y renovarlas sin cobrar sería regalar el acceso. La cuota nueva
 * encadena a partir del día siguiente al vencimiento, así que el socio no
 * pierde ni un día ni se le regala ninguno.
 */
function renovarCuotas(int $idSede, bool $simular, array &$resumen, LogModel $log): void
{
    $membresias = new MembresiaModel($idSede);
    $pendientes = $membresias->listarParaRenovar(3);

    if (empty($pendientes)) {
        registrar('   Renovaciones: ninguna pendiente.');
        return;
    }

    foreach ($pendientes as $cuota) {
        $socio  = trim($cuota['nombre'] . ' ' . $cuota['apellidos']);
        $importe = (float) $cuota['precio_pagado'] + (float) $cuota['precio_suplemento'];

        if ($simular) {
            registrar(sprintf('   [simulado] renovaría %s — %s (%s €)',
                $socio, $cuota['nombre_tipo'], number_format($importe, 2, ',', '.')));
            $resumen['renovadas']++;
            continue;
        }

        $error = '';
        $idNuevo = $membresias->contratar(
            (int) $cuota['id_socio'],
            (int) $cuota['id_tipo_membresia'],
            $cuota['metodo_pago'],
            $error,
            $cuota['id_suplemento'] ? (int) $cuota['id_suplemento'] : null,
            'automatica',
            'auto-renovacion:' . (int) $cuota['id_socio_membresia']
        );

        if ($idNuevo === null) {
            registrar('   ERROR renovando a ' . $socio . ': ' . $error);
            $resumen['errores']++;
            continue;
        }

        $nueva = $membresias->vigenteDeSocio((int) $cuota['id_socio']);
        registrar(sprintf('   Renovado %s — %s hasta %s (%s €)',
            $socio, $cuota['nombre_tipo'],
            date('d/m/Y', strtotime($nueva['fecha_fin'] ?? '')),
            number_format($importe, 2, ',', '.')));

        // El socio se entera ANTES de ver el cargo en su cuenta: es lo que
        // evita la mitad de las devoluciones de recibos.
        if (!empty($cuota['email'])) {
            Mailer::membresiaRenovada(
                $cuota['email'], $cuota['nombre'],
                $cuota['nombre_tipo'], $nueva['fecha_fin'] ?? '', $importe
            );
        }

        // Queda en el historial como cualquier otro movimiento, pero sin autor:
        // en el listado se lee como "sistema" y no se confunde con una persona.
        $log->registrarCambio(
            null, 'Renovación automática',
            $socio . ' — ' . $cuota['nombre_tipo'],
            (int) $cuota['id_socio'], 'socio', (int) $cuota['id_socio'],
            $cuota['fecha_fin'], $nueva['fecha_fin'] ?? '', $idSede
        );

        $resumen['renovadas']++;
    }
}

/**
 * Avisa por correo a quien le vence la cuota en DIAS_AVISO días.
 *
 * Se avisa solo a los que NO se renuevan solos: al domiciliado ya le llega el
 * correo de renovación, y recibir los dos mensajes confunde.
 */
function avisarVencimientos(int $idSede, bool $simular, array &$resumen): void
{
    $membresias = new MembresiaModel($idSede);
    $proximas   = $membresias->listarProximasAVencer(DIAS_AVISO);
    $avisados   = 0;

    foreach ($proximas as $cuota) {
        if ((int) ($cuota['dias_restantes'] ?? -1) !== DIAS_AVISO) {
            continue;   // solo el día exacto: si no, se avisa siete veces
        }
        if (empty($cuota['email'])) {
            continue;
        }

        if ($simular) {
            registrar('   [simulado] avisaría a ' . trim($cuota['nombre'] . ' ' . $cuota['apellidos']));
            $avisados++;
            continue;
        }

        if (Mailer::membresiaPorVencer($cuota['email'], $cuota['nombre'], $cuota['nombre_tipo'], $cuota['fecha_fin'])) {
            $avisados++;
        } else {
            $resumen['errores']++;
        }
    }

    registrar('   Avisos de vencimiento: ' . $avisados);
    $resumen['avisos'] += $avisados;
}

/**
 * Prepara la remesa del mes con los recibos domiciliados pendientes.
 *
 * Se CREA, no se envía: el fichero lo descarga una persona y lo sube a la
 * banca electrónica. Que un programa mande dinero al banco sin que nadie lo
 * mire es justo lo que no se debe hacer.
 */
function prepararRemesa(int $idSede, bool $simular, array &$resumen, LogModel $log): void
{
    if ((int) date('j') !== DIA_REMESA && !$simular) {
        return;   // la remesa se prepara una vez al mes
    }

    $sepa = new SepaModel($idSede);

    if (!$sepa->acreedorCompleto()) {
        registrar('   Remesa: faltan los datos bancarios de la sede. No se crea.');
        return;
    }

    $pendientes = $sepa->listarDomiciliablesPendientes();
    if (empty($pendientes)) {
        registrar('   Remesa: no hay recibos pendientes.');
        return;
    }

    $importe = array_sum(array_map(function ($p) { return (float) $p['importe']; }, $pendientes));

    if ($simular) {
        registrar(sprintf('   [simulado] crearía remesa de %d recibos (%s €)',
            count($pendientes), number_format($importe, 2, ',', '.')));
        $resumen['remesas']++;
        return;
    }

    $error    = '';
    $idRemesa = $sepa->crearRemesa(
        array_column($pendientes, 'id_socio_membresia'),
        'Cuota ' . date('m/Y'),
        date('Y-m-d', strtotime('+3 days')),
        null,
        $error
    );

    if ($idRemesa === null) {
        registrar('   ERROR creando la remesa: ' . $error);
        $resumen['errores']++;
        return;
    }

    registrar(sprintf('   Remesa #%d creada: %d recibos, %s €. Falta descargarla y subirla al banco.',
        $idRemesa, count($pendientes), number_format($importe, 2, ',', '.')));

    $log->registrarCambio(
        0, 'Remesa automática',
        'Remesa #' . $idRemesa . ' — ' . count($pendientes) . ' recibos, '
            . number_format($importe, 2, ',', '.') . ' €',
        null, null, null, null, null, $idSede
    );

    $resumen['remesas']++;
}

function registrar(string $linea): void
{
    echo $linea . "\n";
}
