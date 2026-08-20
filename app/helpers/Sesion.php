<?php
/**
 * Sesion — arranque centralizado y seguro de la sesión.
 *
 * Antes cada controlador llamaba a session_start() por su cuenta y solo uno
 * ajustaba las cookies, así que la configuración dependía de por dónde entrara
 * la petición. Aquí queda en un único sitio:
 *
 *   - httponly: el JavaScript de la página no puede leer la cookie de sesión.
 *   - secure:   bajo HTTPS la cookie no viaja nunca en claro.
 *   - samesite: el navegador no la envía desde otro sitio web, que es la
 *               defensa de base contra CSRF.
 *   - caducidad por inactividad: un panel abierto en el mostrador se cierra
 *               solo si nadie lo toca.
 */

require_once __DIR__ . '/../config/config.php';

class Sesion
{
    /** Minutos de inactividad antes de cerrar la sesión. */
    private static function minutosInactividad(): int
    {
        $valor = defined('SESION_MINUTOS') ? (int) SESION_MINUTOS : 120;
        return $valor > 0 ? $valor : 120;
    }

    /** ¿La petición llega por HTTPS? Contempla proxies inversos. */
    private static function esHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if (($_SERVER['SERVER_PORT'] ?? '') == 443) {
            return true;
        }
        // Cabecera que añaden los balanceadores y CDN cuando terminan el TLS.
        return strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }

    public static function iniciar(): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            self::comprobarInactividad();
            self::comprobarContrasenaCambiada();
            return;
        }

        $segundos = self::minutosInactividad() * 60;

        // El PHP de XAMPP puede apuntar a un directorio global no escribible
        // desde el runner aislado. En test se usa siempre una carpeta local;
        // producción y desarrollo conservan la configuración del servidor.
        if (defined('SESSION_DIR') && SESSION_DIR !== '' && is_dir(SESSION_DIR)) {
            ini_set('session.save_path', SESSION_DIR);
        } elseif (defined('APP_ENV') && APP_ENV === 'test') {
            $testSessions = dirname(__DIR__, 2) . '/pruebas/sesiones_tmp';
            if (is_dir($testSessions)) ini_set('session.save_path', $testSessions);
        }

        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_strict_mode', '1');   // rechaza ids de sesión inventados
        ini_set('session.gc_maxlifetime', (string) $segundos);

        $params = [
            'lifetime' => 0,                 // la cookie muere al cerrar el navegador
            'path'     => '/',
            'domain'   => '',
            'secure'   => self::esHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ];

        if (PHP_VERSION_ID >= 70300) {
            session_set_cookie_params($params);
        } else {
            session_set_cookie_params(
                $params['lifetime'],
                $params['path'] . '; samesite=' . $params['samesite'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_start();
        self::comprobarInactividad();
        self::comprobarContrasenaCambiada();
    }

    /**
     * Echa a las sesiones abiertas antes del último cambio de contraseña.
     *
     * Se comprueba en cada petición porque el ataque que esto tapa es
     * justamente el de otra sesión abierta en otro sitio: si solo se mirara al
     * entrar, esa sesión no volvería a pasar nunca por aquí.
     *
     * Una consulta por clave primaria en cada petición del panel; al lado de
     * las que ya hace cualquier pantalla, no se nota.
     */
    private static function comprobarContrasenaCambiada(): void
    {
        if (empty($_SESSION['logueado']) || empty($_SESSION['usuario_id'])) {
            return;
        }
        // Sesión de antes de esta función: se le pone marca ahora y sigue.
        if (empty($_SESSION['iniciada_en'])) {
            $_SESSION['iniciada_en'] = time();
            return;
        }

        try {
            require_once __DIR__ . '/../config/database.php';
            $stmt = Database::getInstance()->getConnection()
                ->prepare('SELECT sesiones_desde FROM usuario WHERE id_usuario = :id');
            $stmt->execute([':id' => (int) $_SESSION['usuario_id']]);
            $desde = $stmt->fetchColumn();
        } catch (\Throwable $e) {
            // Si la base no responde, no se echa a nadie: peor sería dejar el
            // panel inservible por un problema de conexión.
            error_log('Sesion::comprobarContrasenaCambiada: ' . $e->getMessage());
            return;
        }

        if (!$desde) {
            return;
        }

        if (strtotime((string) $desde) > (int) $_SESSION['iniciada_en']) {
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $p = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
            }
            session_destroy();
            session_start();
            $_SESSION['contrasena_cambiada'] = true;
        }
    }

    /** Cierra la sesión si lleva demasiado tiempo sin actividad. */
    private static function comprobarInactividad(): void
    {
        if (empty($_SESSION['logueado'])) {
            $_SESSION['ultimo_acceso'] = time();
            return;
        }

        $limite  = self::minutosInactividad() * 60;
        $ultimo  = (int) ($_SESSION['ultimo_acceso'] ?? time());

        if (time() - $ultimo > $limite) {
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $p = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
            }
            session_destroy();
            session_start();
            $_SESSION['caducada'] = true;
            return;
        }

        $_SESSION['ultimo_acceso'] = time();
    }
}
