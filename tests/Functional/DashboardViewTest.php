<?php
require_once dirname(__DIR__) . '/bootstrap.php';

$sesionesTmp = dirname(__DIR__, 2) . '/pruebas/sesiones_tmp';
if (!is_dir($sesionesTmp)) {
    mkdir($sesionesTmp, 0700, true);
}
session_save_path($sesionesTmp);
session_start();

$db = Database::getInstance()->getConnection();
$db->beginTransaction();
try {
    $db->exec(
        "INSERT INTO venta
         (serie, ejercicio, numero, id_socio, id_usuario_registro, id_gimnasio,
          metodo_pago, total, base_imponible, total_iva, estado, anulada_en,
          motivo_anulacion, fecha)
         VALUES ('UX', YEAR(CURDATE()), 1, NULL, 1, 1, 'efectivo', 1.00,
                 0.83, 0.17, 'anulada', NOW(), 'Prueba de render', NOW())"
    );
    $idVenta = (int) $db->lastInsertId();
    $stmt = $db->prepare(
        "INSERT INTO venta_linea
         (id_venta, id_producto, nombre_producto, cantidad, precio_unitario,
          iva, subtotal, base_linea, cuota_iva)
         VALUES (:venta, 1, 'Agua', 1, 1.00, 21.00, 1.00, 0.83, 0.17)"
    );
    $stmt->execute([':venta' => $idVenta]);

    $_SESSION['logueado'] = true;
    $_SESSION['usuario_id'] = 1;
    $_SESSION['usuario_rol'] = 'admin';
    $_SESSION['gimnasio_auth_id'] = 1;
    $_SESSION['usuario_nombre'] = 'daniel';
    $_SESSION['usuario_nombre_real'] = 'Daniel Admin';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_GET = ['action' => 'admin'];

    require_once dirname(__DIR__, 2) . '/app/controllers/AdminController.php';
    $ctrl = new AdminController();
    ob_start();
    $ctrl->mostrarInicio();
    $html = ob_get_clean();

    check('el panel identifica una venta anulada en las últimas ventas', strpos($html, '— Anulada') !== false);
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}
finishTests();
