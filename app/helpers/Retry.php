<?php

final class Retry
{
    /** @return mixed */
    public static function limited(callable $operation, int $attempts = 3, int $baseDelayMilliseconds = 250)
    {
        $attempts = max(1, min(5, $attempts));
        $last = null;
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                return $operation($attempt);
            } catch (Throwable $e) {
                $last = $e;
                if ($attempt < $attempts && $baseDelayMilliseconds > 0) {
                    usleep($attempt * min(2000, $baseDelayMilliseconds) * 1000);
                }
            }
        }
        throw $last ?: new RuntimeException('La operación agotó los reintentos.');
    }
}
