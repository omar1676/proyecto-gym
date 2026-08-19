<?php
/**
 * AdminController — panel de gestión del gimnasio.
 *
 * Secciones de este archivo (en orden):
 *   1. Inicialización y autorización       (__construct, requirePermission, iniciarModelos)
 *   2. Inicio del panel                    (mostrarInicio)
 *   3. Productos y stock                   (mostrarProductos, subirImagenProducto, quitarImagenProducto)
 *   4. Ventas                              (mostrarVentas, registrarVenta, anularVenta)
 *   5. Socios y membresías                 (mostrarSocios, registrarSocio, contratarMembresia, mostrarMembresias)
 *   6. Reportes                            (mostrarReportes)
 *   7. Sedes y personal                     (mostrarSedes, mostrarEmpleados)
 *   8. Domiciliación SEPA                   (mostrarRemesas, crearMandato)
 *   9. Reportes, log y exportación          (mostrarReportes, mostrarLog, exportarVentasCSV)
 *
 * Cada acción declara un permiso de Authorization; el rol nunca llega del formulario.
 *
 * Todos los POST que mueven dinero o stock validan CSRF con Csrf::validarPost().
 * Las rutas del router se definen en public/index.php.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/LogModel.php';
require_once __DIR__ . '/../models/UserModel.php';
require_once __DIR__ . '/../models/ProductoModel.php';
require_once __DIR__ . '/../models/VentaModel.php';
require_once __DIR__ . '/../models/MembresiaModel.php';
require_once __DIR__ . '/../models/SepaModel.php';
require_once __DIR__ . '/../models/GimnasioModel.php';
require_once __DIR__ . '/../helpers/SepaXml.php';
require_once __DIR__ . '/../helpers/Mailer.php';
require_once __DIR__ . '/../helpers/Csrf.php';
require_once __DIR__ . '/../helpers/Iban.php';
require_once __DIR__ . '/../helpers/Sesion.php';
require_once __DIR__ . '/../helpers/TenantContext.php';
require_once __DIR__ . '/../helpers/Authorization.php';
require_once __DIR__ . '/../helpers/AppLogger.php';
require_once __DIR__ . '/../helpers/InputValidator.php';
require_once __DIR__ . '/../services/MigrationService.php';

class AdminController
{
    private $userModel;
    private $tenant;
    /**
     * Longitud mínima de la clave de un empleado.
     *
     * Es corta a propósito: para entrar hay que superar antes el acceso del
     * gimnasio (email + contraseña), así que esta segunda clave identifica a la
     * persona dentro de un local ya autenticado, como el PIN de un TPV. Se
     * teclea muchas veces al día y una clave larga acabaría en un papel pegado
     * al monitor, que es peor.
     */
    private const MIN_CLAVE_EMPLEADO = 4;

    private $productoModel;
    private $ventaModel;
    private $membresiaModel;
    private $sepaModel;
    private $migrationService;

    public function __construct()
    {
        Sesion::iniciar();
        $this->tenant = TenantContext::desdeSesion();
    }

    private function requirePermission(string $permiso): void
    {
        if (!$this->tenant->autenticado()) {
            $this->irA('login');
        }
        // Ninguna pantalla de negocio puede arrancar modelos sin empresa: un
        // contexto incompleto convertiría null en una consulta sin filtro.
        if ($this->tenant->empresaId() === null) {
            http_response_code(403);
            exit('No hay una empresa autorizada para esta sesión.');
        }
        if (!Authorization::can($this->tenant->rol(), $permiso)) {
            AppLogger::write('SECURITY', 'authorization_denied', [
                'user_id' => $this->tenant->usuarioId(), 'role' => $this->tenant->rol(),
                'company_id' => $this->tenant->empresaId(), 'site_id' => $this->tenant->sedeId(),
                'permission' => $permiso,
            ]);
            http_response_code(403);
            exit('No tienes permiso para realizar esta operación.');
        }

        $this->iniciarModelos();
    }

    /**
     * Sede activa de la sesión. null = ver todas, y solo lo tiene el
     * empresa: es lo que los modelos entienden como "sin filtro".
     */
    private function gimnasioActual(): ?int
    {
        return $this->tenant->sedeId();
    }

    /**
     * Redirige a una pantalla del panel y corta la ejecución.
     *
     * Todas las acciones que guardan algo terminan igual: cabecera Location y
     * exit. Tenerlo en un sitio evita que a alguna se le olvide el exit, que es
     * el fallo clásico: el navegador se va, pero el código sigue corriendo.
     *
     * Los parámetros se codifican aquí, así que los mensajes pueden llevar
     * tildes, comas o el nombre de un socio sin romper la URL.
     */
    private function irA(string $accion, array $parametros = []): void
    {
        $url = APP_URL . '/index.php?action=' . $accion;
        if ($parametros) {
            $url .= '&' . http_build_query($parametros);
        }
        header('Location: ' . $url);
        exit;
    }

    /** Atajo para el caso más repetido: volver a una pantalla con un error. */
    private function irAConError(string $accion, string $mensaje, array $parametros = []): void
    {
        $this->irA($accion, array_merge($parametros, ['err' => $mensaje]));
    }

    /**
     * Recupera solo estado de navegación del listado de socios.
     * No contiene empresa ni sede: esos límites proceden siempre de la sesión.
     */
    private function navegacionSocios(array $origen): array
    {
        $busqueda = InputValidator::text($origen['volver_buscar'] ?? '', 100, false);
        $pagina = filter_var(
            $origen['volver_pagina'] ?? 1,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        $parametros = [];
        if ($busqueda !== null && $busqueda !== '') $parametros['buscar'] = $busqueda;
        if ($pagina !== false && (int) $pagina > 1) $parametros['pagina'] = (int) $pagina;
        return $parametros;
    }

    /**
     * Texto único para cuando falta elegir sede: se usa en todos los avisos.
     */
    private const AVISO_SIN_SEDE = 'Elige primero una sede concreta en el selector de la cabecera: lo que se dé de alta tiene que quedar guardado en alguna.';

    /**
     * Corta la acción si la empresa está trabajando con "todas las sedes".
     *
     * Las altas guardan la sede activa, y sin ninguna fijada la fila nacía con
     * id_gimnasio NULL: como todos los listados filtran por sede, esa venta o
     * esa membresía dejaba de existir para todo el mundo, incluida la caja del
     * día y los informes.
     */
    private function exigirSedeFijada(string $accionVuelta, array $parametros = []): void
    {
        if ($this->gimnasioActual() !== null) {
            return;
        }
        $this->irAConError($accionVuelta, self::AVISO_SIN_SEDE, $parametros);
    }

    private function iniciarModelos(): void
    {
        if (!isset($this->userModel)) {
            $sede = $this->gimnasioActual();
            $empresa = $this->tenant->empresaId();

            // Todos los modelos del gimnasio quedan atados a la sede: el filtro
            // se aplica dentro y no depende de que cada consulta se acuerde.
            $this->userModel        = new UserModel($sede, $empresa);
            $this->productoModel    = new ProductoModel($sede, $empresa);
            $this->ventaModel       = new VentaModel($sede, $empresa);
            $this->membresiaModel   = new MembresiaModel($sede, $empresa);
            $this->sepaModel        = new SepaModel($sede, $empresa);
        }
    }

    private function migraciones(): MigrationService
    {
        if (!$this->migrationService) {
            $this->migrationService = new MigrationService(
                (int) $this->tenant->empresaId(),
                $this->tenant->sedeId(),
                $this->tenant->usuarioId()
            );
        }
        return $this->migrationService;
    }

    public function mostrarInicio(): void
    {
        $this->requirePermission('dashboard.view');
        $pageTitle    = 'Panel de Control';
        $paginaActiva = 'inicio';

        $totalSocios       = $this->userModel->contarPorRol('socio');
        $membresiasActivas = $this->membresiaModel->contarActivas();
        $ventasHoy         = $this->ventaModel->sumarDelDia();
        $numVentasHoy      = $this->ventaModel->contarDelDia();
        $ventasMes         = $this->ventaModel->sumarDelMes();
        $bajoStock         = $this->productoModel->contarBajoStock();
        $porVencer         = $this->membresiaModel->listarProximasAVencer(15);
        $ultimasVentas     = array_slice($this->ventaModel->listarDelDia(), 0, 5);

        require __DIR__ . '/../views/admin/inicio.php';
    }

    private function procesarSubidaImagen($file, string $carpeta, string $prefijo, string &$error): ?string
    {
        if (empty($file) || empty($file['name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if (($file['error'] ?? 0) !== UPLOAD_ERR_OK) {
            $error = 'Error al subir la imagen. Inténtalo de nuevo.';
            return null;
        }
        $extensiones = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $tipoReal = $finfo->file($file['tmp_name']);
        if (!isset($extensiones[$tipoReal])) {
            $error = 'La imagen debe ser JPG, PNG, GIF o WEBP.';
            return null;
        }
        if ($file['size'] > 2 * 1024 * 1024) {
            $error = 'La imagen no puede superar 2 MB.';
            return null;
        }
        $dimensiones = @getimagesize($file['tmp_name']);
        if (!$dimensiones || $dimensiones[0] < 1 || $dimensiones[1] < 1
            || $dimensiones[0] > 6000 || $dimensiones[1] > 6000
            || ($dimensiones[0] * $dimensiones[1]) > 16000000) {
            $error = 'Las dimensiones de la imagen no son válidas o son demasiado grandes.';
            return null;
        }

        if (!in_array($carpeta, ['productos', 'gimnasios'], true)) {
            $error = 'Destino de imagen no permitido.';
            return null;
        }
        $dirDestino = __DIR__ . '/../../public/assets/' . $carpeta . '/';
        if (!is_dir($dirDestino)) {
            @mkdir($dirDestino, 0755, true);
        }
        if (!is_dir($dirDestino) || !is_writable($dirDestino)) {
            $error = 'No se pudo guardar la imagen: la carpeta ' . htmlspecialchars($carpeta) . ' no existe o no tiene permisos de escritura.';
            return null;
        }

        $nombre = preg_replace('/[^a-z0-9_-]/i', '', $prefijo) . '_' . bin2hex(random_bytes(16)) . '.' . $extensiones[$tipoReal];

        if (!move_uploaded_file($file['tmp_name'], $dirDestino . $nombre)) {
            $error = 'No se pudo guardar la imagen en el servidor.';
            return null;
        }
        return $nombre;
    }

    /* ---------------------------------------------------------------------
     * Productos y stock
     * ------------------------------------------------------------------ */

    public function mostrarProductos(): void
    {
        $this->requirePermission('productos.manage');
        $pageTitle    = 'Gestión de Productos';
        $paginaActiva = 'productos';

        $mensajeExito   = '';
        $errorProducto  = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!Csrf::validarPost()) {
                $errorProducto = 'La sesión ha caducado. Vuelve a intentarlo.';
            } else {
                $accion = $_POST['accion'] ?? '';

                if ($accion === 'toggle_estado_producto') {
                    $idProducto = (int) ($_POST['id_producto'] ?? 0);
                    if ($idProducto > 0 && $this->productoModel->toggleEstado($idProducto)) {
                        $this->registrarLog('Estado de producto', 'Activado/desactivado el producto #' . $idProducto);
                    }
                    $this->irA('admin_productos');
                }

                if ($accion === 'actualizar_stock') {
                    $idProducto = (int) ($_POST['id_producto'] ?? 0);
                    $stock      = (int) ($_POST['stock'] ?? 0);
                    if ($idProducto > 0 && $stock >= 0) {
                        $this->productoModel->actualizarStock($idProducto, $stock);
                        $this->registrarLog('Actualizar stock', 'Producto #' . $idProducto . ' → ' . $stock . ' uds.');
                    }
                    $this->irA('admin_productos', ['ok_stock' => 1]);
                }

                if ($accion === 'crear_producto' || $accion === 'editar_producto') {
                    $idProducto   = (int) ($_POST['id_producto'] ?? 0);
                    $nombre       = trim($_POST['nombre']      ?? '');
                    $descripcion  = trim($_POST['descripcion'] ?? '') ?: null;
                    $precio       = $_POST['precio']           ?? '';
                    $precioValido = InputValidator::money($precio);
                    $stock        = $_POST['stock']            ?? '';
                    $stockMinimo  = $_POST['stock_minimo']     ?? '';
                    $estado       = trim($_POST['estado']      ?? '');
                    $idCategoria  = (int) ($_POST['id_categoria'] ?? 0) ?: null;
                    // Tipo general salvo que se indique otro. El precio es PVP
                    // con IVA incluido, así que esto solo cambia el desglose.
                    $iva          = is_numeric($_POST['iva'] ?? null) ? (float) $_POST['iva'] : 21.0;
                    $iva          = max(0.0, min(100.0, $iva));

                    if ($accion === 'crear_producto' && $this->gimnasioActual() === null) {
                        // El producto es stock de un local concreto.
                        $errorProducto = self::AVISO_SIN_SEDE;
                    } elseif ($nombre === '') {
                        $errorProducto = 'El nombre del producto es obligatorio.';
                    } elseif ($precioValido === null) {
                        $errorProducto = 'El precio debe ser un número igual o mayor que 0.';
                    } elseif (!is_numeric($stockMinimo) || (int) $stockMinimo < 0) {
                        $errorProducto = 'El stock mínimo debe ser un número igual o mayor que 0.';
                    } elseif (!in_array($estado, ['activo', 'inactivo'], true)) {
                        $errorProducto = 'El estado es obligatorio.';
                    } elseif ($accion === 'crear_producto' && (!is_numeric($stock) || (int) $stock < 0)) {
                        $errorProducto = 'El stock inicial debe ser un número igual o mayor que 0.';
                    } else {
                        if ($accion === 'crear_producto') {
                            $ok = $this->productoModel->crear(
                                $nombre, $descripcion, (float) $precioValido,
                                (int) $stock, (int) $stockMinimo, $estado, $idCategoria, $iva
                            );
                            $flag = ['ok' => 1];
                            $detalleLog = 'Alta de producto: ' . $nombre;
                        } else {
                            $ok = $idProducto > 0 && $this->productoModel->actualizar(
                                $idProducto, $nombre, $descripcion, (float) $precioValido,
                                (int) $stockMinimo, $estado, $idCategoria, $iva
                            );
                            $flag = ['ok_editar' => 1];
                            $detalleLog = 'Edición de producto #' . $idProducto . ': ' . $nombre;
                        }

                        if ($ok) {
                            $this->registrarLog('Producto', $detalleLog);
                            $this->irA('admin_productos', $flag);
                        }
                        $errorProducto = 'No se pudo guardar el producto. Inténtalo de nuevo.';
                    }
                }
            }
        }

        if (isset($_GET['ok']))        $mensajeExito = 'Producto creado correctamente.';
        if (isset($_GET['ok_editar'])) $mensajeExito = 'Producto actualizado correctamente.';
        if (isset($_GET['ok_stock']))  $mensajeExito = 'Stock actualizado correctamente.';
        if (isset($_GET['ok_imagen'])) $mensajeExito = 'Imagen del producto actualizada correctamente.';
        if (isset($_GET['err_imagen'])) $errorProducto = $_GET['err_imagen'];

        $busqueda        = trim($_GET['buscar'] ?? '');
        $productos       = $this->productoModel->listarTodos($busqueda);
        $listaCategorias = $this->productoModel->listarCategorias();
        $totalProductos  = $this->productoModel->contarTodos();
        $productosActivos = $this->productoModel->contarActivos();
        $numBajoStock    = $this->productoModel->contarBajoStock();
        $valorInventario = $this->productoModel->valorInventario();

        require __DIR__ . '/../views/admin/productos.php';
    }

    public function subirImagenProducto(): void
    {
        $this->requirePermission('productos.manage');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Csrf::validarPost()) {
            $this->irA('admin_productos');
        }

        $idProducto = (int) ($_POST['id_producto'] ?? 0);
        $productoActual = $idProducto > 0 ? $this->productoModel->buscarPorId($idProducto) : null;
        if (!$productoActual) {
            $this->irA('admin_productos');
        }

        $error = '';
        $nombreImagen = $this->procesarSubidaImagen($_FILES['imagen'] ?? null, 'productos', 'producto', $error);

        if ($error === '' && $nombreImagen !== null) {
            if (!$this->productoModel->actualizarImagen($idProducto, $nombreImagen)) {
                @unlink(__DIR__ . '/../../public/assets/productos/' . $nombreImagen);
                $this->irA('admin_productos', ['err_imagen' => 'No se pudo asociar la imagen al producto.']);
            }

            if (!empty($productoActual['imagen'])) {
                $rutaAntigua = __DIR__ . '/../../public/assets/productos/' . $productoActual['imagen'];
                if (is_file($rutaAntigua)) @unlink($rutaAntigua);
            }

            $this->irA('admin_productos', ['ok_imagen' => 1]);
        }
        $this->irA('admin_productos', ['err_imagen' => $error]);
    }

    public function quitarImagenProducto(): void
    {
        $this->requirePermission('productos.manage');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Csrf::validarPost()) {
            $this->irA('admin_productos');
        }

        $idProducto = (int) ($_POST['id_producto'] ?? 0);
        if ($idProducto === 0) {
            $this->irA('admin_productos');
        }

        $producto = $this->productoModel->buscarPorId($idProducto);
        if (!empty($producto['imagen'])) {
            $ruta = __DIR__ . '/../../public/assets/productos/' . $producto['imagen'];
            if (is_file($ruta)) @unlink($ruta);
        }
        $this->productoModel->actualizarImagen($idProducto, null);

        $this->irA('admin_productos', ['ok_imagen' => 1]);
    }

    /* ---------------------------------------------------------------------
     * Ventas
     * ------------------------------------------------------------------ */

    public function mostrarVentas(): void
    {
        $this->requirePermission('ventas.view');
        $pageTitle    = 'Ventas';
        $paginaActiva = 'ventas';

        $mensajeExito = '';
        $errorVenta   = $_GET['err'] ?? '';

        if (isset($_GET['ok']))        $mensajeExito = 'Venta registrada correctamente.';
        if (isset($_GET['ok_anular'])) $mensajeExito = 'Venta anulada y stock devuelto.';

        $desde = trim($_GET['desde'] ?? date('Y-m-d'));
        $hasta = trim($_GET['hasta'] ?? date('Y-m-d'));
        if (!$this->esFechaValida($desde)) $desde = date('Y-m-d');
        if (!$this->esFechaValida($hasta)) $hasta = date('Y-m-d');
        if ($desde > $hasta) [$desde, $hasta] = [$hasta, $desde];

        $ventas          = $this->ventaModel->listarPorRango($desde, $hasta);
        $porMetodo       = $this->ventaModel->sumarPorMetodoPago($desde, $hasta);
        // Las anuladas se siguen listando (para que quede el rastro) pero no
        // suman: el total del rango tiene que cuadrar con el dinero real.
        $totalRango      = $this->sumarActivas($ventas);
        $sedeFijada      = $this->gimnasioActual() !== null;
        // Una venta siempre pertenece a una sede. En la vista global no se
        // precargan catálogos ni se deja empezar un ticket destinado a fallar.
        $productosVenta  = $sedeFijada ? $this->productoModel->listarActivos() : [];
        $socios          = $sedeFijada ? $this->userModel->listarPorRol('socio') : [];
        $ventasHoy       = $this->ventaModel->sumarDelDia();
        $numVentasHoy    = $this->ventaModel->contarDelDia();

        require __DIR__ . '/../views/admin/ventas.php';
    }

    public function registrarVenta(): void
    {
        $this->requirePermission('ventas.create');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Csrf::validarPost()) {
            $this->irAConError('admin_ventas', 'Solicitud no válida. Vuelve a intentarlo.');
        }
        $this->exigirSedeFijada('admin_ventas');

        $idSocio     = (int) ($_POST['id_socio'] ?? 0) ?: null;
        $metodoPago  = $_POST['metodo_pago'] ?? '';
        $operacionId = preg_match('/^[a-f0-9]{32}$/', (string) ($_POST['_operation_id'] ?? '')) ? (string) $_POST['_operation_id'] : null;

        // El formulario admite varias líneas: productos[] y cantidades[] en paralelo.
        $idsProducto = $_POST['productos']  ?? [];
        $cantidades  = $_POST['cantidades'] ?? [];
        $lineas      = [];

        if (is_array($idsProducto)) {
            foreach ($idsProducto as $i => $idProducto) {
                $lineas[] = [
                    'id_producto' => (int) $idProducto,
                    'cantidad'    => (int) ($cantidades[$i] ?? 0),
                ];
            }
        }

        $error   = '';
        $idVenta = $this->ventaModel->registrar(
            $lineas,
            $idSocio,
            $metodoPago,
            (int) ($_SESSION['usuario_id'] ?? 0),
            $error,
            $operacionId
        );

        if ($idVenta === null) {
            $this->irAConError('admin_ventas', $error);
        }

        $venta = $this->ventaModel->buscarPorId($idVenta);
        $this->registrarLog('Venta', 'Venta #' . $idVenta . ' — ' . number_format((float) ($venta['total'] ?? 0), 2, ',', '.') . ' € (' . $metodoPago . ')');

        $this->irA('admin_ventas', ['ok' => 1]);
    }

    public function anularVenta(): void
    {
        $this->requirePermission('ventas.cancel');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Csrf::validarPost()) {
            $this->irA('admin_ventas');
        }

        $idVenta = (int) ($_POST['id_venta'] ?? 0);
        $motivo  = trim($_POST['motivo'] ?? '');
        $venta   = $idVenta > 0 ? $this->ventaModel->buscarPorId($idVenta) : null;

        if ($venta && $this->ventaModel->anular($idVenta, (int) ($_SESSION['usuario_id'] ?? 0), $motivo)) {
            // La venta no se borra: queda anulada y con su número. El log dice
            // quién y por qué, que es lo que se mira cuando la caja no cuadra.
            $this->registrarLog(
                'Anular venta',
                'Ticket ' . VentaModel::referencia($venta)
                    . ' — ' . number_format((float) $venta['total'], 2, ',', '.') . ' €'
                    . ' — stock devuelto'
                    . ($motivo !== '' ? ' — motivo: ' . $motivo : '')
            );
            $this->irA('admin_ventas', ['ok_anular' => 1]);
        }
        $this->irAConError('admin_ventas', 'No se pudo anular la venta: no existe, no es de esta sede o ya estaba anulada.');
    }

    /* ---------------------------------------------------------------------
     * Socios y membresías
     * ------------------------------------------------------------------ */

    public function mostrarSocios(): void
    {
        $this->requirePermission('socios.view');
        $pageTitle    = 'Socios';
        $paginaActiva = 'socios';

        $mensajeExito = '';
        $errorSocio   = $_GET['err'] ?? '';

        if (isset($_GET['ok']))            $mensajeExito = 'Socio dado de alta correctamente.';
        if (isset($_GET['ok_membresia']))  $mensajeExito = 'Membresía registrada correctamente.';
        if (isset($_GET['ok_estado']))     $mensajeExito = 'Estado del socio actualizado.';
        if (isset($_GET['ok_prueba']))     $mensajeExito = 'Acceso de prueba abierto por ' . MembresiaModel::DIAS_PRUEBA . ' días. Se cerrará solo si no se confirma el pago.';
        if (isset($_GET['ok_editar']))     $mensajeExito = 'Datos del socio actualizados.';
        if (isset($_GET['ok_mandato']))    $mensajeExito = 'Mandato SEPA registrado. Ya se le puede domiciliar la cuota.';

        $busquedaValidada = InputValidator::text($_GET['buscar'] ?? '', 100, false);
        $busqueda = $busquedaValidada ?? '';
        if ($busquedaValidada === null && $errorSocio === '') {
            $errorSocio = 'La búsqueda no es válida.';
        }
        $paginaSolicitada = filter_var(
            $_GET['pagina'] ?? 1,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        $paginacion = $this->membresiaModel->paginarSocios(
            $busqueda,
            $paginaSolicitada === false ? 1 : (int) $paginaSolicitada,
            50
        );
        $socios         = $paginacion['items'];
        $totalResultados = $paginacion['total'];
        $paginaActual   = $paginacion['pagina'];
        $porPagina      = $paginacion['por_pagina'];
        $totalPaginas   = $paginacion['paginas'];
        $tipos       = $this->membresiaModel->listarTiposActivos();
        $suplementos = $this->membresiaModel->listarSuplementosActivos();
        $totalSocios = $this->userModel->contarPorRol('socio');
        $activas     = $this->membresiaModel->contarActivas();
        $vencidas    = $this->membresiaModel->contarVencidas();
        $porVencer   = $this->membresiaModel->listarProximasAVencer(15);
        $pruebas     = $this->membresiaModel->listarPruebasPendientes();
        $diasPrueba  = MembresiaModel::DIAS_PRUEBA;

        require __DIR__ . '/../views/admin/socios.php';
    }

    public function registrarSocio(): void
    {
        $this->requirePermission('socios.create');
        $navegacion = $this->navegacionSocios($_POST);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Csrf::validarPost()) {
            $this->irAConError('admin_socios', 'Solicitud no válida. Vuelve a intentarlo.', $navegacion);
        }
        // Un socio nace en la sede donde se le da de alta: hace falta saber cuál.
        $this->exigirSedeFijada('admin_socios', $navegacion);

        $nombre     = trim($_POST['nombre']    ?? '');
        $apellidos  = trim($_POST['apellidos'] ?? '');
        $dni        = trim(strtoupper($_POST['dni']   ?? ''));
        $email      = trim(strtolower($_POST['email'] ?? ''));
        $telefono   = trim($_POST['telefono']  ?? '') ?: null;
        $usuario    = trim($_POST['usuario']   ?? '');
        $contrasena = $_POST['contrasena'] ?? '';
        $idTipo       = (int) ($_POST['id_tipo_membresia'] ?? 0);
        $metodoPago   = $_POST['metodo_pago'] ?? 'efectivo';
        $idSuplemento = (int) ($_POST['id_suplemento'] ?? 0) ?: null;
        $iban         = Iban::normalizar($_POST['iban'] ?? '') ?: null;

        $error = '';
        if ($nombre === '' || $apellidos === '' || $dni === '' || $email === '' || $usuario === '') {
            $error = 'Nombre, apellidos, DNI, email y usuario son obligatorios.';
        } elseif (InputValidator::email($email) === null) {
            $error = 'Correo electrónico no válido.';
        } elseif ($telefono !== null && InputValidator::phone($telefono) === null) {
            $error = 'Teléfono no válido.';
        } elseif (strlen($contrasena) < 8) {
            $error = 'La contraseña debe tener al menos 8 caracteres.';
        } elseif ($this->userModel->usuarioExiste($usuario)) {
            $error = 'Ese nombre de usuario ya está en uso.';
        } elseif ($this->userModel->correoExiste($email)) {
            $error = 'Ese correo ya está registrado.';
        } elseif ($this->userModel->dniExiste($dni)) {
            $error = 'Ese DNI ya está registrado.';
        } elseif ($metodoPago === 'transferencia' && $idTipo > 0 && $iban === null) {
            $error = 'Para cobrar por transferencia hace falta el IBAN.';
        } elseif ($iban !== null && !Iban::esValido($iban)) {
            $error = 'El IBAN no es válido. Revisa que esté completo y bien tecleado.';
        }

        if ($error !== '') {
            $this->irAConError('admin_socios', $error, $navegacion);
        }

        $creado = $this->userModel->crear(
            $nombre, $apellidos, $dni, $telefono, $email, $usuario, $contrasena, $iban
        );

        if (!$creado) {
            $this->irAConError('admin_socios', 'No se pudo dar de alta al socio.', $navegacion);
        }

        $nuevo = $this->userModel->buscarPorCorreo($email);
        $idSocio = (int) ($nuevo['id_usuario'] ?? 0);
        $this->registrarLogSobre('Alta de socio', $idSocio, $nombre . ' ' . $apellidos . ' (' . $email . ')');

        // Si en el alta se eligió una membresía, se contrata en el mismo paso.
        if ($idSocio > 0 && $idTipo > 0) {
            $errorMembresia = '';
            $idContrato = $this->membresiaModel->contratar($idSocio, $idTipo, $metodoPago, $errorMembresia, $idSuplemento);
            if ($idContrato !== null) {
                $vigente = $this->membresiaModel->vigenteDeSocio($idSocio);
                if ($vigente) {
                    Mailer::membresiaContratada($email, $nombre, $vigente['nombre_tipo'], $vigente['fecha_fin']);
                }
            }
        }

        $this->irA('admin_socios', array_merge($navegacion, ['ok' => 1]));
    }

    /**
     * Edita los datos de contacto de un socio, incluido el IBAN.
     * Deja en el historial qué campos cambiaron y con qué valores.
     */
    public function editarSocio(): void
    {
        $this->requirePermission('socios.edit');
        $navegacion = $this->navegacionSocios($_POST);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Csrf::validarPost()) {
            $this->irA('admin_socios', $navegacion);
        }

        $idSocio   = (int) ($_POST['id_socio'] ?? 0);
        $nombre    = trim($_POST['nombre']    ?? '');
        $apellidos = trim($_POST['apellidos'] ?? '');
        $telefono  = trim($_POST['telefono']  ?? '') ?: null;
        $email     = trim(strtolower($_POST['email'] ?? ''));
        $iban      = Iban::normalizar($_POST['iban'] ?? '') ?: null;

        $socio = $idSocio > 0 ? $this->userModel->buscarPorId($idSocio) : null;
        if (!$socio || ($socio['rol'] ?? '') !== 'socio') {
            $this->irAConError('admin_socios', 'El socio no existe.', $navegacion);
        }

        $error = '';
        if ($nombre === '' || $apellidos === '' || $email === '') {
            $error = 'Nombre, apellidos y email son obligatorios.';
        } elseif (InputValidator::email($email) === null) {
            $error = 'Correo electrónico no válido.';
        } elseif ($telefono !== null && InputValidator::phone($telefono) === null) {
            $error = 'Teléfono no válido.';
        } elseif ($this->userModel->correoExisteOtroUsuario($email, $idSocio)) {
            $error = 'Ese correo ya está registrado en otra cuenta.';
        } elseif ($iban !== null && !Iban::esValido($iban)) {
            $error = 'El IBAN no es válido. Revisa que esté completo y bien tecleado.';
        }

        if ($error !== '') {
            $this->irAConError('admin_socios', $error, $navegacion);
        }

        $ibanAnterior = $socio['iban'] ?? null;
        $ok = $this->userModel->actualizarDatosSocio($idSocio, $nombre, $apellidos, $telefono, $email, $iban);

        if (!$ok) {
            $this->irAConError('admin_socios', 'No se pudieron guardar los cambios.', $navegacion);
        }

        $nombreCompleto = trim($nombre . ' ' . $apellidos);
        $this->registrarLogSobre('Edición de socio', $idSocio, $nombreCompleto);

        // El cambio de cuenta bancaria se registra aparte y enmascarado: el log
        // lo puede leer cualquier admin y no tiene por qué mostrar el número entero.
        if ($ibanAnterior !== $iban) {
            $this->registrarLogSobre(
                'Cambio de IBAN',
                $idSocio,
                $nombreCompleto,
                $ibanAnterior ? Iban::enmascarar($ibanAnterior) : 'sin IBAN',
                $iban ? Iban::enmascarar($iban) : 'sin IBAN'
            );
        }

        $this->irA('admin_socios', array_merge($navegacion, ['ok_editar' => 1]));
    }

    public function contratarMembresia(): void
    {
        $this->requirePermission('membresias.renew');
        $navegacion = $this->navegacionSocios($_POST);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Csrf::validarPost()) {
            $this->irA('admin_socios', $navegacion);
        }

        $this->exigirSedeFijada('admin_socios', $navegacion);

        $idSocio       = (int) ($_POST['id_socio'] ?? 0);
        $idTipo        = (int) ($_POST['id_tipo_membresia'] ?? 0);
        $metodoPago    = $_POST['metodo_pago'] ?? 'efectivo';
        $idSuplemento  = (int) ($_POST['id_suplemento'] ?? 0) ?: null;
        $operacionId = preg_match('/^[a-f0-9]{32}$/', (string) ($_POST['_operation_id'] ?? '')) ? (string) $_POST['_operation_id'] : null;

        $socio = $idSocio > 0 ? $this->userModel->buscarPorId($idSocio) : null;
        if (!$socio || ($socio['rol'] ?? '') !== 'socio') {
            $this->irAConError('admin_socios', 'El socio no existe.', $navegacion);
        }

        // Si se cobra por transferencia hace falta el IBAN. El formulario permite
        // teclearlo en el momento si el socio aún no lo tiene guardado.
        if ($metodoPago === 'transferencia') {
            $ibanFormulario = Iban::normalizar($_POST['iban'] ?? '') ?: null;

            if ($ibanFormulario !== null && !Iban::esValido($ibanFormulario)) {
                $this->irAConError('admin_socios', 'El IBAN no es válido. Revisa que esté completo y bien tecleado.', $navegacion);
            }
            if ($ibanFormulario !== null && $ibanFormulario !== ($socio['iban'] ?? null)) {
                $this->userModel->actualizarIban($idSocio, $ibanFormulario);
                $this->registrarLogSobre(
                    'Cambio de IBAN',
                    $idSocio,
                    trim(($socio['nombre'] ?? '') . ' ' . ($socio['apellidos'] ?? '')),
                    !empty($socio['iban']) ? Iban::enmascarar($socio['iban']) : 'sin IBAN',
                    Iban::enmascarar($ibanFormulario)
                );
                $socio['iban'] = $ibanFormulario;
            }
            if (empty($socio['iban'])) {
                $this->irAConError('admin_socios', 'Para cobrar por transferencia hace falta el IBAN del socio.', $navegacion);
            }
        }

        // Se guarda el vencimiento previo para dejarlo reflejado en el log.
        $anterior = $this->membresiaModel->vigenteDeSocio($idSocio);
        $vencimientoAnterior = $anterior['fecha_fin'] ?? 'sin membresía';

        $error = '';
        $idContrato = $this->membresiaModel->contratar($idSocio, $idTipo, $metodoPago, $error, $idSuplemento, 'mostrador', $operacionId);

        if ($idContrato === null) {
            $this->irAConError('admin_socios', $error, $navegacion);
        }

        $vigente = $this->membresiaModel->vigenteDeSocio($idSocio);
        $nombreSocio = trim(($socio['nombre'] ?? '') . ' ' . ($socio['apellidos'] ?? ''));
        $this->registrarLogSobre(
            $anterior ? 'Renovación de membresía' : 'Alta de membresía',
            $idSocio,
            $nombreSocio . ' — ' . ($vigente['nombre_tipo'] ?? '')
                . (!empty($vigente['nombre_suplemento']) ? ' + ' . $vigente['nombre_suplemento'] : ''),
            $vencimientoAnterior,
            $vigente['fecha_fin'] ?? ''
        );

        if ($vigente && !empty($socio['email'])) {
            Mailer::membresiaContratada($socio['email'], $socio['nombre'], $vigente['nombre_tipo'], $vigente['fecha_fin']);
        }

        $this->irA('admin_socios', array_merge($navegacion, ['ok_membresia' => 1]));
    }

    /**
     * Abre el acceso de prueba: gratis, pendiente de pago y con caducidad
     * automática a los días que fije MembresiaModel::DIAS_PRUEBA.
     */
    public function iniciarPruebaSocio(): void
    {
        $this->requirePermission('membresias.renew');
        $navegacion = $this->navegacionSocios($_POST);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Csrf::validarPost()) {
            $this->irA('admin_socios', $navegacion);
        }

        $this->exigirSedeFijada('admin_socios', $navegacion);

        $idSocio = (int) ($_POST['id_socio'] ?? 0);
        $socio   = $idSocio > 0 ? $this->userModel->buscarPorId($idSocio) : null;

        if (!$socio || ($socio['rol'] ?? '') !== 'socio') {
            $this->irAConError('admin_socios', 'El socio no existe.', $navegacion);
        }

        $error = '';
        $idPrueba = $this->membresiaModel->iniciarPrueba($idSocio, $error);

        if ($idPrueba === null) {
            $this->irAConError('admin_socios', $error, $navegacion);
        }

        $prueba = $this->membresiaModel->pruebaVigenteDeSocio($idSocio);
        $this->registrarLogSobre(
            'Apertura de prueba',
            $idSocio,
            trim(($socio['nombre'] ?? '') . ' ' . ($socio['apellidos'] ?? ''))
                . ' — acceso de prueba pendiente de pago',
            'sin acceso',
            'prueba hasta ' . ($prueba['fecha_fin'] ?? '')
        );

        $this->irA('admin_socios', array_merge($navegacion, ['ok_prueba' => 1]));
    }

    public function mostrarMembresias(): void
    {
        $this->requirePermission('membresias.catalog.manage');
        $pageTitle    = 'Tipos de Membresía';
        $paginaActiva = 'membresias';

        $mensajeExito   = '';
        $errorMembresia = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!Csrf::validarPost()) {
                $errorMembresia = 'La sesión ha caducado. Vuelve a intentarlo.';
            } else {
                $accion = $_POST['accion'] ?? '';

                if ($accion === 'toggle_estado_tipo') {
                    $idTipo = (int) ($_POST['id_tipo_membresia'] ?? 0);
                    if ($idTipo > 0 && $this->membresiaModel->toggleEstadoTipo($idTipo)) {
                        $this->registrarLog('Estado de cuota', 'Activado/desactivado el tipo de membresía #' . $idTipo);
                    }
                    $this->irA('admin_membresias');
                }

                if ($accion === 'crear_tipo' || $accion === 'editar_tipo') {
                    $idTipo        = (int) ($_POST['id_tipo_membresia'] ?? 0);
                    $nombre        = trim($_POST['nombre']      ?? '');
                    $descripcion   = trim($_POST['descripcion'] ?? '') ?: null;
                    $precio        = $_POST['precio']           ?? '';
                    $duracionMeses = $_POST['duracion_meses']   ?? '';
                    $estado        = trim($_POST['estado']      ?? '');
                    $iva           = is_numeric($_POST['iva'] ?? null) ? (float) $_POST['iva'] : 21.0;
                    $iva           = max(0.0, min(100.0, $iva));

                    if ($nombre === '') {
                        $errorMembresia = 'El nombre de la membresía es obligatorio.';
                    } elseif (!is_numeric($precio) || (float) $precio < 0) {
                        $errorMembresia = 'El precio debe ser un número igual o mayor que 0.';
                    } elseif (!is_numeric($duracionMeses) || (int) $duracionMeses < 1) {
                        $errorMembresia = 'La duración debe ser de al menos 1 mes.';
                    } elseif (!in_array($estado, ['activo', 'inactivo'], true)) {
                        $errorMembresia = 'El estado es obligatorio.';
                    } else {
                        if ($accion === 'crear_tipo') {
                            $ok = $this->membresiaModel->crearTipo(
                                $nombre, $descripcion, (float) $precio, (int) $duracionMeses, $estado, $iva
                            );
                            $flag = ['ok' => 1];
                        } else {
                            $ok = $idTipo > 0 && $this->membresiaModel->actualizarTipo(
                                $idTipo, $nombre, $descripcion, (float) $precio, (int) $duracionMeses, $estado, $iva
                            );
                            $flag = ['ok_editar' => 1];
                        }

                        if ($ok) {
                            $this->registrarLog('Tipo de membresía', $nombre . ' (' . $duracionMeses . ' meses)');
                            $this->irA('admin_membresias', $flag);
                        }
                        $errorMembresia = 'No se pudo guardar el tipo de membresía.';
                    }
                }
            }
        }

        if (isset($_GET['ok']))        $mensajeExito = 'Tipo de membresía creado correctamente.';
        if (isset($_GET['ok_editar'])) $mensajeExito = 'Tipo de membresía actualizado correctamente.';

        $tipos           = $this->membresiaModel->listarTipos();
        $activas         = $this->membresiaModel->contarActivas();
        $vencidas        = $this->membresiaModel->contarVencidas();
        $ingresosMes     = $this->membresiaModel->sumarIngresosDelMes();

        require __DIR__ . '/../views/admin/membresias.php';
    }

    /* ---------------------------------------------------------------------
     * Sedes y personal
     * ------------------------------------------------------------------ */

    public function mostrarSedes(): void
    {
        $this->requirePermission('sedes.manage');
        $pageTitle    = 'Sedes';
        $paginaActiva = 'sedes';

        $mensajeExito = '';
        $errorSede    = $_GET['err'] ?? '';
        if (isset($_GET['ok']))        $mensajeExito = 'Sede creada correctamente.';
        if (isset($_GET['ok_editar'])) $mensajeExito = 'Sede actualizada.';
        if (isset($_GET['ok_estado'])) $mensajeExito = 'Estado de la sede actualizado.';
        if (isset($_GET['ok_marca']))  $mensajeExito = 'Marca de la sede actualizada.';

        $gimnasioModel = new GimnasioModel($this->tenant->empresaId());

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!Csrf::validarPost()) {
                $errorSede = 'La sesión ha caducado. Vuelve a intentarlo.';
            } else {
                $accion = $_POST['accion'] ?? '';

                if ($accion === 'toggle_sede') {
                    $idSede = (int) ($_POST['id_gimnasio'] ?? 0);
                    $sede   = $idSede > 0 ? $gimnasioModel->buscarPorId($idSede) : null;
                    if ($sede) {
                        // Cerrar la última sede abierta dejaría el sistema inservible.
                        if ((int) $sede['activo'] === 1 && $gimnasioModel->contarActivas() <= 1) {
                            $this->irAConError('admin_sedes', 'No puedes cerrar la única sede activa.');
                        }
                        $gimnasioModel->toggleActivo($idSede);
                        $this->registrarLog('Sede', ($sede['activo'] ? 'Cerrada' : 'Reabierta') . ' la sede ' . $sede['nombre']);
                    }
                    $this->irA('admin_sedes', ['ok_estado' => 1]);
                }

                if ($accion === 'guardar_sede') {
                    $idSede = (int) ($_POST['id_gimnasio'] ?? 0);
                    $datos  = [
                        'nombre'       => trim($_POST['nombre']       ?? ''),
                        'razon_social' => trim($_POST['razon_social'] ?? ''),
                        'cif'          => trim($_POST['cif']          ?? ''),
                        'direccion'    => trim($_POST['direccion']    ?? ''),
                        'telefono'     => trim($_POST['telefono']     ?? ''),
                        'email'        => trim($_POST['email']        ?? ''),
                    ];

                    // Credenciales con las que entrará el gimnasio.
                    $emailAcceso = trim(strtolower($_POST['email_acceso'] ?? ''));
                    $claveAcceso = $_POST['contrasena_acceso'] ?? '';

                    if ($datos['nombre'] === '') {
                        $errorSede = 'El nombre de la sede es obligatorio.';
                    } elseif ($gimnasioModel->nombreExiste($datos['nombre'], $idSede)) {
                        $errorSede = 'Ya existe una sede con ese nombre.';
                    } elseif ($datos['email'] !== '' && !filter_var($datos['email'], FILTER_VALIDATE_EMAIL)) {
                        $errorSede = 'El correo de contacto no es válido.';
                    } elseif ($emailAcceso !== '' && !filter_var($emailAcceso, FILTER_VALIDATE_EMAIL)) {
                        $errorSede = 'El email de acceso del gimnasio no es válido.';
                    } elseif ($emailAcceso !== '' && $gimnasioModel->emailAccesoExiste($emailAcceso, $idSede)) {
                        $errorSede = 'Ese email de acceso ya lo usa otro gimnasio.';
                    } elseif ($claveAcceso !== '' && strlen($claveAcceso) < 6) {
                        $errorSede = 'La contraseña del gimnasio debe tener al menos 6 caracteres.';
                    } else {
                        if ($idSede > 0) {
                            $gimnasioModel->actualizar($idSede, $datos);
                            $this->registrarLog('Sede', 'Editada la sede ' . $datos['nombre']);
                            $flag = ['ok_editar' => 1];
                        } else {
                            $idSede = (int) $gimnasioModel->crear($datos);
                            $this->registrarLog('Sede', 'Creada la sede ' . $datos['nombre'] . ' (#' . $idSede . ')');
                            $flag = ['ok' => 1];
                        }

                        if ($emailAcceso !== '' && $idSede > 0) {
                            $gimnasioModel->guardarCredenciales($idSede, $emailAcceso, $claveAcceso);
                            $this->registrarLog(
                                'Acceso de sede',
                                'Credenciales de ' . $datos['nombre'] . ': ' . $emailAcceso
                                    . ($claveAcceso !== '' ? ' (contraseña actualizada)' : '')
                            );
                        }

                        $this->irA('admin_sedes', $flag);
                    }
                }
            }
        }

        $sedes = $gimnasioModel->listar();

        require __DIR__ . '/../views/admin/sedes.php';
    }

    /**
     * Logo y colores de una sede: es lo que da a cada gimnasio su pantalla de
     * acceso propia. El logo se guarda en public/assets/gimnasios/.
     */
    public function guardarMarcaSede(): void
    {
        $this->requirePermission('config.manage');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Csrf::validarPost()) {
            $this->irA('admin_sedes');
        }

        $idSede = (int) ($_POST['id_gimnasio'] ?? 0);
        $gimnasioModel = new GimnasioModel($this->tenant->empresaId());
        $sede = $idSede > 0 ? $gimnasioModel->buscarPorId($idSede) : null;

        if (!$sede) {
            $this->irAConError('admin_sedes', 'La sede no existe.');
        }

        // Quitar el logo es una acción aparte del guardado de colores.
        if (($_POST['accion_marca'] ?? '') === 'quitar_logo') {
            if (!empty($sede['logo'])) {
                $ruta = __DIR__ . '/../../public/assets/gimnasios/' . $sede['logo'];
                if (is_file($ruta)) @unlink($ruta);
            }
            $gimnasioModel->quitarLogo($idSede);
            $this->registrarLog('Marca de sede', 'Quitado el logo de ' . $sede['nombre']);
            $this->irA('admin_sedes', ['ok_marca' => 1]);
        }

        $error  = '';
        $nombreLogo = $this->procesarSubidaImagen($_FILES['logo'] ?? null, 'gimnasios', 'logo', $error);

        if ($error !== '') {
            $this->irAConError('admin_sedes', $error);
        }

        $colorPrimario = trim($_POST['color_primario'] ?? '#4f46e5');
        $colorTexto    = trim($_POST['color_texto']    ?? '#ffffff');

        if (!$gimnasioModel->actualizarMarca($idSede, $nombreLogo, $colorPrimario, $colorTexto)) {
            if ($nombreLogo !== null) @unlink(__DIR__ . '/../../public/assets/gimnasios/' . $nombreLogo);
            $this->irAConError('admin_sedes', 'No se pudo guardar la marca de la sede.');
        }

        // Si se sube uno nuevo, el anterior se borra del disco.
        if ($nombreLogo !== null && !empty($sede['logo'])) {
            $rutaAntigua = __DIR__ . '/../../public/assets/gimnasios/' . $sede['logo'];
            if (is_file($rutaAntigua)) @unlink($rutaAntigua);
        }

        $this->registrarLog(
            'Marca de sede',
            'Actualizada la marca de ' . $sede['nombre']
                . ($nombreLogo !== null ? ' (logo nuevo)' : '')
        );

        $this->irA('admin_sedes', ['ok_marca' => 1]);
    }

    /** La empresa elige en qué sede trabaja; vacío = ver todas juntas. */
    public function cambiarSedeActiva(): void
    {
        $this->requirePermission('sedes.manage');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Csrf::validarPost()) {
            $this->irA('admin');
        }

        $idSede = (int) ($_POST['id_gimnasio'] ?? 0);
        if (!$this->tenant->seleccionarSede($idSede > 0 ? $idSede : null)) {
            $this->irAConError('admin', 'La sede seleccionada no pertenece a tu empresa.');
        }

        if ($idSede > 0) {
            $gimnasioModel = new GimnasioModel($this->tenant->empresaId());
            $sede = $gimnasioModel->buscarPorId($idSede);
            $_SESSION['gimnasio_nombre'] = $sede['nombre'] ?? '';
        } else {
            $_SESSION['gimnasio_nombre'] = '';
        }

        $volver = $_POST['volver_a'] ?? 'admin';
        $volver = preg_match('/^admin[a-z_]*$/', $volver) ? $volver : 'admin';

        $parametros = $volver === 'admin_socios' ? $this->navegacionSocios($_POST) : [];
        $this->irA($volver, $parametros);
    }

    public function mostrarEmpleados(): void
    {
        $this->requirePermission('empleados.manage');
        $pageTitle    = 'Personal';
        $paginaActiva = 'empleados';

        $mensajeExito  = '';
        $errorEmpleado = $_GET['err'] ?? '';
        if (isset($_GET['ok']))        $mensajeExito = 'Empleado dado de alta correctamente.';
        if (isset($_GET['ok_editar'])) $mensajeExito = 'Datos del empleado actualizados.';
        if (isset($_GET['ok_estado'])) $mensajeExito = 'Acceso del empleado actualizado.';

        $esEmpresa = in_array($this->tenant->rol(), ['superadmin', 'direccion'], true);
        $gimnasioModel = new GimnasioModel($this->tenant->empresaId());

        $busqueda  = trim($_GET['buscar'] ?? '');
        $empleados = $this->userModel->listarEmpleados($busqueda);
        $sedes     = $gimnasioModel->listarActivas();
        $idPropio  = (int) ($_SESSION['usuario_id'] ?? 0);

        require __DIR__ . '/../views/admin/empleados.php';
    }

    public function crearEmpleado(): void
    {
        $this->requirePermission('empleados.manage');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Csrf::validarPost()) {
            $this->irA('admin_empleados');
        }

        $esEmpresa = in_array($this->tenant->rol(), ['superadmin', 'direccion'], true);

        $nombre     = trim($_POST['nombre']    ?? '');
        $apellidos  = trim($_POST['apellidos'] ?? '');
        $dni        = trim(strtoupper($_POST['dni']   ?? ''));
        $email      = trim(strtolower($_POST['email'] ?? ''));
        $telefono   = trim($_POST['telefono']  ?? '') ?: null;
        $usuario    = trim($_POST['usuario']   ?? '');
        $contrasena = $_POST['contrasena'] ?? '';
        $rol        = $_POST['rol'] ?? 'recepcion';

        // Un admin solo crea recepción y solo en su propia sede. Ascender a
        // alguien a admin es competencia de la empresa.
        if (!$esEmpresa) {
            $rol    = 'recepcion';
            $idSede = $this->gimnasioActual();
        } else {
            $rol    = in_array($rol, ['admin', 'recepcion'], true) ? $rol : 'recepcion';
            $idSede = (int) ($_POST['id_gimnasio'] ?? 0) ?: null;
        }

        $error = '';
        if ($nombre === '' || $apellidos === '' || $dni === '' || $email === '' || $usuario === '') {
            $error = 'Nombre, apellidos, DNI, email y usuario son obligatorios.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Correo electrónico no válido.';
        } elseif (strlen($contrasena) < self::MIN_CLAVE_EMPLEADO) {
            $error = 'La contraseña debe tener al menos ' . self::MIN_CLAVE_EMPLEADO . ' caracteres.';
        } elseif ($idSede === null) {
            $error = 'Hay que asignar el empleado a una sede.';
        } elseif (!$this->tenant->puedeUsarSede($idSede)) {
            $error = 'La sede indicada no pertenece a tu empresa.';
        } elseif ($this->userModel->usuarioExiste($usuario)) {
            $error = 'Ese nombre de usuario ya está en uso.';
        } elseif ($this->userModel->correoExiste($email)) {
            $error = 'Ese correo ya está registrado.';
        } elseif ($this->userModel->dniExiste($dni)) {
            $error = 'Ese DNI ya está registrado.';
        }

        if ($error !== '') {
            $this->irAConError('admin_empleados', $error);
        }

        $idNuevo = $this->userModel->crearEmpleado(
            $nombre, $apellidos, $dni, $email, $telefono,
            $usuario, $contrasena, $rol, $idSede
        );

        if ($idNuevo === null) {
            $this->irAConError('admin_empleados', 'No se pudo dar de alta al empleado.');
        }

        $this->registrarLogSobre(
            'Alta de empleado',
            $idNuevo,
            trim($nombre . ' ' . $apellidos) . ' (' . $usuario . ')',
            'sin acceso',
            $rol,
            'empleado'
        );

        $this->irA('admin_empleados', ['ok' => 1]);
    }

    public function editarEmpleado(): void
    {
        $this->requirePermission('empleados.manage');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Csrf::validarPost()) {
            $this->irA('admin_empleados');
        }

        $esEmpresa = in_array($this->tenant->rol(), ['superadmin', 'direccion'], true);
        $idPropio      = (int) ($_SESSION['usuario_id'] ?? 0);

        $idEmpleado = (int) ($_POST['id_usuario'] ?? 0);
        $empleado   = $idEmpleado > 0 ? $this->userModel->buscarPorId($idEmpleado) : null;

        if (!$empleado || !in_array($empleado['rol'], ['direccion', 'admin', 'recepcion'], true)) {
            $this->irAConError('admin_empleados', 'El empleado no existe.');
        }
        // Un admin no puede tocar a la empresa ni a otro admin.
        if (!$esEmpresa && $empleado['rol'] !== 'recepcion') {
            $this->irAConError('admin_empleados', 'No tienes permiso para editar a este usuario.');
        }

        $nombre    = trim($_POST['nombre']    ?? '');
        $apellidos = trim($_POST['apellidos'] ?? '');
        $email     = trim(strtolower($_POST['email'] ?? ''));
        $telefono  = trim($_POST['telefono']  ?? '') ?: null;

        $rolAnterior = $empleado['rol'];
        if ($esEmpresa) {
            $rol    = in_array($_POST['rol'] ?? '', ['direccion', 'admin', 'recepcion'], true)
                    ? $_POST['rol'] : $rolAnterior;
            $idSede = (int) ($_POST['id_gimnasio'] ?? 0) ?: null;
        } else {
            $rol    = $rolAnterior;                       // el admin no cambia roles
            $idSede = (int) $empleado['id_gimnasio'] ?: null;
        }

        // Nadie puede quitarse a sí mismo los permisos y quedar fuera.
        if ($idEmpleado === $idPropio && $rol !== $rolAnterior) {
            $this->irAConError('admin_empleados', 'No puedes cambiarte el rol a ti mismo.');
        }

        $error = '';
        if ($nombre === '' || $apellidos === '' || $email === '') {
            $error = 'Nombre, apellidos y email son obligatorios.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Correo electrónico no válido.';
        } elseif ($this->userModel->correoExisteOtroUsuario($email, $idEmpleado)) {
            $error = 'Ese correo ya está registrado en otra cuenta.';
        } elseif ($rol !== 'direccion' && $idSede === null) {
            $error = 'Hay que asignar el empleado a una sede.';
        } elseif ($idSede !== null && !$this->tenant->puedeUsarSede($idSede)) {
            $error = 'La sede indicada no pertenece a tu empresa.';
        }

        if ($error !== '') {
            $this->irAConError('admin_empleados', $error);
        }

        $this->userModel->actualizarEmpleado($idEmpleado, $nombre, $apellidos, $email, $telefono, $rol, $idSede);

        $nombreCompleto = trim($nombre . ' ' . $apellidos);
        if ($rol !== $rolAnterior) {
            $this->registrarLogSobre('Cambio de rol', $idEmpleado, $nombreCompleto, $rolAnterior, $rol, 'empleado');
        } else {
            $this->registrarLogSobre('Edición de empleado', $idEmpleado, $nombreCompleto, null, null, 'empleado');
        }

        $this->irA('admin_empleados', ['ok_editar' => 1]);
    }

    /** Activa o bloquea el acceso de un empleado sin borrar su histórico. */
    public function toggleEmpleado(): void
    {
        $this->requirePermission('empleados.manage');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Csrf::validarPost()) {
            $this->irA('admin_empleados');
        }

        $esEmpresa = in_array($this->tenant->rol(), ['superadmin', 'direccion'], true);
        $idPropio      = (int) ($_SESSION['usuario_id'] ?? 0);
        $idEmpleado    = (int) ($_POST['id_usuario'] ?? 0);
        $empleado      = $idEmpleado > 0 ? $this->userModel->buscarPorId($idEmpleado) : null;

        if (!$empleado || !in_array($empleado['rol'], ['direccion', 'admin', 'recepcion'], true)) {
            $this->irAConError('admin_empleados', 'El empleado no existe.');
        }
        if ($idEmpleado === $idPropio) {
            $this->irAConError('admin_empleados', 'No puedes bloquear tu propio acceso.');
        }
        if (!$esEmpresa && $empleado['rol'] !== 'recepcion') {
            $this->irAConError('admin_empleados', 'No tienes permiso sobre este usuario.');
        }
        // Quedarse sin nadie que pueda administrar dejaría el panel inaccesible.
        if ((int) $empleado['activo'] === 1
            && in_array($empleado['rol'], ['direccion', 'admin'], true)
            && $this->userModel->contarGestoresActivos($idEmpleado) === 0) {
            $this->irAConError('admin_empleados', 'Es el único usuario con permisos de administración: no se puede bloquear.');
        }

        if (!$this->userModel->toggleActivo($idEmpleado)) {
            $this->irAConError('admin_empleados', 'El empleado no existe.');
        }

        $this->registrarLogSobre(
            (int) $empleado['activo'] === 1 ? 'Acceso bloqueado' : 'Acceso restablecido',
            $idEmpleado,
            trim($empleado['nombre'] . ' ' . $empleado['apellidos']),
            (int) $empleado['activo'] === 1 ? 'activo' : 'bloqueado',
            (int) $empleado['activo'] === 1 ? 'bloqueado' : 'activo',
            'empleado'
        );

        $this->irA('admin_empleados', ['ok_estado' => 1]);
    }

    /* ---------------------------------------------------------------------
     * Domiciliación SEPA
     * ------------------------------------------------------------------ */

    public function mostrarRemesas(): void
    {
        $this->requirePermission('remesas.manage');
        $pageTitle    = 'Domiciliaciones';
        $paginaActiva = 'remesas';

        $mensajeExito = '';
        $errorRemesa  = $_GET['err'] ?? '';

        if (isset($_GET['ok_acreedor'])) $mensajeExito = 'Datos bancarios del gimnasio guardados.';
        if (isset($_GET['ok_remesa']))   $mensajeExito = 'Remesa creada. Descarga el fichero y súbelo a tu banca electrónica.';
        if (isset($_GET['ok_enviada']))  $mensajeExito = 'Remesa marcada como enviada al banco.';
        if (isset($_GET['ok_cobrada']))  $mensajeExito = 'Remesa marcada como cobrada.';
        if (isset($_GET['ok_devuelto'])) $mensajeExito = 'Recibo marcado como devuelto. Volverá a estar pendiente de cobro.';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!Csrf::validarPost()) {
                $errorRemesa = 'La sesión ha caducado. Vuelve a intentarlo.';
            } else {
                $accion = $_POST['accion'] ?? '';

                if ($accion === 'guardar_acreedor') {
                    $sede = $this->gimnasioActual();
                    if ($sede === null) {
                        $this->irAConError('admin_remesas', 'Elige una sede concreta para configurar sus datos bancarios.');
                    }
                    $ibanAcreedor = Iban::normalizar($_POST['iban'] ?? '');
                    if ($ibanAcreedor !== '' && !Iban::esValido($ibanAcreedor)) {
                        $this->irAConError('admin_remesas', 'El IBAN del gimnasio no es válido.');
                    }
                    $this->sepaModel->guardarAcreedor($sede, [
                        'razon_social'           => trim($_POST['razon_social'] ?? ''),
                        'cif'                    => trim($_POST['cif'] ?? ''),
                        'iban'                   => $ibanAcreedor,
                        'bic'                    => trim($_POST['bic'] ?? ''),
                        'identificador_acreedor' => trim($_POST['identificador_acreedor'] ?? ''),
                    ]);
                    $this->registrarLog('Datos bancarios', 'Actualizados los datos de acreedor SEPA');
                    $this->irA('admin_remesas', ['ok_acreedor' => 1]);
                }

                if ($accion === 'crear_remesa') {
                    // La remesa se emite con el IBAN de una sede: sin elegirla
                    // no se sabe con qué datos bancarios se cobraría.
                    $this->exigirSedeFijada('admin_remesas');
                    if (!$this->sepaModel->acreedorCompleto()) {
                        $this->irAConError('admin_remesas', 'Antes de crear una remesa hay que rellenar los datos bancarios del gimnasio.');
                    }
                    $seleccion  = $_POST['membresias'] ?? [];
                    $concepto   = trim($_POST['concepto'] ?? '') ?: ('Cuota ' . date('m/Y'));
                    $fechaCobro = trim($_POST['fecha_cobro'] ?? '');
                    $operacionId = preg_match('/^[a-f0-9]{32}$/', (string) ($_POST['_operation_id'] ?? '')) ? (string) $_POST['_operation_id'] : null;
                    if (!$this->esFechaValida($fechaCobro)) {
                        $fechaCobro = date('Y-m-d', strtotime('+3 days'));
                    }

                    $error = '';
                    $idRemesa = $this->sepaModel->crearRemesa(
                        is_array($seleccion) ? $seleccion : [],
                        $concepto, $fechaCobro,
                        (int) ($_SESSION['usuario_id'] ?? 0),
                        $error,
                        $operacionId
                    );

                    if ($idRemesa === null) {
                        $this->irAConError('admin_remesas', $error);
                    }
                    $remesa = $this->sepaModel->buscarRemesa($idRemesa);
                    $this->registrarLog(
                        'Remesa SEPA',
                        'Remesa #' . $idRemesa . ' — ' . (int) $remesa['num_recibos'] . ' recibos, '
                            . number_format((float) $remesa['importe_total'], 2, ',', '.') . ' €'
                    );
                    $this->irA('admin_remesas', ['ok_remesa' => 1]);
                }

                if ($accion === 'marcar_enviada' || $accion === 'marcar_cobrada') {
                    $idRemesa = (int) ($_POST['id_remesa'] ?? 0);
                    if ($idRemesa > 0 && $this->sepaModel->buscarRemesa($idRemesa)) {
                        if ($accion === 'marcar_enviada') {
                            $this->sepaModel->marcarEnviada($idRemesa);
                            $this->registrarLog('Remesa SEPA', 'Remesa #' . $idRemesa . ' enviada al banco');
                            $flag = ['ok_enviada' => 1];
                        } else {
                            $this->sepaModel->marcarCobrada($idRemesa);
                            $this->registrarLog('Remesa SEPA', 'Remesa #' . $idRemesa . ' cobrada');
                            $flag = ['ok_cobrada' => 1];
                        }
                        $this->irA('admin_remesas', $flag);
                    }
                }

                if ($accion === 'marcar_devuelto') {
                    $idRecibo = (int) ($_POST['id_recibo'] ?? 0);
                    $motivo   = trim($_POST['motivo'] ?? '') ?: 'Sin motivo indicado';
                    if ($idRecibo > 0) {
                        if (!$this->sepaModel->marcarDevuelto($idRecibo, $motivo)) {
                            $this->irAConError('admin_remesas', 'Ese recibo no existe o no es de esta sede.');
                        }
                        $this->registrarLog('Recibo devuelto', 'Recibo #' . $idRecibo . ' — ' . $motivo);
                        $this->irA('admin_remesas', ['ok_devuelto' => 1]);
                    }
                }
            }
        }

        $acreedor      = $this->sepaModel->acreedor();
        $datosListos   = $this->sepaModel->acreedorCompleto();
        $pendientes    = $this->sepaModel->listarDomiciliablesPendientes();
        $remesas       = $this->sepaModel->listarRemesas(30);
        $devueltos     = $this->sepaModel->listarDevueltos();
        $mandatos      = $this->sepaModel->listarMandatos();
        $idRemesaAbierta = (int) ($_GET['remesa'] ?? 0);
        $recibosAbiertos = $idRemesaAbierta > 0 ? $this->sepaModel->listarRecibos($idRemesaAbierta) : [];
        $totalPendiente  = array_sum(array_map(function ($p) { return (float) $p['importe']; }, $pendientes));

        require __DIR__ . '/../views/admin/remesas.php';
    }

    /** Descarga el fichero XML que se sube a la banca electrónica. */
    public function descargarRemesa(): void
    {
        $this->requirePermission('remesas.manage');

        // Con "todas las sedes" no hay unos datos bancarios que usar, y antes se
        // cogían los del primer gimnasio de la tabla. Mejor pedir que se elija.
        $this->exigirSedeFijada('admin_remesas');

        $idRemesa = (int) ($_GET['id'] ?? 0);
        $remesa   = $idRemesa > 0 ? $this->sepaModel->buscarRemesa($idRemesa) : null;
        $acreedor = $this->sepaModel->acreedor();

        if (!$remesa || !$acreedor) {
            $this->irAConError('admin_remesas', 'La remesa no existe.');
        }

        $recibos = $this->sepaModel->listarRecibos($idRemesa);
        if (empty($recibos)) {
            $this->irAConError('admin_remesas', 'La remesa no tiene recibos.');
        }

        $xml = SepaXml::generar(
            [
                'nombre'                 => $acreedor['razon_social'] ?: $acreedor['nombre'],
                'iban'                   => $acreedor['iban'],
                'bic'                    => $acreedor['bic'],
                'identificador_acreedor' => $acreedor['identificador_acreedor'],
            ],
            $remesa,
            $recibos
        );

        $nombre = 'remesa_' . $idRemesa . '_' . $remesa['fecha_cobro'] . '.xml';

        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/xml; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $nombre . '"');
        header('Content-Length: ' . strlen($xml));
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

        echo $xml;

        $this->registrarLog('Remesa SEPA', 'Descargado el fichero de la remesa #' . $idRemesa);
        exit;
    }

    /** Registra el mandato firmado por un socio. */
    public function crearMandato(): void
    {
        $this->requirePermission('mandatos.create');
        $navegacion = $this->navegacionSocios($_POST);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Csrf::validarPost()) {
            $this->irA('admin_socios', $navegacion);
        }

        // El mandato queda a nombre de una sede concreta: es la que cobrará.
        $this->exigirSedeFijada('admin_socios', $navegacion);

        $idSocio    = (int) ($_POST['id_socio'] ?? 0);
        $fechaFirma = trim($_POST['fecha_firma'] ?? '') ?: date('Y-m-d');
        $socio      = $idSocio > 0 ? $this->userModel->buscarPorId($idSocio) : null;

        if (!$socio || ($socio['rol'] ?? '') !== 'socio') {
            $this->irAConError('admin_socios', 'El socio no existe.', $navegacion);
        }
        if (!$this->esFechaValida($fechaFirma)) {
            $fechaFirma = date('Y-m-d');
        }

        $iban = Iban::normalizar($_POST['iban'] ?? '') ?: ($socio['iban'] ?? '');
        if ($iban === '') {
            $this->irAConError('admin_socios', 'Hace falta el IBAN para firmar el mandato.', $navegacion);
        }

        $error = '';
        $idMandato = $this->sepaModel->crearMandato($idSocio, $iban, $fechaFirma, $error);

        if ($idMandato === null) {
            $this->irAConError('admin_socios', $error, $navegacion);
        }

        // El IBAN del mandato pasa a ser el de la ficha del socio.
        $this->userModel->actualizarIban($idSocio, Iban::normalizar($iban));

        $mandato = $this->sepaModel->mandatoActivo($idSocio);
        $this->registrarLogSobre(
            'Mandato SEPA firmado',
            $idSocio,
            trim(($socio['nombre'] ?? '') . ' ' . ($socio['apellidos'] ?? ''))
                . ' — ref. ' . ($mandato['referencia'] ?? ''),
            'sin mandato',
            Iban::enmascarar($iban)
        );

        $this->irA('admin_socios', array_merge($navegacion, ['ok_mandato' => 1]));
    }

    /* ---------------------------------------------------------------------
     * Importaciones masivas
     * ------------------------------------------------------------------ */

    public function mostrarImportaciones(): void
    {
        $this->requirePermission('migrations.manage');
        $pageTitle = 'Importaciones';
        $paginaActiva = 'migraciones';
        $errorImportacion = InputValidator::text($_GET['err'] ?? '', 500, false) ?? '';
        $mensajeImportacion = '';
        if (isset($_GET['subida'])) $mensajeImportacion = 'Archivo recibido. Revisa el mapeo y ejecuta el dry-run.';
        if (isset($_GET['repetido'])) $mensajeImportacion = 'Ese archivo ya fue procesado. Se muestra el batch existente.';
        if (isset($_GET['simulado'])) $mensajeImportacion = 'Dry-run completado. No se modificaron socios ni productos.';
        if (isset($_GET['importado'])) $mensajeImportacion = 'Importación completada y archivo temporal eliminado.';
        if (isset($_GET['descartado'])) $mensajeImportacion = 'Batch descartado y archivo temporal eliminado.';
        $servicio = $this->migraciones();
        $sedeActivaImportacion = $this->tenant->sedeId();
        $batches = $servicio->listBatches();
        $reporte = null;
        $uuid = (string) ($_GET['batch'] ?? '');
        if ($uuid !== '') {
            try {
                $reporte = $servicio->report($uuid);
            } catch (MigrationException $e) {
                $errorImportacion = $e->getMessage();
            }
        }
        $camposSocios = ImportFieldMapper::fields('socios');
        $camposProductos = ImportFieldMapper::fields('productos');
        $camposMembresias = ImportFieldMapper::fields('membresias');
        require __DIR__ . '/../views/admin/importaciones.php';
    }

    public function subirImportacion(): void
    {
        $this->requirePermission('migrations.manage');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Csrf::validarPost()) {
            $this->irAConError('admin_importaciones', 'Solicitud no válida.');
        }
        try {
            $entity = (string) ($_POST['entity_type'] ?? '');
            $source = strtolower(trim((string) ($_POST['source_system'] ?? 'generic')));
            $file = $_FILES['archivo'] ?? [];
            $batch = $this->migraciones()->createFromUpload(
                $entity,
                $source,
                (string) ($file['name'] ?? ''),
                $file
            );
            $flag = !empty($batch['already_processed']) ? 'repetido' : 'subida';
            $this->irA('admin_importaciones', ['batch'=>$batch['uuid'],$flag=>1]);
        } catch (MigrationException $e) {
            $this->irAConError('admin_importaciones', $e->getMessage());
        } catch (Throwable $e) {
            AppLogger::error('migration_upload_failed', ['company_id'=>$this->tenant->empresaId(),'reason'=>$e->getMessage()]);
            $this->irAConError('admin_importaciones', 'No se pudo preparar el archivo.');
        }
    }

    public function simularImportacion(): void
    {
        $this->requirePermission('migrations.manage');
        $uuid = (string) ($_POST['batch'] ?? '');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Csrf::validarPost()) {
            $this->irAConError('admin_importaciones', 'Solicitud no válida.', ['batch'=>$uuid]);
        }
        $mapping = [];
        $externals = is_array($_POST['external'] ?? null) ? $_POST['external'] : [];
        $internals = is_array($_POST['internal'] ?? null) ? $_POST['internal'] : [];
        foreach ($externals as $i => $external) {
            if (is_string($external)) $mapping[$external] = is_string($internals[$i] ?? null) ? $internals[$i] : '';
        }
        try {
            $this->migraciones()->dryRun($uuid, $mapping, ['date_format'=>$_POST['date_format'] ?? 'Y-m-d']);
            $this->irA('admin_importaciones', ['batch'=>$uuid,'simulado'=>1]);
        } catch (MigrationException $e) {
            $this->irAConError('admin_importaciones', $e->getMessage(), ['batch'=>$uuid]);
        } catch (Throwable $e) {
            AppLogger::error('migration_dry_run_failed', ['batch'=>$uuid,'company_id'=>$this->tenant->empresaId(),'reason'=>$e->getMessage()]);
            $this->irAConError('admin_importaciones', 'No se pudo completar el dry-run.', ['batch'=>$uuid]);
        }
    }

    public function confirmarImportacion(): void
    {
        $this->requirePermission('migrations.manage');
        $uuid = (string) ($_POST['batch'] ?? '');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Csrf::validarPost()) {
            $this->irAConError('admin_importaciones', 'Solicitud no válida.', ['batch'=>$uuid]);
        }
        try {
            $this->migraciones()->confirm($uuid);
            $this->irA('admin_importaciones', ['batch'=>$uuid,'importado'=>1]);
        } catch (MigrationException $e) {
            $this->irAConError('admin_importaciones', $e->getMessage(), ['batch'=>$uuid]);
        } catch (Throwable $e) {
            AppLogger::error('migration_confirm_failed', ['batch'=>$uuid,'company_id'=>$this->tenant->empresaId(),'reason'=>$e->getMessage()]);
            $this->irAConError('admin_importaciones', 'No se pudo completar la importación.', ['batch'=>$uuid]);
        }
    }

    public function descartarImportacion(): void
    {
        $this->requirePermission('migrations.manage');
        $uuid = (string) ($_POST['batch'] ?? '');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Csrf::validarPost()) {
            $this->irAConError('admin_importaciones', 'Solicitud no válida.', ['batch'=>$uuid]);
        }
        try {
            $this->migraciones()->discard($uuid);
            $this->irA('admin_importaciones', ['descartado'=>1]);
        } catch (MigrationException $e) {
            $this->irAConError('admin_importaciones', $e->getMessage(), ['batch'=>$uuid]);
        } catch (Throwable $e) {
            AppLogger::error('migration_discard_failed', ['batch'=>$uuid,'company_id'=>$this->tenant->empresaId(),'reason'=>$e->getMessage()]);
            $this->irAConError('admin_importaciones', 'No se pudo descartar el batch.', ['batch'=>$uuid]);
        }
    }

    /* ---------------------------------------------------------------------
     * Reportes
     * ------------------------------------------------------------------ */

    public function mostrarReportes(): void
    {
        $this->requirePermission('informes.view');
        $pageTitle    = 'Reportes';
        $paginaActiva = 'reportes';

        $desde = trim($_GET['desde'] ?? date('Y-m-01'));
        $hasta = trim($_GET['hasta'] ?? date('Y-m-d'));
        if (!$this->esFechaValida($desde)) $desde = date('Y-m-01');
        if (!$this->esFechaValida($hasta)) $hasta = date('Y-m-d');
        if ($desde > $hasta) [$desde, $hasta] = [$hasta, $desde];

        $ventasHoy       = $this->ventaModel->sumarDelDia();
        $numVentasHoy    = $this->ventaModel->contarDelDia();
        $ventasMes       = $this->ventaModel->sumarDelMes();
        $ventasRango     = $this->ventaModel->listarPorRango($desde, $hasta);
        $totalRango      = $this->sumarActivas($ventasRango);
        $porMetodo       = $this->ventaModel->sumarPorMetodoPago($desde, $hasta);
        $topProductos    = $this->ventaModel->topProductos($desde, $hasta, 10);
        $bajoStock       = $this->productoModel->listarBajoStock();
        $valorInventario = $this->productoModel->valorInventario();
        $porVencer       = $this->membresiaModel->listarProximasAVencer(30);
        $membresiasActivas = $this->membresiaModel->contarActivas();
        $membresiasVencidas = $this->membresiaModel->contarVencidas();
        $ingresosMembresias = $this->membresiaModel->sumarIngresosDelMes();

        require __DIR__ . '/../views/admin/reportes.php';
    }

    /**
     * Prepara un valor para el CSV.
     *
     * Excel y LibreOffice interpretan como fórmula cualquier celda que empiece
     * por = + - o @. Un producto llamado `=HYPERLINK(...)` se ejecutaría al
     * abrir el archivo exportado, así que se antepone un apóstrofo, que las
     * hojas de cálculo entienden como "esto es texto".
     */
    private function celdaCsv($valor): string
    {
        $valor = (string) $valor;
        return preg_match('/^[=+\-@\t\r]/', $valor) ? "'" . $valor : $valor;
    }

    /** Suma el importe de las ventas que siguen vivas, ignorando las anuladas. */
    private function sumarActivas(array $ventas): float
    {
        $total = 0.0;
        foreach ($ventas as $v) {
            if (($v['estado'] ?? 'activa') === 'activa') {
                $total += (float) $v['total'];
            }
        }
        return $total;
    }

    /** Valida que una cadena sea una fecha real en formato Y-m-d. */
    private function esFechaValida(string $fecha): bool
    {
        $d = DateTime::createFromFormat('Y-m-d', $fecha);
        return $d !== false && $d->format('Y-m-d') === $fecha;
    }

    /** Atajo para registrar en el log con el id del usuario en sesión. */
    private function registrarLog(string $accion, string $detalle): void
    {
        $log = new LogModel($this->tenant->empresaId());
        $log->registrarCambio(
            (int) ($_SESSION['usuario_id'] ?? 0), $accion, $detalle,
            null, null, null, null, null, $this->gimnasioActual()
        );
    }

    /**
     * Registra una acción hecha SOBRE una persona, guardando el valor anterior
     * y el nuevo. Es lo que permite leer después "Dani cambió el vencimiento de
     * Omar del 30/08 al 30/09" sin depender de cómo se redactó el detalle.
     */
    private function registrarLogSobre(
        string $accion,
        int $idAfectado,
        string $detalle = '',
        ?string $valorAnterior = null,
        ?string $valorNuevo = null,
        string $entidad = 'socio'
    ): void {
        $log = new LogModel($this->tenant->empresaId());
        $log->registrarCambio(
            (int) ($_SESSION['usuario_id'] ?? 0), $accion, $detalle,
            $idAfectado, $entidad, $idAfectado,
            $valorAnterior, $valorNuevo, $this->gimnasioActual()
        );
    }

    public function mostrarLog(): void {
        $this->requirePermission('auditoria.view');
        $pageTitle    = 'Historial de actividad';
        $paginaActiva = 'log';

        $filtros = [
            'id_usuario'  => (int) ($_GET['autor'] ?? 0) ?: null,
            'id_afectado' => (int) ($_GET['afectado'] ?? 0) ?: null,
            'buscar'      => trim($_GET['buscar'] ?? ''),
            'desde'       => trim($_GET['desde'] ?? ''),
            'hasta'       => trim($_GET['hasta'] ?? ''),
        ];
        if ($filtros['desde'] !== '' && !$this->esFechaValida($filtros['desde'])) $filtros['desde'] = '';
        if ($filtros['hasta'] !== '' && !$this->esFechaValida($filtros['hasta'])) $filtros['hasta'] = '';

        $logModel = new LogModel($this->tenant->empresaId());
        $logs     = $logModel->listar(200, $this->gimnasioActual(), $filtros);
        $autores  = $logModel->listarAutores($this->gimnasioActual());

        require __DIR__ . '/../views/admin/log.php';
    }

    public function exportarVentasCSV(): void
    {
        $this->requirePermission('informes.export');

        $desde = trim($_GET['desde'] ?? date('Y-m-01'));
        $hasta = trim($_GET['hasta'] ?? date('Y-m-d'));
        if (!$this->esFechaValida($desde)) $desde = date('Y-m-01');
        if (!$this->esFechaValida($hasta)) $hasta = date('Y-m-d');
        if ($desde > $hasta) [$desde, $hasta] = [$hasta, $desde];

        $ventas   = $this->ventaModel->listarPorRango($desde, $hasta);
        $filename = 'ventas_' . $desde . '_a_' . $hasta . '.csv';

        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, [
            'Ticket', 'Fecha', 'Socio', 'Detalle', 'Método de pago',
            'Base (€)', 'IVA (€)', 'Total (€)', 'Estado',
        ], ';');
        foreach ($ventas as $v) {
            $socio = trim(($v['nombre_socio'] ?? '') . ' ' . ($v['apellidos_socio'] ?? ''));
            fputcsv($out, array_map([$this, 'celdaCsv'], [
                VentaModel::referencia($v),
                $v['fecha']       ?? '',
                $socio !== '' ? $socio : 'Cliente de paso',
                $v['detalle']     ?? '',
                $v['metodo_pago'] ?? '',
                number_format((float) ($v['base_imponible'] ?? 0), 2, ',', ''),
                number_format((float) ($v['total_iva'] ?? 0), 2, ',', ''),
                number_format((float) ($v['total'] ?? 0), 2, ',', ''),
                ($v['estado'] ?? 'activa') === 'anulada' ? 'ANULADA' : 'Activa',
            ]), ';');
        }
        fclose($out);

        $this->registrarLog('Exportar ventas CSV', 'rango ' . $desde . ' a ' . $hasta . ' (' . count($ventas) . ' ventas)');
        exit;
    }

}
