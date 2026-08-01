<?php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/Smtp.php';

class Mailer
{
    /**
     * Dirección de la que salen los correos.
     *
     * Sale del .env (MAIL_FROM) y tiene que ser del dominio propio. El valor de
     * emergencia es `noreply@` del dominio de APP_URL: no es lo ideal, pero al
     * menos coincide con el dominio desde el que se envía, que es lo que mira
     * el receptor. La dirección fija del portal de cursos que había aquí antes
     * garantizaba que todo acabara en spam.
     */
    private static function from(): string
    {
        if (defined('MAIL_FROM') && MAIL_FROM !== '') {
            return MAIL_FROM;
        }
        $host = parse_url(self::baseUrl(), PHP_URL_HOST) ?: 'localhost';
        return 'noreply@' . preg_replace('/^www\./', '', $host);
    }

    /** Nombre que se ve en la bandeja de entrada. */
    private static function remitente(): string
    {
        if (defined('MAIL_NOMBRE') && MAIL_NOMBRE !== '') {
            return MAIL_NOMBRE;
        }
        return defined('APP_NOMBRE') ? APP_NOMBRE : 'Gimnasio';
    }

    private static function baseUrl(): string
    {
        return defined('APP_URL') ? APP_URL : '';
    }

    public static function enviar(string $para, string $asunto, string $html): bool
    {
        if (!filter_var($para, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $from = self::from();

        // Con SMTP configurado se envía por ahí; si no, se cae a mail().
        if (Smtp::configurado()) {
            return Smtp::enviar($para, $asunto, $html, $from, self::remitente());
        }

        $asuntoCodificado = '=?UTF-8?B?' . base64_encode($asunto) . '?=';
        $nombre           = '=?UTF-8?B?' . base64_encode(self::remitente()) . '?=';

        $cabeceras  = 'MIME-Version: 1.0' . "\r\n";
        $cabeceras .= 'Content-Type: text/html; charset=UTF-8' . "\r\n";
        $cabeceras .= 'From: ' . $nombre . ' <' . $from . '>' . "\r\n";
        $cabeceras .= 'Reply-To: ' . $from . "\r\n";
        $cabeceras .= 'X-Mailer: PHP/' . phpversion() . "\r\n";

        $params = '-f' . $from;

        try {
            return @mail($para, $asuntoCodificado, $html, $cabeceras, $params);
        } catch (\Throwable $e) {
            error_log('Mailer::enviar error: ' . $e->getMessage());
            return false;
        }
    }

    private static function plantilla(string $titulo, string $cuerpo): string
    {
        return '<!DOCTYPE html>
<html lang="es"><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#e6e6e6;font-family:Arial,sans-serif;color:#1f2937;">
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#e6e6e6;padding:30px 0;">
    <tr><td align="center">
      <table width="600" cellpadding="0" cellspacing="0" border="0" style="background:#ffffff;border-radius:14px;overflow:hidden;box-shadow:0 4px 14px rgba(0,0,0,.06);">
        <tr><td style="background:#111111;padding:24px 30px;color:#ffffff;">
          <h1 style="margin:0;font-size:20px;font-weight:800;">' . htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8') . '</h1>
        </td></tr>
        <tr><td style="padding:30px;font-size:15px;line-height:1.55;color:#374151;">' . $cuerpo . '</td></tr>
        <tr><td style="padding:18px 30px;background:#e6e6e6;border-top:1px solid #ececec;font-size:12px;color:#8b8b8b;text-align:center;">
          ' . htmlspecialchars(self::remitente(), ENT_QUOTES, 'UTF-8') . ' · ' . self::baseUrl() . '
        </td></tr>
      </table>
    </td></tr>
  </table>
</body></html>';
    }

    public static function membresiaContratada(string $email, string $nombre, string $tipo, string $fechaFin): bool
    {
        $nombre    = htmlspecialchars($nombre,    ENT_QUOTES, 'UTF-8');
        $tipo      = htmlspecialchars($tipo,      ENT_QUOTES, 'UTF-8');
        $fechaFin  = htmlspecialchars($fechaFin,  ENT_QUOTES, 'UTF-8');
        $cuerpo = '<p>Hola <b>' . $nombre . '</b>,</p>'
                . '<p>Tu membresía <b>' . $tipo . '</b> ya está activa.</p>'
                . '<p>Válida hasta el <b>' . date('d/m/Y', strtotime($fechaFin)) . '</b>.</p>'
                . '<p>¡Nos vemos en el gimnasio!</p>';
        return self::enviar($email, 'Membresía activada — ' . $tipo, self::plantilla('Membresía activada', $cuerpo));
    }

    public static function membresiaPorVencer(string $email, string $nombre, string $tipo, string $fechaFin): bool
    {
        $nombre   = htmlspecialchars($nombre,   ENT_QUOTES, 'UTF-8');
        $tipo     = htmlspecialchars($tipo,     ENT_QUOTES, 'UTF-8');
        $fechaFin = htmlspecialchars($fechaFin, ENT_QUOTES, 'UTF-8');
        $cuerpo = '<p>Hola <b>' . $nombre . '</b>,</p>'
                . '<p>Tu membresía <b>' . $tipo . '</b> vence el '
                . '<b style="color:#111111;">' . date('d/m/Y', strtotime($fechaFin)) . '</b>.</p>'
                . '<p>Pásate por recepción para renovarla y no perder el acceso.</p>';
        return self::enviar($email, 'Tu membresía está a punto de vencer', self::plantilla('Renovación de membresía', $cuerpo));
    }

    /**
     * Aviso de que la cuota domiciliada se ha renovado sola y se va a cobrar.
     * El socio tiene que enterarse ANTES de ver el cargo en su cuenta: es lo
     * que evita la mitad de las devoluciones de recibos.
     */
    public static function membresiaRenovada(string $email, string $nombre, string $tipo, string $fechaFin, float $importe): bool
    {
        $nombre   = htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8');
        $tipo     = htmlspecialchars($tipo,   ENT_QUOTES, 'UTF-8');
        $cuerpo = '<p>Hola <b>' . $nombre . '</b>,</p>'
                . '<p>Hemos renovado tu membresía <b>' . $tipo . '</b>, que queda válida hasta el '
                . '<b>' . date('d/m/Y', strtotime($fechaFin)) . '</b>.</p>'
                . '<p>El importe de <b>' . number_format($importe, 2, ',', '.') . ' €</b> se cargará en la cuenta '
                . 'que tienes domiciliada en los próximos días.</p>'
                . '<p>Si quieres cambiar o cancelar la domiciliación, avísanos en recepción.</p>';
        return self::enviar($email, 'Tu membresía se ha renovado', self::plantilla('Membresía renovada', $cuerpo));
    }

    public static function resetContrasena(string $email, string $nombre, string $url): bool
    {
        $nombre  = htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8');
        $urlEsc  = htmlspecialchars($url,    ENT_QUOTES, 'UTF-8');
        $cuerpo  = '<p>Hola <b>' . $nombre . '</b>,</p>'
                 . '<p>Hemos recibido una solicitud para restablecer la contraseña de tu cuenta. Si has sido tú, pulsa el siguiente botón para crear una nueva. El enlace caduca en <b>30 minutos</b>.</p>'
                 . '<p><a href="' . $urlEsc . '" style="display:inline-block;background:#111111;color:#fff;text-decoration:none;padding:12px 26px;border-radius:8px;font-weight:bold;">Restablecer contraseña</a></p>'
                 . '<p style="font-size:12px;color:#9b9b9b;">Si el botón no funciona, copia y pega este enlace en tu navegador:<br>' . $urlEsc . '</p>'
                 . '<p style="font-size:12px;color:#9b9b9b;">Si no has solicitado tú este cambio, puedes ignorar este correo.</p>';
        return self::enviar($email, 'Restablece tu contraseña', self::plantilla('Restablecer contraseña', $cuerpo));
    }
}
