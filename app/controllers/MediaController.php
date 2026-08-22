<?php

require_once __DIR__ . '/../helpers/Sesion.php';
require_once __DIR__ . '/../helpers/TenantContext.php';
require_once __DIR__ . '/../helpers/Authorization.php';
require_once __DIR__ . '/../helpers/PrivatePhotoStorage.php';
require_once __DIR__ . '/../models/UserModel.php';

final class MediaController
{
    public function fotoUsuario(): void
    {
        Sesion::iniciar();
        $context = TenantContext::desdeSesion();
        if (!$context->autenticado()) $this->deny(403);
        $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        if (!$id || $id <= 0) $this->deny(404);
        $users = new UserModel($context->sedeId(), $context->empresaId());
        $target = $users->buscarPorId((int) $id);
        if (!$target || empty($target['foto']) || !self::canView($context, $target)) $this->deny(404);
        $file = PrivatePhotoStorage::resolve((string) $target['foto']);
        if ($file === null) $this->deny(404);
        header('Content-Type: ' . $file['mime']);
        header('Content-Length: ' . (string) filesize($file['path']));
        header('Content-Disposition: inline; filename="profile.' . $file['extension'] . '"');
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        readfile($file['path']);
        exit;
    }

    /** @param array<string,mixed> $target */
    public static function canView(TenantContext $context, array $target): bool
    {
        if ((int) ($target['id_usuario'] ?? 0) === $context->usuarioId()) return true;
        $permission = ($target['rol'] ?? '') === 'socio' ? 'socios.view' : 'empleados.manage';
        return Authorization::can($context->rol(), $permission);
    }

    private function deny(int $status): never
    {
        http_response_code($status);
        header('Cache-Control: no-store');
        exit;
    }
}
