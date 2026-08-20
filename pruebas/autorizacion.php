<?php
/** Pruebas unitarias de la matriz y de propiedad horizontal. */

require_once __DIR__ . '/_arranque.php';
require_once dirname(__DIR__) . '/app/helpers/Authorization.php';

$ok = 0; $fallos = 0;
function comprobarAutorizacion(string $descripcion, bool $condicion): void {
    global $ok, $fallos;
    if ($condicion) { $ok++; echo "  OK   {$descripcion}\n"; }
    else { $fallos++; echo "  FALLO {$descripcion}\n"; }
}

echo "== MATRIZ DE PERMISOS ==\n";
comprobarAutorizacion('superadmin puede administrar sedes', Authorization::can('superadmin', 'sedes.manage'));
comprobarAutorizacion('dirección puede administrar sedes', Authorization::can('direccion', 'sedes.manage'));
comprobarAutorizacion('admin no administra sedes', !Authorization::can('admin', 'sedes.manage'));
comprobarAutorizacion('admin puede gestionar empleados de su ámbito', Authorization::can('admin', 'empleados.manage'));
comprobarAutorizacion('recepción puede registrar ventas', Authorization::can('recepcion', 'ventas.create'));
comprobarAutorizacion('recepción no puede anular ventas', !Authorization::can('recepcion', 'ventas.cancel'));
comprobarAutorizacion('recepción no modifica stock', !Authorization::can('recepcion', 'stock.manage'));
comprobarAutorizacion('recepción no consulta informes', !Authorization::can('recepcion', 'informes.view'));
comprobarAutorizacion('recepción no gestiona personal', !Authorization::can('recepcion', 'empleados.manage'));
comprobarAutorizacion('recepción no consulta auditoría', !Authorization::can('recepcion', 'auditoria.view'));
comprobarAutorizacion('recepción no genera remesas', !Authorization::can('recepcion', 'remesas.manage'));
comprobarAutorizacion('recepción puede operar caja de su sede', Authorization::can('recepcion', 'caja.operate'));
comprobarAutorizacion('recepción no hace ajustes manuales de caja', !Authorization::can('recepcion', 'caja.adjust'));
comprobarAutorizacion('admin puede ajustar caja', Authorization::can('admin', 'caja.adjust'));

echo "\n== PROPIEDAD DEL SOCIO ==\n";
comprobarAutorizacion('socio puede consultar su propio recurso', Authorization::canOwn('socio', 'propio.view', 100, 100));
comprobarAutorizacion('socio 100 no consulta recurso de socio 101', !Authorization::canOwn('socio', 'propio.view', 100, 101));
comprobarAutorizacion('un id cero nunca se considera propio', !Authorization::canOwn('socio', 'propio.view', 0, 0));

echo "\n== RESUMEN: {$ok} correctas, {$fallos} fallidas ==\n";
exit($fallos > 0 ? 1 : 0);
