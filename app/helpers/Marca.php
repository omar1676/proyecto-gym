<?php
/**
 * Marca — la identidad visual de cada gimnasio, lista para pintar.
 *
 * En la base de datos solo se guardan tres cosas: el logo, el color principal
 * y el color del texto que va encima. Todo lo demás (el tinte del fondo, el
 * borde suave, el color de los enlaces) se calcula aquí a partir de ellos,
 * para que las pantallas de acceso no tengan que hacer cuentas de color.
 *
 * La regla que gobierna estos cálculos: el logo del cliente puede ser de
 * cualquier color, incluido negro sobre transparente, así que
 * SIEMPRE se pinta sobre una superficie blanca. El color de marca manda en el
 * fondo, los botones y los acentos, nunca debajo del logo.
 */

class Marca
{
    /** Cuando no hay gimnasio identificado manda la marca de la plataforma. */
    const PRIMARIO_DEFECTO = '#111318';
    const TEXTO_DEFECTO    = '#ffffff';

    /** Relación de contraste mínima que se exige a un texto pequeño (WCAG AA). */
    const CONTRASTE_MINIMO = 4.5;

    /**
     * Devuelve la marca de un gimnasio en forma de array listo para la vista.
     * Con $gimnasio a null se obtiene la marca neutra de la plataforma.
     */
    public static function de(?array $gimnasio): array
    {
        $nombre = trim((string) ($gimnasio['nombre'] ?? ''));
        if ($nombre === '') {
            $nombre = defined('APP_NOMBRE') ? APP_NOMBRE : 'Gimnasio';
        }

        $primario = self::normalizarColor($gimnasio['color_primario'] ?? '', self::PRIMARIO_DEFECTO);
        $texto    = self::normalizarColor($gimnasio['color_texto']    ?? '', self::TEXTO_DEFECTO);

        // El color de texto guardado podría no leerse sobre el principal (un
        // blanco sobre amarillo, por ejemplo). Si no llega al mínimo se cambia
        // por el que sí contrasta: es preferible respetar la legibilidad.
        if (self::contraste($primario, $texto) < 3.0) {
            $texto = self::sobre($primario);
        }

        $logo = trim((string) ($gimnasio['logo'] ?? ''));

        return [
            'nombre'    => $nombre,
            'inicial'   => mb_strtoupper(mb_substr($nombre, 0, 1, 'UTF-8'), 'UTF-8'),
            'logo'      => $logo !== '' ? $logo : null,
            'logoUrl'   => $logo !== '' ? 'assets/gimnasios/' . rawurlencode($logo) : null,

            // Colores tal cual los eligió el cliente.
            'primario'  => $primario,
            'texto'     => $texto,

            // Texto y separadores que van encima del fondo de marca.
            'sobreFondo' => self::sobre($primario),
            'tenue'      => self::mezclar(self::sobre($primario), $primario, 0.65),

            // Versión del color de marca que sí se lee sobre una tarjeta blanca:
            // se oscurece lo justo hasta cumplir el contraste mínimo.
            'enlace'    => self::legibleSobreBlanco($primario),

            // Tintes del color de marca para fondos y bordes suaves.
            'tinte'     => self::mezclar($primario, '#ffffff', 0.06),
            'borde'     => self::mezclar($primario, '#ffffff', 0.18),

            // Fondo de la pantalla de acceso: el color de marca, oscurecido o
            // aclarado un punto para dar profundidad al degradado.
            'fondoA'    => $primario,
            'fondoB'    => self::esOscuro($primario)
                                ? self::mezclar($primario, '#000000', 0.55)
                                : self::mezclar($primario, '#000000', 0.20),

            'esOscuro'  => self::esOscuro($primario),
        ];
    }

    /* ---------------------------------------------------------------------
     * Utilidades de color
     * ------------------------------------------------------------------ */

    /** Acepta solo #rrggbb; cualquier otra cosa se sustituye por $porDefecto. */
    public static function normalizarColor($color, string $porDefecto): string
    {
        $color = strtolower(trim((string) $color));
        // Se admite la forma corta #abc por comodidad al escribirla a mano.
        if (preg_match('/^#([0-9a-f])([0-9a-f])([0-9a-f])$/', $color, $m)) {
            $color = '#' . $m[1] . $m[1] . $m[2] . $m[2] . $m[3] . $m[3];
        }
        return preg_match('/^#[0-9a-f]{6}$/', $color) ? $color : $porDefecto;
    }

    public static function aRgb(string $hex): array
    {
        $hex = self::normalizarColor($hex, '#000000');
        return [
            hexdec(substr($hex, 1, 2)),
            hexdec(substr($hex, 3, 2)),
            hexdec(substr($hex, 5, 2)),
        ];
    }

    public static function aHex(array $rgb): string
    {
        return sprintf(
            '#%02x%02x%02x',
            max(0, min(255, (int) round($rgb[0]))),
            max(0, min(255, (int) round($rgb[1]))),
            max(0, min(255, (int) round($rgb[2])))
        );
    }

    /** Mezcla dos colores: $peso es cuánto del segundo entra (0..1). */
    public static function mezclar(string $a, string $b, float $peso): string
    {
        $peso = max(0.0, min(1.0, $peso));
        [$r1, $g1, $b1] = self::aRgb($a);
        [$r2, $g2, $b2] = self::aRgb($b);
        return self::aHex([
            $r1 + ($r2 - $r1) * $peso,
            $g1 + ($g2 - $g1) * $peso,
            $b1 + ($b2 - $b1) * $peso,
        ]);
    }

    /** Luminancia relativa (WCAG): 0 negro, 1 blanco. */
    public static function luminancia(string $hex): float
    {
        $canal = function ($v) {
            $v = $v / 255;
            return $v <= 0.03928 ? $v / 12.92 : pow(($v + 0.055) / 1.055, 2.4);
        };
        [$r, $g, $b] = self::aRgb($hex);
        return 0.2126 * $canal($r) + 0.7152 * $canal($g) + 0.0722 * $canal($b);
    }

    /** Relación de contraste entre dos colores: de 1 (igual) a 21 (negro/blanco). */
    public static function contraste(string $a, string $b): float
    {
        $la = self::luminancia($a);
        $lb = self::luminancia($b);
        return ($la > $lb ? $la + 0.05 : $lb + 0.05) / ($la > $lb ? $lb + 0.05 : $la + 0.05);
    }

    public static function esOscuro(string $hex): bool
    {
        return self::luminancia($hex) < 0.35;
    }

    /** Blanco o casi negro, el que mejor se lea sobre el color dado. */
    public static function sobre(string $hex): string
    {
        return self::contraste($hex, '#ffffff') >= self::contraste($hex, '#111318')
            ? '#ffffff'
            : '#111318';
    }

    /**
     * Oscurece el color hasta que se lea sobre blanco. Un amarillo de marca
     * queda inservible como color de enlace; esto lo baja a un tono utilizable
     * sin cambiarle el matiz.
     */
    public static function legibleSobreBlanco(string $hex): string
    {
        $color = self::normalizarColor($hex, self::PRIMARIO_DEFECTO);
        $vueltas = 0;
        while (self::contraste($color, '#ffffff') < self::CONTRASTE_MINIMO && $vueltas < 20) {
            $color = self::mezclar($color, '#000000', 0.10);
            $vueltas++;
        }
        return $color;
    }
}
