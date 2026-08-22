<?php

require_once __DIR__ . '/config.php';

final class DatabaseUnavailableException extends RuntimeException
{
}

class Database
{
    private static $instance = null;
    private $connection;

    private function __construct()
    {
        // Las suites de pruebas definen MODO_PRUEBAS antes de tocar nada (ver
        // pruebas/_arranque.php) y entonces se conecta a la base de pruebas.
        // Nunca a la de trabajo: las suites borran filas para dejar el estado
        // limpio, y hacerlo sobre datos reales sería un desastre.
        $modoPruebas = (defined('MODO_PRUEBAS') && MODO_PRUEBAS) || APP_ENV === 'test';
        if ($modoPruebas && APP_ENV === 'production') {
            throw new RuntimeException('Las pruebas no pueden usar APP_ENV=production.');
        }
        $base = ($modoPruebas && defined('DB_NAME_PRUEBAS') && DB_NAME_PRUEBAS !== '')
            ? DB_NAME_PRUEBAS
            : DB_NAME;

        $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . $base . ";charset=" . DB_CHARSET;

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $this->connection = new PDO($dsn, DB_USER, DB_PASS, $options);
            // La hora de MySQL tiene que ser la misma que la de PHP: la caja
            // "del día" y los vencimientos se calculan en los dos sitios.
            $this->connection->exec("SET time_zone = '" . self::desfaseHorario() . "'");
        } catch (PDOException $e) {
            // Nunca conviertas una caída de infraestructura en una terminación
            // satisfactoria ni copies DSN/rutas del driver a la respuesta o al
            // log genérico. La capa que conoce el contexto (HTTP/CLI) decide el
            // código de estado y el mensaje seguro.
            error_log('database_connection_failed');
            throw new DatabaseUnavailableException('Database unavailable.');
        }
    }

    /** Desfase actual de PHP respecto a UTC, en el formato que entiende MySQL (+02:00). */
    private static function desfaseHorario(): string
    {
        $minutos = (int) round((new DateTimeZone(date_default_timezone_get()))
            ->getOffset(new DateTime('now')) / 60);
        $signo   = $minutos < 0 ? '-' : '+';
        $minutos = abs($minutos);
        return sprintf('%s%02d:%02d', $signo, intdiv($minutos, 60), $minutos % 60);
    }

    /** Nombre de la base a la que se ha conectado de verdad. */
    public function nombreBase(): string
    {
        return (string) $this->connection->query('SELECT DATABASE()')->fetchColumn();
    }

    public static function getInstance(): Database
    {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }

    public function getConnection(): PDO
    {
        return $this->connection;
    }
}
