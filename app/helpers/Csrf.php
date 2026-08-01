<?php

require_once __DIR__ . '/Sesion.php';

class Csrf
{
    const KEY = '_csrf_token';
    const FIELD = '_csrf';

    public static function token(): string
    {
        // Vía el helper para no arrancar nunca la sesión sin cookies seguras.
        Sesion::iniciar();
        if (empty($_SESSION[self::KEY])) {
            $_SESSION[self::KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::KEY];
    }

    public static function field(): string
    {
        $t = htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8');
        return '<input type="hidden" name="' . self::FIELD . '" value="' . $t . '">';
    }

    public static function check($valor): bool
    {
        // Vía el helper para no arrancar nunca la sesión sin cookies seguras.
        Sesion::iniciar();
        $esperado = $_SESSION[self::KEY] ?? '';
        if ($esperado === '' || !is_string($valor) || $valor === '') {
            return false;
        }
        return hash_equals($esperado, $valor);
    }

    public static function validarPost(): bool
    {
        $valor = isset($_POST[self::FIELD]) ? (string) $_POST[self::FIELD] : '';
        return self::check($valor);
    }
}
