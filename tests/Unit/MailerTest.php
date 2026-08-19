<?php
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/helpers/Mailer.php';

$inicio = hrtime(true);
$resultado = Mailer::enviar(
    'destino@test.invalid',
    'Correo sintético',
    '<p>No debe salir del entorno de pruebas.</p>'
);
$milisegundos = (hrtime(true) - $inicio) / 1_000_000;

check('el correo sintético se acepta en APP_ENV=test', $resultado === true);
check('APP_ENV=test no espera una conexión de correo', $milisegundos < 100.0);
finishTests();
