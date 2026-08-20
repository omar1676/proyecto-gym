<?php

/** Resultado técnico normalizado de un provider, sin secretos ni datos brutos. */
final class AccessControlResult
{
    public const SUCCESS = 'SUCCESS';
    public const DUPLICATE = 'DUPLICATE';
    public const NOT_FOUND = 'NOT_FOUND';
    public const UNAVAILABLE = 'UNAVAILABLE';
    public const TIMEOUT = 'TIMEOUT';
    public const ERROR = 'ERROR';

    private string $code;
    private int $latencyMs;
    private array $data;

    public function __construct(string $code, int $latencyMs = 0, array $data = [])
    {
        if (!in_array($code, [
            self::SUCCESS, self::DUPLICATE, self::NOT_FOUND,
            self::UNAVAILABLE, self::TIMEOUT, self::ERROR,
        ], true)) {
            throw new InvalidArgumentException('Resultado de provider no válido.');
        }
        $this->code = $code;
        $this->latencyMs = max(0, $latencyMs);
        $this->data = $data;
    }

    public function code(): string { return $this->code; }
    public function latencyMs(): int { return $this->latencyMs; }
    public function data(): array { return $this->data; }
    public function successful(): bool { return in_array($this->code, [self::SUCCESS, self::DUPLICATE], true); }
    public function retryable(): bool { return in_array($this->code, [self::UNAVAILABLE, self::TIMEOUT, self::ERROR], true); }
}
