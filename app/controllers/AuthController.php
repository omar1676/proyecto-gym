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
require_once __DIR__ . '/../helpers/TenantContext.php';
require_once __DIR__ . '/../helpers/AppLogger.php';
require_once __DIR__ . '/../helpers/Mailer.php';
require_once __DIR__ . '/../models/LogModel.php';
require_once __DIR__ . '/../services/PasswordResetDeliveryService.php';

class AuthController {

    /** Roles con acceso al panel. */
    private const ROLES_PANEL = ['superadmin', 'direccion', 'admin', 'recepcion'];

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
        $_SESSION['empresa_id']          = !empty($user['id_empresa']) ? (int) $user['id_empresa'] : null;
        $_SESSION['usuario_nombre_real'] = trim(($user['nombre'] ?? '') . ' ' . ($user['apellidos'] ?? ''));
        $_SESSION['usuario_foto']        = $user['foto'] ?? null;
        $_SESSION['logueado']            = true;
        $_SESSION['ultimo_acceso']       = time();
        $_SESSION['gimnasio_id']         = in_array($rol, ['superadmin', 'direccion'], true) ? null : $idGimnasio;
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
        if (!$this->isLoggedIn() || !TenantContext::desdeSesion()->autenticado()) {
            $this->sessionLogout();
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

    /** @param array<string,mixed>|null $user @param array<string,mixed>|null $gym */
    private function auditAuth(string $action, string $result, ?array $user = null, ?array $gym = null, ?string $reason = null): void
    {
        $company = (int) ($user['id_empresa'] ?? $gym['id_empresa'] ?? 0) ?: null;
        $site = (int) ($user['id_gimnasio'] ?? $gym['id_gimnasio'] ?? 0) ?: null;
        $targetUser = $user ? (int) ($user['id_usuario'] ?? 0) ?: null : null;
        $authenticatedActor = $targetUser !== null
            && $result === 'exito'
            && in_array($action, [
                'LOGIN', 'LOGOUT', 'LOGOUT_COMPLETO', 'PASSWORD_RESET_COMPLETED', 'PASSWORD_CHANGED',
            ], true);
        (new LogModel($company))->registrarCambio(
            $authenticatedActor ? $targetUser : null,
            $action,
            'Evento de autenticación',
            $targetUser,
            'usuario',
            $targetUser,
            null,
            null,
            $site,
            $result,
            $reason,
            [],
            $authenticatedActor ? 'usuario' : 'anonymous',
            'WEB'
        );
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

        $fallar = function (string $mensaje, string $reason) use ($email) {
            $this->auditAuth('LOGIN_GIMNASIO', 'fallo', null, null, $reason);
            $this->setFlash('errores', [$mensaje]);
            $this->setFlash('old', ['email' => $email]);
            $this->redirigir('login');
        };

        if ($this->intentosGimnasioBloqueado($ip, $email)) {
            AppLogger::write('SECURITY', 'gym_login_rate_limited', ['ip' => $ip]);
            $fallar('Demasiados intentos fallidos. Espera 15 minutos.', 'RATE_LIMITED');
        }
        if ($email === '' || $contrasena === '') {
            $fallar('Introduce el email y la contraseña del gimnasio.', 'INVALID_INPUT');
        }

        $gimnasio = $this->gimnasioModel->autenticar($email, $contrasena);

        if (!$gimnasio) {
            $this->registrarIntentoGimnasio($ip, $email);
            // Mensaje único: distinguir "email no existe" de "contraseña
            // incorrecta" permitiría averiguar qué gimnasios están dados de alta.
            $fallar('Email o contraseña del gimnasio incorrectos.', 'INVALID_CREDENTIALS');
        }

        session_regenerate_id(true);
        $this->limpiarIntentosGimnasio($email);
        $_SESSION['gimnasio_auth_id']     = (int) $gimnasio['id_gimnasio'];
        $_SESSION['gimnasio_auth_nombre'] = $gimnasio['nombre'];
        $this->auditAuth('LOGIN_GIMNASIO', 'exito', null, $gimnasio, 'AUTHENTICATED');

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
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Csrf::validarPost()) {
            $this->redirigir('login_gimnasio');
        }
        $user = !empty($_SESSION['usuario_id']) ? $this->userModel->buscarPorId((int) $_SESSION['usuario_id']) : null;
        $gym = $this->gimnasioDeSesion();
        $this->auditAuth('LOGOUT_COMPLETO', 'exito', $user ?: null, $gym, 'USER_REQUEST');
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

    private function intentosGimnasioBloqueado(string $ip, string $email, int $maximo = 8, int $minutos = 15): bool {
        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare(
                "SELECT SUM(email = :email) AS por_email, SUM(ip_address = :ip) AS por_ip
                 FROM intentos_gimnasio WHERE fecha_intento > DATE_SUB(NOW(), INTERVAL :min MINUTE)"
            );
            $stmt->bindValue(':ip', $ip);
            $stmt->bindValue(':email', mb_strtolower(trim($email)));
            $stmt->bindValue(':min', $minutos, PDO::PARAM_INT);
            $stmt->execute();
            $conteos = $stmt->fetch();
            return (int) ($conteos['por_email'] ?? 0) >= $maximo || (int) ($conteos['por_ip'] ?? 0) >= 30;
        } catch (\PDOException $e) {
            error_log('intentosGimnasioBloqueado error: ' . $e->getMessage());
            return false;
        }
    }

    private function limpiarIntentosGimnasio(string $email): void {
        try {
            Database::getInstance()->getConnection()->prepare('DELETE FROM intentos_gimnasio WHERE email = :email')
                ->execute([':email' => mb_strtolower(trim($email))]);
        } catch (\PDOException $e) {
            AppLogger::write('ERROR', 'gym_rate_limit_cleanup_failed');
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

        $usuario    = mb_strtolower(trim($_POST['usuario'] ?? ''));
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

        $authUser = null;
        $fallar = function (string $mensaje, string $reason) use ($usuario, $gimnasio, &$authUser) {
            $this->auditAuth('LOGIN', 'fallo', $authUser, $gimnasio, $reason);
            $this->setFlash('errores', [$mensaje]);
            $this->setFlash('old', ['usuario' => $usuario]);
            $this->redirigir('login_gimnasio');
        };

        if ($this->blacklist->estaBloqueado($ip, $usuario)) {
            AppLogger::write('SECURITY', 'employee_login_rate_limited', ['ip' => $ip]);
            $fallar('Cuenta bloqueada por múltiples intentos fallidos. Inténtalo de nuevo en 15 minutos.', 'RATE_LIMITED');
        }
        if ($usuario === '' || $contrasena === '') {
            $fallar('Introduce usuario y contraseña.', 'INVALID_INPUT');
        }

        $user = $this->userModel->buscarPorUsuario($usuario);
        $authUser = $user ?: null;

        if (!$user || !password_verify($contrasena, $user['contrasena'])) {
            $this->blacklist->registrarIntentoFallido($ip, $usuario);
            $restantes = $this->blacklist->getIntentosRestantes($ip, $usuario);
            $fallar($restantes > 0
                ? 'Usuario o contraseña incorrectos. Te quedan ' . $restantes . ' intentos.'
                : 'Cuenta bloqueada por múltiples intentos fallidos.', 'INVALID_CREDENTIALS');
        }

        // Solo el personal entra al panel. Los socios no tienen acceso web.
        if (!in_array($user['rol'] ?? '', self::ROLES_PANEL, true)) {
            $fallar('Esta cuenta no tiene acceso al panel de gestión.', 'ROLE_DENIED');
        }
        if ((int) ($user['activo'] ?? 1) !== 1) {
            $fallar('Tu acceso está bloqueado. Habla con la administración del gimnasio.', 'ACCOUNT_DISABLED');
        }

        // Personal de sede: coincidencia exacta. Dirección: cualquier sede de
        // su empresa. El superadmin es el único rol que puede entrar por todas.
        $rol = $user['rol'] ?? '';
        $mismaSede = (int) ($user['id_gimnasio'] ?? 0) === (int) $gimnasio['id_gimnasio'];
        $mismaEmpresa = (int) ($user['id_empresa'] ?? 0) > 0
            && (int) $user['id_empresa'] === (int) ($gimnasio['id_empresa'] ?? 0);
        if (($rol === 'direccion' && !$mismaEmpresa)
            || (!in_array($rol, ['superadmin', 'direccion'], true) && !$mismaSede)) {
            $this->blacklist->registrarIntentoFallido($ip, $usuario);
            $fallar('Esta cuenta no pertenece a ' . $gimnasio['nombre'] . '.', 'TENANT_OR_SITE_MISMATCH');
        }

        $this->sessionLogin($user);
        $this->blacklist->limpiarIntentos($ip, $usuario);
        $_SESSION['mantener_sesion'] = isset($_POST['mantener_sesion']);

        // El gimnasio identificado se conserva durante toda la sesión: es lo
        // que permite que al salir se vuelva a su pantalla y no a la inicial.
        $_SESSION['gimnasio_auth_id']     = (int) $gimnasio['id_gimnasio'];
        $_SESSION['gimnasio_auth_nombre'] = $gimnasio['nombre'];
        $this->auditAuth('LOGIN', 'exito', $user, $gimnasio, 'AUTHENTICATED');

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
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Csrf::validarPost()) {
            $this->redirigir($this->isLoggedIn() ? 'admin' : 'login');
        }
        $idGimnasio = (int) ($_SESSION['gimnasio_auth_id'] ?? $_SESSION['gimnasio_id'] ?? 0);
        $gimnasio   = $idGimnasio > 0 ? $this->gimnasioModel->buscarPorId($idGimnasio) : null;
        $user = !empty($_SESSION['usuario_id']) ? $this->userModel->buscarPorId((int) $_SESSION['usuario_id']) : null;
        $this->auditAuth('LOGOUT', 'exito', $user ?: null, $gimnasio ?: null, 'USER_REQUEST');

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

        $gimnasio = $this->gimnasioDeSesion();
        $user = $gimnasio
            ? (new UserModel(null, (int) $gimnasio['id_empresa']))->buscarPorCorreo($correo)
            : null;
        // Solo se envía enlace al personal: un socio no tiene dónde entrar.
        if ($user && (int) ($user['activo'] ?? 0) === 1 && in_array($user['rol'] ?? '', self::ROLES_PANEL, true)) {
            (new PasswordResetDeliveryService())->issue(
                $this->userModel,
                $user,
                static fn(string $email, string $name, string $url): bool => Mailer::resetContrasena($email, $name, $url),
                function (string $result, string $reason) use ($user, $gimnasio): void {
                    $this->auditAuth('PASSWORD_RESET_DELIVERY', $result, $user, $gimnasio, $reason);
                }
            );
        }
        $this->auditAuth('PASSWORD_RESET_REQUEST_ACCEPTED', 'exito', $user ?: null, $gimnasio, 'GENERIC_RESPONSE');

        // El mensaje es el mismo exista o no la cuenta: si cambiara, serviría
        // para averiguar qué correos están registrados.
        $this->setFlash('exito', 'Si el correo está registrado, recibirás un enlace para restablecer tu contraseña en unos minutos.');
        $this->redirigir('password_forgot');
    }

    public function mostrarResetContrasena(): void {
        if ($this->isLoggedIn()) {
            $this->redirigirSegunRol();
        }

        $tokenFromLink = trim($_GET['token'] ?? '');
        if ($tokenFromLink !== '') {
            if (!$this->userModel->buscarPorTokenReset($tokenFromLink)) {
                $this->setFlash('errores', ['El enlace ha caducado o no es válido. Solicita uno nuevo.']);
                $this->redirigir('password_forgot');
            }
            // Se retira el secreto de la URL antes de renderizar recursos o un
            // formulario. El valor permanece solo en la sesión del servidor.
            $_SESSION['password_reset_token'] = $tokenFromLink;
            $this->redirigir('password_reset');
        }

        $token = trim((string) ($_SESSION['password_reset_token'] ?? ''));
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

        $token      = trim((string) ($_SESSION['password_reset_token'] ?? ''));
        $contrasena =      $_POST['contrasena'] ?? '';
        $confirmar  =      $_POST['confirmar_contrasena'] ?? '';

        if ($token === '') {
            $this->setFlash('errores', ['Falta el token de recuperación.']);
            $this->redirigir('password_forgot');
        }

        $errores = [];
        if (strlen($contrasena) < 8)   $errores[] = 'La contraseña debe tener al menos 8 caracteres.';
        if ($contrasena !== $confirmar) $errores[] = 'Las contraseñas no coinciden.';

        if (!empty($errores)) {
            $this->setFlash('errores', $errores);
            $this->redirigir('password_reset');
        }

        $user = $this->userModel->consumirTokenReset($token, $contrasena);
        unset($_SESSION['password_reset_token']);
        if ($user) {
            $this->auditAuth('PASSWORD_RESET_COMPLETED', 'exito', $user, null, 'PASSWORD_CHANGED');
            $this->setFlash('exito', 'Contraseña actualizada. Ya puedes iniciar sesión.');
            $this->redirigir('login');
        }
        $this->auditAuth('PASSWORD_RESET_COMPLETED', 'fallo', null, null, 'TOKEN_INVALID_OR_CONSUMED');
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

        $actual    = $_POST['contrasena_actual']    ?? '';
        $nueva     = $_POST['contrasena_nueva']     ?? '';
        $confirmar = $_POST['contrasena_confirmar'] ?? '';

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
            if ($actual === '' || !password_verify($actual, $usuario['contrasena'] ?? '')) {
                $errores[] = 'La contraseña actual no es correcta.';
            }
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
            if (!$this->userModel->cambiarContrasena($idUsuario, $nueva)) {
                $this->auditAuth('PASSWORD_CHANGED', 'fallo', $usuario, null, 'UPDATE_FAILED');
                $this->setFlash('errores', ['El perfil se actualizó, pero no se pudo cambiar la contraseña. Inténtalo de nuevo.']);
                $this->redirigir('perfil');
            }
            $this->auditAuth('PASSWORD_CHANGED', 'exito', $usuario, null, 'SELF_SERVICE');
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
