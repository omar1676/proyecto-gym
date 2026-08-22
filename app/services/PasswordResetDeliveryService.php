<?php

require_once __DIR__ . '/../models/UserModel.php';

final class PasswordResetDeliveryService
{
    /**
     * @param callable(string,string,string):bool $mailer
     * @param callable(string,string):void $audit recibe resultado y reason code
     */
    public function issue(UserModel $users, array $user, callable $mailer, callable $audit): bool
    {
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + 1800);
        $id = (int) ($user['id_usuario'] ?? 0);
        if ($id <= 0 || !$users->guardarTokenReset($id, $token, $expires)) {
            $audit('fallo', 'TOKEN_STORE_FAILED');
            return false;
        }
        $url = APP_URL . '/index.php?action=password_reset&token=' . urlencode($token);
        $name = trim(($user['nombre'] ?? '') . ' ' . ($user['apellidos'] ?? ''));
        $delivered = (bool) $mailer((string) ($user['email'] ?? ''), $name, $url);
        $audit($delivered ? 'exito' : 'fallo', $delivered ? 'EMAIL_SENT' : 'EMAIL_DELIVERY_FAILED');
        if (!$delivered) {
            // Condicional: no elimina un token más nuevo emitido por otra
            // solicitud que hubiera ganado después.
            $users->invalidarTokenReset($id, $token);
        }
        return $delivered;
    }
}
