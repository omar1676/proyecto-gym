<?php
/**
 * Iban — normalización y validación de números de cuenta.
 *
 * La comprobación no es solo de formato: aplica el dígito de control ISO 7064
 * (mod 97-10), que es el mismo que usa el banco. Eso detecta la mayoría de los
 * errores de tecleo —un dígito cambiado o dos cifras intercambiadas— antes de
 * guardar una cuenta a la que luego no se le puede cobrar.
 */

class Iban
{
    /** Longitud oficial del IBAN por país. Los no listados se aceptan por longitud genérica. */
    private const LONGITUDES = [
        'ES' => 24, 'PT' => 25, 'FR' => 27, 'IT' => 27, 'DE' => 22,
        'GB' => 22, 'NL' => 18, 'BE' => 16, 'AD' => 24, 'IE' => 22,
        'LU' => 20, 'CH' => 21, 'AT' => 20, 'MC' => 27, 'PL' => 28,
    ];

    /** Quita espacios y guiones, y pasa a mayúsculas. */
    public static function normalizar(string $iban): string
    {
        return strtoupper(preg_replace('/[\s\-]+/', '', $iban));
    }

    public static function esValido(string $iban): bool
    {
        $iban = self::normalizar($iban);

        // Dos letras de país, dos dígitos de control y el resto alfanumérico.
        if (!preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{10,30}$/', $iban)) {
            return false;
        }

        $pais = substr($iban, 0, 2);
        if (isset(self::LONGITUDES[$pais]) && strlen($iban) !== self::LONGITUDES[$pais]) {
            return false;
        }

        // Mod 97: se llevan los cuatro primeros caracteres al final, se cambia
        // cada letra por su posición en el alfabeto + 9 (A=10 … Z=35) y el
        // resto de dividir entre 97 tiene que ser 1.
        $reordenado = substr($iban, 4) . substr($iban, 0, 4);
        $numerico   = '';
        for ($i = 0, $n = strlen($reordenado); $i < $n; $i++) {
            $c = $reordenado[$i];
            $numerico .= ctype_alpha($c) ? (string) (ord($c) - 55) : $c;
        }

        // bcmod evita desbordar el entero: un IBAN convertido pasa de 30 dígitos.
        if (function_exists('bcmod')) {
            return bcmod($numerico, '97') === '1';
        }

        // Resto por tramos, por si la extensión bcmath no está disponible.
        $resto = '';
        for ($i = 0, $n = strlen($numerico); $i < $n; $i++) {
            $resto = (string) ((int) ($resto . $numerico[$i]) % 97);
        }
        return $resto === '1';
    }

    /** Formato legible en grupos de cuatro: ES91 2100 0418 4502 0005 1332. */
    public static function formatear(?string $iban): string
    {
        if (empty($iban)) return '';
        return trim(chunk_split(self::normalizar($iban), 4, ' '));
    }

    /**
     * Versión enmascarada para listados: ES91 **** **** **** **** 1332.
     * Un número de cuenta completo no debería estar a la vista en una pantalla
     * de mostrador que cualquiera puede mirar de reojo.
     */
    public static function enmascarar(?string $iban): string
    {
        $iban = self::normalizar((string) $iban);
        if ($iban === '') return '';
        if (strlen($iban) <= 8) return $iban;

        $inicio = substr($iban, 0, 4);
        $fin    = substr($iban, -4);
        $medio  = str_repeat('*', max(0, strlen($iban) - 8));

        return trim(chunk_split($inicio . $medio . $fin, 4, ' '));
    }
}
