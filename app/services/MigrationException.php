<?php

final class MigrationException extends RuntimeException
{
    public function __construct(string $message, private string $safeCode = 'migration_error')
    {
        parent::__construct($message);
    }

    public function safeCode(): string
    {
        return $this->safeCode;
    }
}
