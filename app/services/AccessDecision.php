<?php

/**
 * Decisión lógica normalizada emitida por el SaaS.
 *
 * No contiene biometría, credenciales del proveedor ni detalle financiero.
 * Una decisión expresa lo que el dominio considera, no que una puerta se haya
 * abierto ni que una persona haya entrado.
 */
final class AccessDecision
{
    public const PERMITIDO = 'PERMITIDO';
    public const BLOQUEADO = 'BLOQUEADO';
    public const REVISAR = 'REVISAR';

    private int $empresaId;
    private int $sedeId;
    private int $socioId;
    private string $estado;
    private string $reasonCode;
    private string $decidedAt;
    private string $correlationId;
    private string $decisionVersion;

    public function __construct(
        int $empresaId,
        int $sedeId,
        int $socioId,
        string $estado,
        string $reasonCode,
        ?string $decidedAt = null,
        ?string $correlationId = null,
        ?string $decisionVersion = null
    ) {
        if ($empresaId <= 0 || $sedeId <= 0 || $socioId <= 0) {
            throw new InvalidArgumentException('Empresa, sede y socio son obligatorios en una decisión de acceso.');
        }
        if (!in_array($estado, [self::PERMITIDO, self::BLOQUEADO, self::REVISAR], true)) {
            throw new InvalidArgumentException('Estado de acceso no válido.');
        }
        $reasonCode = strtoupper(trim($reasonCode));
        if (!preg_match('/^[A-Z][A-Z0-9_]{1,63}$/', $reasonCode)) {
            throw new InvalidArgumentException('reason_code no válido.');
        }

        $this->empresaId = $empresaId;
        $this->sedeId = $sedeId;
        $this->socioId = $socioId;
        $this->estado = $estado;
        $this->reasonCode = $reasonCode;
        $parsedAt = $decidedAt === null ? time() : strtotime($decidedAt);
        if ($parsedAt === false) {
            throw new InvalidArgumentException('timestamp de decisión no válido.');
        }
        $this->decidedAt = gmdate(DATE_ATOM, $parsedAt);
        $this->correlationId = $correlationId ?: self::uuidV4();
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $this->correlationId)) {
            throw new InvalidArgumentException('correlation_id no válido.');
        }
        $this->decisionVersion = trim((string) $decisionVersion);
        if ($this->decisionVersion === '') {
            $this->decisionVersion = hash('sha256', $estado . '|' . $reasonCode);
        }
        if (!preg_match('/^[A-Za-z0-9._:-]{1,64}$/', $this->decisionVersion)) {
            $this->decisionVersion = hash('sha256', $this->decisionVersion);
        }
    }

    public function empresaId(): int { return $this->empresaId; }
    public function sedeId(): int { return $this->sedeId; }
    public function socioId(): int { return $this->socioId; }
    public function estado(): string { return $this->estado; }
    public function reasonCode(): string { return $this->reasonCode; }
    public function decidedAt(): string { return $this->decidedAt; }
    public function correlationId(): string { return $this->correlationId; }
    public function decisionVersion(): string { return $this->decisionVersion; }

    public function idempotencyKey(string $provider): string
    {
        $provider = strtolower(trim($provider));
        if (!preg_match('/^[a-z0-9_-]{1,32}$/', $provider)) {
            throw new InvalidArgumentException('Provider no válido para idempotencia.');
        }
        return hash('sha256', implode('|', [
            'access-sync-v1', $provider, $this->empresaId, $this->sedeId,
            $this->socioId, $this->estado, $this->reasonCode, $this->decisionVersion,
        ]));
    }

    public function toArray(): array
    {
        return [
            'empresa_id' => $this->empresaId,
            'sede_id' => $this->sedeId,
            'socio_id' => $this->socioId,
            'estado' => $this->estado,
            'reason_code' => $this->reasonCode,
            'timestamp' => $this->decidedAt,
            'correlation_id' => $this->correlationId,
            'decision_version' => $this->decisionVersion,
        ];
    }

    private static function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-'
            . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-'
            . substr($hex, 20, 12);
    }
}
