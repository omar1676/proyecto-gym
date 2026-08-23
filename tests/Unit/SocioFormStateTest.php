<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/helpers/SocioFormState.php';
require_once dirname(__DIR__, 2) . '/app/helpers/SocioProfileValidator.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$_SESSION = [];

$validated = SocioProfileValidator::validate([
    'nombre'=>' Ana ', 'apellidos'=>' Prueba ', 'dni'=>'12 345 678-z',
    'email'=>' ANA@EXAMPLE.INVALID ', 'telefono'=>'+34 600 123 123',
    'iban'=>'es91 2100 0418 4502 0005 1332',
]);
check('validador compartido normaliza perfil completo', $validated['errors'] === []
    && $validated['values']['dni'] === '12345678Z'
    && $validated['values']['email'] === 'ana@example.invalid'
    && $validated['values']['iban'] === 'ES9121000418450200051332');

$invalid = SocioProfileValidator::validate([
    'nombre'=>'Ana','apellidos'=>'Prueba','dni'=>'12345678A','email'=>'mal@',
    'telefono'=>'abc','iban'=>'ES001234',
]);
check('validador devuelve errores por campo', array_keys($invalid['errors']) === ['dni','email','telefono','iban']);

SocioFormState::put('alta', [
    'nombre'=>'Ana','dni'=>'12345678A','email'=>'ana@example.invalid',
    'contrasena'=>'NO-DEBE-GUARDARSE','_operation_id'=>'NO-DEBE-GUARDARSE',
], ['dni'=>'DNI no válido','contrasena'=>'Vuelve a escribirla'], 'Revisa', 10, 20, 30);
$state = SocioFormState::consume(10, 20, 30);
check('flash conserva solo campos permitidos', $state !== null && $state['values']['nombre'] === 'Ana' && $state['values']['dni'] === '12345678A');
check('flash nunca conserva contraseña ni operación', !isset($state['values']['contrasena'], $state['values']['_operation_id']));
check('flash se consume una sola vez', SocioFormState::consume(10, 20, 30) === null);

SocioFormState::put('alta', ['nombre'=>'Tenant A'], [], 'x', 10, 20, 30);
check('flash no cruza sede/tenant', SocioFormState::consume(10, 21, 30) === null);

finishTests();
