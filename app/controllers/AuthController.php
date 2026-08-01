<?php
/**
 * AuthController — acceso al panel de gestión.
 *
 * El acceso tiene dos pasos:
 *   1. `login`          pantalla de la plataforma: se elige el gimnasio.
 *   2. `login_gimnasio` pantalla del gimnasio elegido, con su logo y sus
 *                       colores, donde entra su equipo.
 *
 * Al autenticar se comprueba que la persona pertenece a esa sede. El
 * la empresa es la excepción: no está asignado a ninguna y entra por cualquiera.
 *
 * Secciones de este archivo (en orden):
 *   1. Inicialización y sesión   (__construct, sessionStart, sessionLogin,
 *                                 sessionLogout, requireLogin, redirigirSegunRol)
 *   2. Mensajes flash            (setFlash, getFlash, flash)
 *   3. Acceso en dos pasos       (mostrarLogin, mostrarLoginGimnasio, autenticar, cerrarSesion)
 *   4. Recuperar contraseña      (mostrarOlvideContrasena, procesarOlvideContrasena,
 *                                 mostrarResetContrasena, procesarResetContrasena)
 *   5. Perfil propio             (mostrarPerfil, actualizarPerfil)
 *
 * Aquí ya no hay portal de socios ni panel de profesor: el acceso web es solo
 * para empresa, admin y recepción. Los socios existen como datos del
 * negocio (membresías, ventas, domiciliaciones), pero no inician sesión.
 */

require_once __DIR__ . '/../models/UserModel.php';
require_once __DIR__ . '/../models/BlackList.php';
require_once __DIR__ . '/../models/GimnasioModel.php';
require_once __DIR__ . '/../helpers/Csrf.php';
require_once __DIR__ . '/../helpers/Sesion.php';
require_once __DIR__ . '/../helpers/Mailer.php';

class AuthController {

    /** Roles con acceso al panel. */
    private const ROLES_PANEL = ['empresa', 'admin', 'recepcion'];

    private $userModel;
    private $gimnasioModel;
    private $blacklist;

    public function __construct() {
        $this->userModel     = new UserModel();
        $this->gimnasioModel = new GimnasioModel();
        $this->blacklist     = new Blacklist(Database::getInstance()->getConnection());
        $this->sessionStart();
    }

    /* --- Sesión ----------------------------------------------------------- */

    private function sessionStart(): void {
        Sesion::iniciar();
    }

    /**
     * El gimnasio se deduce del usuario: la pantalla anterior solo sirve para
     * saber qué marca mostrar, nunca para decidir permisos.
     */
    private function sessionLogin(array $user): void {
        session_regenerate_id(true);

        $rol        = $user['rol'] ?? 'socio';
        $idGimnasio = isset($user['id_gimnasio']) ? (int) $user['id_gimnasio'] : null;

        // Momento en que nace la sesión: es con lo que se compara al cambiar la
        // contraseña para saber qué sesiones hay que echar (ver Sesion.php).
        $_SESSION['iniciada_en']         = time();
        $_SESSION['usuario_id']          = (int) $user['id_usuario'];
        $_SESSION['usuario_nombre']      = $user['nombre_usuario'];
        $_SESSION['usuario_rol']         = $rol;
        $_SESSION['usuario_nombre_real'] = trim(($user['nombre'] ?? '') . ' ' . ($user['apellidos'] ?? ''));
        $_SESSION['usuario_foto']        = $user['foto'] ?? null;
        $_SESSION['logueado']            = true;
        $_SESSION['ultimo_acceso']       = time();
        $_SESSION['gimnasio_id']         = $rol === 'empresa' ? null : $idGimnasio;
        $_SESSION['gimnasio_nombre']     = '';
        // El logo se guarda aquí para que la cabecera del panel lo pinte sin
        // volver a consultar la ficha en cada página. La empresa no fija sede,
        // así que se queda con el logo de la instalación.
        $_SESSION['gimnasio_logo']       = '';

        if (!empty($_SESSION['gimnasio_id'])) {
            $sede = $this->gimnasioModel->buscarPorId((int) $_SESSION['gimnasio_id']);
            $_SESSION['gimnasio_nombre'] = $sede['nombre'] ?? '';
            $_SESSION['gimnasio_logo']   = $sede['logo'] ?? '';
        }
    }

    private function sessionLogout(): void {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    private function isLoggedIn(): bool {
        return isset($_SESSION['logueado']) && $_SESSION['logueado'] === true;
    }

    private function requireLogin(): void {
        if (!$this->isLoggedIn()) {
            $this->redirigir('login');
        }
    }

    private function redirigir(string $action): void {
        header('Location: ' . APP_URL . '/index.php?action=' . $action);
        exit;
    }

    private function redirigirSegunRol(): void {
        $this->redirigir('admin');
    }

    /* --- Mensajes flash ---------------------------------------------------- */

    private function setFlash(string $clave, $valor): void {
        $_SESSION['flash'][$clave] = $valor;
    }

    private function getFlash(string $clave) {
        if (!isset($_SESSION['flash'][$clave])) return null;
        $valor = $_SESSION['flash'][$clave];
        unset($_SESSION['flash'][$clave]);
        return $valor;
    }

    public function flash(string $clave) {
        return $this->getFlash($clave);
    }

    /* --- Acceso en dos pasos ----------------------------------------------- */

    /** Paso 1: el gimnasio se identifica con su email y contraseña. */
    public function mostrarLogin(): void {
        if ($this->isLoggedIn()) {
            $this->redirigirSegunRol();
        }
        // Si el gimnasio ya se identificó, se pasa directo a sus empleados.
        if (!empty($_SESSION['gimnasio_auth_id'])) {
            $this->redirigir('login_gimnasio');
        }

        $pageTitle = 'Acceso';
        $errores   = $this->getFlash('errores') ?? [];
        $exito     = $this->getFlash('exito');
        $old       = $this->getFlash('old') ?? [];

        require __DIR__ . '/../views/auth/login_plataforma.php';
    }

    /** Valida las credenciales del gimnasio y abre el segundo paso. */
    public function autenticarGimnasio(): void {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !Csrf::validarPost()) {
            $this->setFlash('errores', ['La sesión ha caducado. Vuelve a intentarlo.']);
            $this->redirigir('login');
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirigir('login');
        }

        $email      = trim(strtolower($_POST['email'] ?? ''));
        $contrasena =      $_POST['contrasena'] ?? '';
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        $fallar = function (string $mensaje) use ($email) {
            $this->setFlash('errores', [$mensaje]);
            $this->setFlash('old', ['email' => $email]);
            $this->redirigir('login');
        };

        if ($this->intentosGimnasioBloqueado($ip)) {
            $fallar('Demasiados intentos fallidos. Espera 15 minutos.');
        }
        if ($email === '' || $contrasena === '') {
            $fallar('Introduce el email y la contraseña del gimnasio.');
        }

        $gimnasio = $this->gimnasioModel->autenticar($email, $contrasena);

        if (!$gimnasio) {
            $this->registrarIntentoGimnasio($ip, $email);
            // Mensaje único: distinguir "email no existe" de "contraseña
            // incorrecta" permitiría averiguar qué gimnasios están dados de alta.
            $fallar('Email o contraseña del gimnasio incorrectos.');
        }

        session_regenerate_id(true);
        $_SESSION['gimnasio_auth_id']     = (int) $gimnasio['id_gimnasio'];
        $_SESSION['gimnasio_auth_nombre'] = $gimnasio['nombre'];

        $this->redirigir('login_gimnasio');
    }

    /** Paso 2: los empleados de ese gimnasio, con su logo y sus colores. */
    public function mostrarLoginGimnasio(): void {
        if ($this->isLoggedIn()) {
            $this->redirigirSegunRol();
        }

        $idGimnasio = (int) ($_SESSION['gimnasio_auth_id'] ?? 0);
        $gimnasio   = $idGimnasio > 0 ? $this->gimnasioModel->buscarPorId($idGimnasio) : null;

        // Sin haber pasado el primer nivel no se llega aquí.
        if (!$gimnasio || (int) $gimnasio['activo'] !== 1) {
            unset($_SESSION['gimnasio_auth_id'], $_SESSION['gimnasio_auth_nombre']);
            $this->setFlash('errores', ['Identifica primero el gimnasio.']);
            $this->redirigir('login');
        }

        $pageTitle = 'Acceso — ' . $gimnasio['nombre'];
        $errores   = $this->getFlash('errores') ?? [];
        $exito     = $this->getFlash('exito');
        $old       = $this->getFlash('old') ?? [];

        require __DIR__ . '/../views/auth/login.php';
    }

    /**
     * Salida completa: cierra la sesión del empleado, si la hubiera, y también
     * la del gimnasio. Es lo que hay que usar al cerrar el local.
     */
    public function salirGimnasio(): void {
        $this->sessionLogout();
        $this->redirigir('login');
    }

    /* --- Intentos fallidos contra el acceso de gimnasio -------------------- */

    private function registrarIntentoGimnasio(string $ip, string $email): void {
        try {
            $db = Database::getInstance()->getConnection();
            $db->prepare("INSERT INTO intentos_gimnasio (ip_address, email) VALUES (:ip, :email)")
               ->execute([':ip' => $ip, ':email' => mb_substr($email, 0, 255)]);
        } catch (\PDOException $e) {
            error_log('registrarIntentoGimnasio error: ' . $e->getMessage());
        }
    }

    private function intentosGimnasioBloqueado(string $ip, int $maximo = 8, int $minutos = 15): bool {
        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM intentos_gimnasio
                 WHERE ip_address = :ip AND fecha_intento > DATE_SUB(NOW(), INTERVAL :min MINUTE)"
            );
            $stmt->bindValue(':ip', $ip);
            $stmt->bindValue(':min', $minutos, PDO::PARAM_INT);
            $stmt->execute();
            return (int) $stmt->fetchColumn() >= $maximo;
        } catch (\PDOException $e) {
            error_log('intentosGimnasioBloqueado error: ' . $e->getMessage());
            return false;
        }
    }

    public function autenticar(): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirigir('login');
        }
        // La cookie es SameSite=Lax, que ya frena el grueso de los envíos desde
        // otro sitio. El testigo es el cinturón: cuesta una línea y cierra el
        // hueco de que alguien te meta en una sesión que no es tuya.
        if (!Csrf::validarPost()) {
            $this->setFlash('errores', ['La sesión ha caducado. Vuelve a intentarlo.']);
            $this->redirigir('login_gimnasio');
        }

        $usuario    = trim($_POST['usuario']    ?? '');
        $contrasena =      $_POST['contrasena'] ?? '';
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        // El gimnasio sale de la sesión, nunca del formulario: así no se puede
        // saltar el primer nivel manipulando un campo oculto.
        $idGimnasio = (int) ($_SESSION['gimnasio_auth_id'] ?? 0);
        $gimnasio   = $idGimnasio > 0 ? $this->gimnasioModel->buscarPorId($idGimnasio) : null;

        if (!$gimnasio) {
            $this->setFlash('errores', ['Identifica primero el gimnasio.']);
            $this->redirigir('login');
        }

        $fallar = function (string $mensaje) use ($usuario) {
            $this->setFlash('errores', [$mensaje]);
            $this->setFlash('old', ['usuario' => $usuario]);
            $this->redirigir('login_gimnasio');
        };

        if ($this->blacklist->estaBloqueado($ip, $usuario)) {
            $fallar('Cuenta bloqueada por múltiples intentos fallidos. Inténtalo de nuevo en 15 minutos.');
        }
        if ($usuario === '' || $contrasena === '') {
            $fallar('Introduce usuario y contraseña.');
        }

        $user = $this->userModel->buscarPorUsuario($usuario);

        if (!$user || !password_verify($contrasena, $user['contrasena'])) {
            $this->blacklist->registrarIntentoFallido($ip, $usuario);
            $restantes = $this->blacklist->getIntentosRestantes($ip, $usuario);
            $fallar($restantes > 0
                ? 'Usuario o contraseña incorrectos. Te quedan ' . $restantes . ' intentos.'
                : 'Cuenta bloqueada por múltiples intentos fallidos.');
        }

        // Solo el personal entra al panel. Los socios no tienen acceso web.
        if (!in_array($user['rol'] ?? '', self::ROLES_PANEL, true)) {
            $fallar('Esta cuenta no tiene acceso al panel de gestión.');
        }
        if ((int) ($user['activo'] ?? 1) !== 1) {
            $fallar('Tu acceso está bloqueado. Habla con la administración del gimnasio.');
        }

        // El empleado tiene que ser de este gimnasio. La empresa es la
        // excepción: no está asignado a ninguno y entra por cualquiera.
        if (($user['rol'] ?? '') !== 'empresa'
            && (int) ($user['id_gimnasio'] ?? 0) !== (int) $gimnasio['id_gimnasio']) {
            $this->blacklist->registrarIntentoFallido($ip, $usuario);
            $fallar('Esta cuenta no pertenece a ' . $gimnasio['nombre'] . '.');
        }

        $this->sessionLogin($user);
        $_SESSION['mantener_sesion'] = isset($_POST['mantener_sesion']);

        // El gimnasio identificado se conserva durante toda la sesión: es lo
        // que permite que al salir se vuelva a su pantalla y no a la inicial.
        $_SESSION['gimnasio_auth_id']     = (int) $gimnasio['id_gimnasio'];
        $_SESSION['gimnasio_auth_nombre'] = $gimnasio['nombre'];

        $this->redirigirSegunRol();
    }

    /**
     * Cierra la sesión del empleado pero mantiene identificado al gimnasio.
     *
     * Es el caso normal en un mostrador: acaba el turno de uno y entra otro.
     * Volver a pedir el email y la contraseña del gimnasio en cada relevo sería
     * un estorbo, y acabaría con la contraseña apuntada al lado del teclado.
     *
     * Para salir también del gimnasio está `salir_gimnasio`.
     */
    public function cerrarSesion(): void {
        $idGimnasio = (int) ($_SESSION['gimnasio_auth_id'] ?? $_SESSION['gimnasio_id'] ?? 0);
        $gimnasio   = $idGimnasio > 0 ? $this->gimnasioModel->buscarPorId($idGimnasio) : null;

        // No se destruye la sesión: se vacía y se deja solo el gimnasio. El id
        // se regenera igualmente para que la sesión del empleado no se reutilice.
        $_SESSION = [];
        session_regenerate_id(true);

        if ($gimnasio && (int) $gimnasio['activo'] === 1) {
            $_SESSION['gimnasio_auth_id']     = (int) $gimnasio['id_gimnasio'];
            $_SESSION['gimnasio_auth_nombre'] = $gimnasio['nombre'];
            $this->setFlash('exito', 'Sesión cerrada. Puede entrar otro usuario.');
            $this->redirigir('login_gimnasio');
        }

        // Sin gimnasio válido (por ejemplo, si lo han cerrado) se vuelve al inicio.
        $this->redirigir('login');
    }

    /* --- Recuperar contraseña ----------------------------------------------- */

    public function mostrarOlvideContrasena(): void {
        if ($this->isLoggedIn()) {
            $this->redirigirSegunRol();
        }
        $pageTitle = 'Recuperar contraseña';
        $errores   = $this->getFlash('errores') ?? [];
        $exito     = $this->getFlash('exito');
        $old       = $this->getFlash('old') ?? [];

        // Se llega desde el login de una sede: se mantiene su marca y el enlace
        // de vuelta apunta a su pantalla, no al primer paso.
        $gimnasio = $this->gimnasioDeSesion();
        $volverA  = $gimnasio ? 'login_gimnasio' : 'login';

        require __DIR__ . '/../views/auth/forgot.php';
    }

    /** El gimnasio identificado en el primer paso, si sigue abierto. */
    private function gimnasioDeSesion(): ?array {
        $id = (int) ($_SESSION['gimnasio_auth_id'] ?? 0);
        if ($id <= 0) {
            return null;
        }
        $gimnasio = $this->gimnasioModel->buscarPorId($id);
        return ($gimnasio && (int) $gimnasio['activo'] === 1) ? $gimnasio : null;
    }

    public function procesarOlvideContrasena(): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirigir('password_forgot');
        }
        if (!Csrf::validarPost()) {
            $this->setFlash('errores', ['Sesión expirada. Vuelve a intentarlo.']);
            $this->redirigir('password_forgot');
        }

        $correo = trim(strtolower($_POST['correo'] ?? ''));
        if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            $this->setFlash('errores', ['Introduce un correo electrónico válido.']);
            $this->setFlash('old', ['correo' => $correo]);
            $this->redirigir('password_forgot');
        }

        // Sin límite, este formulario sirve para llenar de correos el buzón de
        // cualquiera cuya dirección se conozca. Se cuenta por IP con el mismo
        // registro que el login, usando una etiqueta propia.
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        if ($this->blacklist->estaBloqueado($ip, 'recuperar:' . $ip)) {
            // El mensaje es el mismo de siempre: decir "has pedido demasiados"
            // ya confirmaría que ese correo existe.
            $this->setFlash('exito', 'Si el correo está registrado, recibirás un enlace para restablecer tu contraseña en unos minutos.');
            $this->redirigir('password_forgot');
        }
        $this->blacklist->registrarIntentoFallido($ip, 'recuperar:' . $ip);

        $user = $this->userModel->buscarPorCorreo($correo);
        // Solo se envía enlace al personal: un socio no tiene dónde entrar.
        if ($user && in_array($user['rol'] ?? '', self::ROLES_PANEL, true)) {
            $token  = bin2hex(random_bytes(32));
            $expira = date('Y-m-d H:i:s', time() + 1800);
            if ($this->userModel->guardarTokenReset((int) $user['id_usuario'], $token, $expira)) {
                $url = APP_URL . '/index.php?action=password_reset&token=' . urlencode($token);
                $nombreCompleto = trim(($user['nombre'] ?? '') . ' ' . ($user['apellidos'] ?? ''));
                Mailer::resetContrasena($user['email'], $nombreCompleto, $url);
            }
        }

        // El mensaje es el mismo exista o no la cuenta: si cambiara, serviría
        // para averiguar qué correos están registrados.
        $this->setFlash('exito', 'Si el correo está registrado, recibirás un enlace para restablecer tu contraseña en unos minutos.');
        $this->redirigir('password_forgot');
    }

    public function mostrarResetContrasena(): void {
        if ($this->isLoggedIn()) {
            $this->redirigirSegunRol();
        }

        $token = trim($_GET['token'] ?? '');
        if ($token === '') {
            $this->setFlash('errores', ['Falta el token de recuperación.']);
            $this->redirigir('password_forgot');
        }

        $user = $this->userModel->buscarPorTokenReset($token);
        if (!$user) {
            $this->setFlash('errores', ['El enlace ha caducado o no es válido. Solicita uno nuevo.']);
            $this->redirigir('password_forgot');
        }

        $pageTitle = 'Crear nueva contraseña';
        $errores   = $this->getFlash('errores') ?? [];

        // El enlace del correo se abre en cualquier navegador, sin sesión: la
        // marca sale del gimnasio al que pertenece la cuenta del token.
        $idSede   = (int) ($user['id_gimnasio'] ?? 0);
        $gimnasio = $idSede > 0
            ? $this->gimnasioModel->buscarPorId($idSede)
            : $this->gimnasioDeSesion();

        require __DIR__ . '/../views/auth/reset.php';
    }

    public function procesarResetContrasena(): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirigir('password_forgot');
        }
        if (!Csrf::validarPost()) {
            $this->setFlash('errores', ['Sesión expirada. Vuelve a intentarlo.']);
            $this->redirigir('password_forgot');
        }

        $token      = trim($_POST['token'] ?? '');
        $contrasena =      $_POST['contrasena'] ?? '';
        $confirmar  =      $_POST['confirmar_contrasena'] ?? '';

        if ($token === '') {
            $this->setFlash('errores', ['Falta el token de recuperación.']);
            $this->redirigir('password_forgot');
        }

        $user = $this->userModel->buscarPorTokenReset($token);
        if (!$user) {
            $this->setFlash('errores', ['El enlace ha caducado o no es válido. Solicita uno nuevo.']);
            $this->redirigir('password_forgot');
        }

        $errores = [];
        if (strlen($contrasena) < 8)   $errores[] = 'La contraseña debe tener al menos 8 caracteres.';
        if ($contrasena !== $confirmar) $errores[] = 'Las contraseñas no coinciden.';

        if (!empty($errores)) {
            $this->setFlash('errores', $errores);
            header('Location: ' . APP_URL . '/index.php?action=password_reset&token=' . urlencode($token));
            exit;
        }

        $ok = $this->userModel->cambiarContrasena((int) $user['id_usuario'], $contrasena);
        $this->userModel->limpiarTokenReset((int) $user['id_usuario']);

        if ($ok) {
            $this->setFlash('exito', 'Contraseña actualizada. Ya puedes iniciar sesión.');
            $this->redirigir('login');
        }
        $this->setFlash('errores', ['No se pudo actualizar la contraseña. Inténtalo de nuevo.']);
        $this->redirigir('password_forgot');
    }

    /* --- Perfil propio ------------------------------------------------------ */

    public function mostrarPerfil(): void {
        $this->requireLogin();

        $idUsuario = (int) ($_SESSION['usuario_id'] ?? 0);
        $usuario   = $this->userModel->buscarPorId($idUsuario);
        if (!$usuario) {
            $this->sessionLogout();
            $this->redirigir('login');
        }

        $pageTitle = 'Mi perfil';
        $errores   = $this->getFlash('errores') ?? [];
        $exito     = $this->getFlash('exito');

        require __DIR__ . '/../views/auth/perfil.php';
    }

    public function actualizarPerfil(): void {
        $this->requireLogin();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Csrf::validarPost()) {
            $this->redirigir('perfil');
        }

        $idUsuario = (int) ($_SESSION['usuario_id'] ?? 0);
        $usuario   = $this->userModel->buscarPorId($idUsuario);
        if (!$usuario) {
            $this->sessionLogout();
            $this->redirigir('login');
        }

        $nombre    = trim($_POST['nombre']    ?? '');
        $apellidos = trim($_POST['apellidos'] ?? '');
        $correo    = trim(strtolower($_POST['correo'] ?? ''));
        $telefono  = trim($_POST['telefono']  ?? '') ?: null;

        $nueva     = $_POST['contrasena']           ?? '';
        $confirmar = $_POST['confirmar_contrasena'] ?? '';

        $errores = [];
        if ($nombre === '' || $apellidos === '') {
            $errores[] = 'Nombre y apellidos son obligatorios.';
        }
        if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            $errores[] = 'Correo electrónico no válido.';
        } elseif ($this->userModel->correoExisteOtroUsuario($correo, $idUsuario)) {
            $errores[] = 'Ese correo ya está registrado en otra cuenta.';
        }
        if ($nueva !== '' || $confirmar !== '') {
            if (strlen($nueva) < 8)    $errores[] = 'La contraseña debe tener al menos 8 caracteres.';
            if ($nueva !== $confirmar) $errores[] = 'Las contraseñas no coinciden.';
        }

        if (!empty($errores)) {
            $this->setFlash('errores', $errores);
            $this->redirigir('perfil');
        }

        $this->userModel->actualizarPerfil($idUsuario, $nombre, $apellidos, $telefono, $correo);

        $mensaje = 'Perfil actualizado correctamente.';

        if ($nueva !== '') {
            $this->userModel->cambiarContrasena($idUsuario, $nueva);
            // Cambiar la clave invalida las sesiones anteriores. Esta se salva
            // adelantando su marca: quien acaba de teclear la nueva contraseña
            // no tiene por qué volver a entrar.
            $_SESSION['iniciada_en'] = time();
            $mensaje = 'Perfil actualizado. Se ha cerrado tu sesión en los demás dispositivos.';
        }

        $_SESSION['usuario_nombre_real'] = trim($nombre . ' ' . $apellidos);
        $this->setFlash('exito', $mensaje);
        $this->redirigir('perfil');
    }
}
