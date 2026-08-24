<?php

/** Importa dumps SQL/MariaDB, incluidas rutinas y triggers con DELIMITER. */
final class SqlDumpImporter
{
    public static function import(PDO $db, string $file): void
    {
        $gzip = str_ends_with(strtolower($file), '.gz');
        $handle = $gzip ? @gzopen($file, 'rb') : @fopen($file, 'rb');
        if (!$handle) throw new RuntimeException('No se pudo abrir el dump.');

        $delimiter = ';';
        $statement = '';
        try {
            while (($line = $gzip ? gzgets($handle) : fgets($handle)) !== false) {
                $trimmedLine = trim($line);
                if ($statement === '' && preg_match('/^DELIMITER\s+(\S+)$/i', $trimmedLine, $match) === 1) {
                    $delimiter = $match[1];
                    continue;
                }
                if ($statement === '' && ($trimmedLine === '' || str_starts_with($trimmedLine, '--')
                    || str_starts_with($trimmedLine, '#'))) {
                    continue;
                }
                $statement .= $line;
                $candidate = rtrim($statement);
                if ($candidate === '' || !str_ends_with($candidate, $delimiter)) continue;

                $sql = trim(substr($candidate, 0, -strlen($delimiter)));
                $sql = self::withoutDefiner($sql);
                if ($sql !== '') $db->exec($sql);
                $statement = '';
            }
        } finally {
            $gzip ? gzclose($handle) : fclose($handle);
        }
        if (trim($statement) !== '') throw new RuntimeException('El dump termina con una sentencia incompleta.');
    }

    /**
     * Los dumps incluyen el DEFINER del usuario que creó triggers/vistas.
     * Ese usuario no existe necesariamente durante disaster recovery y el
     * usuario de restore no debe necesitar privilegios SET USER/SUPER.
     */
    private static function withoutDefiner(string $sql): string
    {
        $principal = "(?:`[^`]*`|'[^']*'|[A-Za-z0-9_$.:-]+)";
        $account = $principal . '\\s*@\\s*' . $principal;
        $sql = preg_replace(
            '~\/\\*!\\d{5}\\s+DEFINER\\s*=\\s*' . $account . '\\s*\\*\/~i',
            '',
            $sql
        ) ?? $sql;
        return preg_replace(
            '~\\bDEFINER\\s*=\\s*' . $account . '\\s*(?=(?:TRIGGER|PROCEDURE|FUNCTION|EVENT|VIEW)\\b)~i',
            '',
            $sql
        ) ?? $sql;
    }
}
