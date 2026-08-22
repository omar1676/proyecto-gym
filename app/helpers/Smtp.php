<?php
/**
 * Smtp — cliente mínimo para enviar correo por un servidor SMTP autenticado.
 *
 * Por qué existe pudiendo usar mail(): en alojamiento compartido, mail() sale
 * con la identidad del servidor, no la del dominio del gimnasio. El receptor
 * comprueba el SPF del remitente, no cuadra, y el aviso de vencimiento acaba en
 * spam. Enviando por el SMTP del propio dominio el correo va firmado por quien
 * dice ser y llega a la bandeja de entrada.
 *
 * Cubre lo que hace falta aquí y nada más: EHLO, STARTTLS, AUTH LOGIN y un
 * mensaje HTML a un destinatario. Sin adjuntos ni copias.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/SafeException.php';

class Smtp
{
    /** ¿Hay servidor configurado? Si no, quien llame usará mail(). */
    public static function configurado(): bool
    {
        return defined('MAIL_SMTP_HOST') && MAIL_SMTP_HOST !== '';
    }

    /**
     * Envía un correo HTML. Devuelve false y deja el motivo en el log si algo
     * falla: un aviso que no sale nunca debe tumbar la pantalla desde la que
     * se disparó.
     */
    public static function enviar(string $para, string $asunto, string $html, string $from, string $nombreFrom): bool
    {
        return self::enviarConConfiguracion($para, $asunto, $html, $from, $nombreFrom, [
            'host' => MAIL_SMTP_HOST,
            'port' => MAIL_SMTP_PUERTO,
            'security' => defined('MAIL_SMTP_SEGURIDAD') ? MAIL_SMTP_SEGURIDAD : 'tls',
            'user' => MAIL_SMTP_USUARIO,
            'password' => MAIL_SMTP_CLAVE,
            'timeout' => 15,
        ]);
    }

    /**
     * Transporte SMTP parametrizado para canales separados. Las credenciales
     * permanecen en memoria y nunca se incluyen en mensajes de error.
     *
     * @param array{host:string,port:int,security:string,user:string,password:string,timeout?:int} $config
     */
    public static function enviarConConfiguracion(
        string $para,
        string $asunto,
        string $html,
        string $from,
        string $nombreFrom,
        array $config
    ): bool {
        $seguridad = strtolower((string) ($config['security'] ?? 'tls'));
        if (!in_array($seguridad, ['tls', 'ssl'], true)) return false;
        $host = trim((string) ($config['host'] ?? ''));
        $puerto = (int) ($config['port'] ?? ($seguridad === 'ssl' ? 465 : 587));
        $timeout = max(3, min(30, (int) ($config['timeout'] ?? 15)));
        $usuario = (string) ($config['user'] ?? '');
        $clave = (string) ($config['password'] ?? '');
        if ($host === '' || !filter_var($para, FILTER_VALIDATE_EMAIL) || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        // Con SSL directo el cifrado empieza desde el saludo; con STARTTLS se
        // abre en claro y se sube después.
        $destino = ($seguridad === 'ssl' ? 'ssl://' : '') . $host . ':' . $puerto;

        $contexto = stream_context_create([
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true],
        ]);

        $socket = @stream_socket_client($destino, $errNo, $errStr, $timeout, STREAM_CLIENT_CONNECT, $contexto);
        if (!$socket) {
            error_log("Smtp: no se pudo conectar a {$destino} ({$errNo} {$errStr})");
            return false;
        }
        stream_set_timeout($socket, $timeout);

        try {
            self::esperar($socket, 220);

            $dominio = self::dominioDe($from);
            self::orden($socket, 'EHLO ' . $dominio, 250);

            if ($seguridad === 'tls') {
                self::orden($socket, 'STARTTLS', 220);
                $metodo = STREAM_CRYPTO_METHOD_TLS_CLIENT;
                if (!@stream_socket_enable_crypto($socket, true, $metodo)) {
                    throw new RuntimeException('no se pudo activar TLS');
                }
                // Tras STARTTLS hay que volver a saludar: la sesión se reinicia.
                self::orden($socket, 'EHLO ' . $dominio, 250);
            }

            if ($usuario !== '') {
                self::orden($socket, 'AUTH LOGIN', 334);
                self::orden($socket, base64_encode($usuario), 334);
                self::orden($socket, base64_encode($clave), 235);
            }

            self::orden($socket, 'MAIL FROM:<' . $from . '>', 250);
            self::orden($socket, 'RCPT TO:<' . $para . '>', [250, 251]);
            self::orden($socket, 'DATA', 354);

            $cuerpo = self::cabeceras($para, $asunto, $from, $nombreFrom) . "\r\n" . self::escaparPuntos($html);
            fwrite($socket, $cuerpo . "\r\n.\r\n");
            self::esperar($socket, 250);

            self::orden($socket, 'QUIT', [221, 250]);
            fclose($socket);
            return true;

        } catch (\Throwable $e) {
            SafeException::log('smtp_failed', $e, 'Smtp.enviar');
            if (is_resource($socket)) {
                @fwrite($socket, "QUIT\r\n");
                @fclose($socket);
            }
            return false;
        }
    }

    private static function cabeceras(string $para, string $asunto, string $from, string $nombreFrom): string
    {
        $nombre = '=?UTF-8?B?' . base64_encode($nombreFrom) . '?=';
        return implode("\r\n", [
            'Date: ' . date('r'),
            'From: ' . $nombre . ' <' . $from . '>',
            'To: <' . $para . '>',
            'Subject: =?UTF-8?B?' . base64_encode($asunto) . '?=',
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . self::dominioDe($from) . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ]) . "\r\n";
    }

    /** Una línea del cuerpo que empiece por punto cerraría el mensaje: se dobla. */
    private static function escaparPuntos(string $texto): string
    {
        $texto = str_replace(["\r\n", "\r", "\n"], "\r\n", $texto);
        return preg_replace('/^\./m', '..', $texto);
    }

    private static function dominioDe(string $email): string
    {
        $partes = explode('@', $email);
        return count($partes) === 2 && $partes[1] !== '' ? $partes[1] : 'localhost';
    }

    /** @param int|int[] $esperado */
    private static function orden($socket, string $orden, $esperado): void
    {
        fwrite($socket, $orden . "\r\n");
        self::esperar($socket, $esperado);
    }

    /** @param int|int[] $esperado */
    private static function esperar($socket, $esperado): void
    {
        $codigos   = is_array($esperado) ? $esperado : [$esperado];
        $respuesta = '';

        // Una respuesta SMTP puede ocupar varias líneas: las intermedias llevan
        // un guion tras el código (250-) y la última un espacio (250 ).
        do {
            $linea = fgets($socket, 515);
            if ($linea === false) {
                throw new RuntimeException('el servidor cortó la conexión');
            }
            $respuesta .= $linea;
        } while (isset($linea[3]) && $linea[3] === '-');

        $codigo = (int) substr($respuesta, 0, 3);
        if (!in_array($codigo, $codigos, true)) {
            throw new RuntimeException('respuesta inesperada: ' . trim($respuesta));
        }
    }
}
