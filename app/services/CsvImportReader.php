<?php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/MigrationException.php';

/** Lector CSV en streaming con límites estrictos y encoding UTF-8. */
final class CsvImportReader
{
    private const ALLOWED_MIME = [
        'text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel',
        'application/octet-stream',
    ];

    public function inspect(string $path, string $logicalName): array
    {
        if (strtolower(pathinfo($logicalName, PATHINFO_EXTENSION)) !== 'csv') {
            throw new MigrationException('En esta fase solo se admiten archivos CSV.', 'extension_rejected');
        }
        $size = filesize($path);
        if ($size === false || $size <= 0 || $size > IMPORT_MAX_BYTES) {
            throw new MigrationException('El archivo está vacío o supera el tamaño permitido.', 'file_size');
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = strtolower((string) $finfo->file($path));
        if (!in_array($mime, self::ALLOWED_MIME, true)) {
            throw new MigrationException('El contenido del archivo no corresponde a un CSV permitido.', 'mime_rejected');
        }
        $handle = @fopen($path, 'rb');
        if (!$handle) throw new MigrationException('No se puede leer el CSV.', 'file_unreadable');
        try {
            $first = fread($handle, min(8192, (int) $size));
            if ($first === false || str_contains($first, "\0")) {
                throw new MigrationException('El archivo contiene datos binarios no permitidos.', 'binary_rejected');
            }
            $withoutBom = str_starts_with($first, "\xEF\xBB\xBF") ? substr($first, 3) : $first;
            if (!mb_check_encoding($withoutBom, 'UTF-8')) {
                throw new MigrationException('El archivo debe utilizar UTF-8.', 'encoding_rejected');
            }
            if (preg_match('/<\?(?:php|=)/i', $withoutBom)) {
                throw new MigrationException('El archivo contiene código ejecutable no permitido.', 'executable_content');
            }
        } finally {
            fclose($handle);
        }

        [$headers, $delimiter] = $this->readHeader($path);
        return ['headers' => $headers, 'delimiter' => $delimiter, 'mime' => $mime, 'size' => (int) $size];
    }

    private function readHeader(string $path): array
    {
        $handle = fopen($path, 'rb');
        if (!$handle) throw new MigrationException('No se puede leer el CSV.', 'file_unreadable');
        try {
            while (($line = fgets($handle)) !== false) {
                if (trim($line) === '') continue;
                $line = preg_replace('/^\xEF\xBB\xBF/', '', $line) ?? $line;
                $comma = str_getcsv($line, ',', '"', '\\');
                $semi = str_getcsv($line, ';', '"', '\\');
                $delimiter = count($semi) > count($comma) ? ';' : ',';
                $headers = $delimiter === ';' ? $semi : $comma;
                $headers = array_map(static fn($h) => trim((string) $h), $headers);
                if (count($headers) < 2 || in_array('', $headers, true)) {
                    throw new MigrationException('El CSV necesita encabezados completos.', 'invalid_headers');
                }
                $folded = array_map([self::class, 'foldHeader'], $headers);
                if (count(array_unique($folded)) !== count($folded)) {
                    throw new MigrationException('El CSV contiene encabezados duplicados.', 'duplicate_headers');
                }
                return [$headers, $delimiter];
            }
        } finally {
            fclose($handle);
        }
        throw new MigrationException('El CSV no contiene encabezados.', 'missing_headers');
    }

    public function rows(string $path, array $headers, string $delimiter): Generator
    {
        if (!in_array($delimiter, [',', ';'], true)) {
            throw new MigrationException('Delimitador CSV no permitido.', 'invalid_delimiter');
        }
        $handle = @fopen($path, 'rb');
        if (!$handle) throw new MigrationException('No se puede leer el CSV.', 'file_unreadable');
        $physical = 0;
        $dataRows = 0;
        $headerConsumed = false;
        try {
            while (($values = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
                $physical++;
                if (count($values) === 1 && trim((string) $values[0]) === '') continue;
                if (!$headerConsumed) {
                    $values[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $values[0]) ?? $values[0];
                    $actual = array_map(static fn($v) => trim((string) $v), $values);
                    if ($actual !== $headers) {
                        throw new MigrationException('Los encabezados cambiaron durante la lectura.', 'headers_changed');
                    }
                    $headerConsumed = true;
                    continue;
                }
                $dataRows++;
                if ($dataRows > IMPORT_MAX_ROWS) {
                    throw new MigrationException('El CSV supera el máximo de filas permitido.', 'row_limit');
                }
                foreach ($values as $value) {
                    if (!mb_check_encoding((string) $value, 'UTF-8')) {
                        throw new MigrationException('Una fila no utiliza UTF-8 válido.', 'encoding_rejected');
                    }
                }
                $row = [];
                foreach ($headers as $i => $header) $row[$header] = trim((string) ($values[$i] ?? ''));
                $extra = count($values) > count($headers);
                yield ['row_number' => $physical, 'values' => $row, 'extra_columns' => $extra];
            }
        } finally {
            fclose($handle);
        }
    }

    public static function foldHeader(string $value): string
    {
        $value = trim(mb_strtolower($value, 'UTF-8'));
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $value = $ascii !== false ? $ascii : $value;
        return trim(preg_replace('/[^a-z0-9]+/', '_', $value) ?? '', '_');
    }
}
