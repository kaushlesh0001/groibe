<?php

namespace App\Services\Ops;

use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;

/**
 * Writes the whole database as one gzipped SQL file in pure PHP (spec 17.4), because a shared host often has no `mysqldump`
 * and no `exec`. Rows are streamed one at a time, so memory stays flat whatever the size. MySQL, MariaDB and SQLite are
 * supported (SQLite is what local development and the tests run on). Restore with the `mysql` client or phpMyAdmin.
 *
 * The data of `sessions`, `cache` and the reset tokens is left out on purpose: it is short-lived, a restore does not need
 * it, and a backup should not carry other people's open sessions.
 */
class DatabaseDumper
{
    /** Tables whose structure is dumped but whose rows are not. */
    private const STRUCTURE_ONLY = ['sessions', 'cache', 'cache_locks', 'password_reset_tokens'];

    private const ROWS_PER_INSERT = 100;

    /** @return array{tables: int, rows: int} */
    public function dump(string $path): array
    {
        $pdo = DB::connection()->getPdo();
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if (! in_array($driver, ['mysql', 'sqlite'], true)) {
            throw new RuntimeException("Backups support MySQL, MariaDB and SQLite, not {$driver}.");
        }

        $file = gzopen($path, 'wb9');

        if ($file === false) {
            throw new RuntimeException('The backup file could not be created.');
        }

        $tables = 0;
        $rows = 0;

        try {
            $this->write($file, $this->header($driver));

            foreach ($this->tables($pdo, $driver) as $table => $create) {
                $tables++;
                $this->write($file, ($driver === 'mysql' ? "DROP TABLE IF EXISTS {$this->quoteName($table, $driver)};\n" : '').$create.";\n\n");

                if (! in_array($table, self::STRUCTURE_ONLY, true)) {
                    $rows += $this->rows($file, $pdo, $driver, $table);
                }
            }

            $this->write($file, $this->footer($driver));
        } finally {
            gzclose($file);
        }

        return ['tables' => $tables, 'rows' => $rows];
    }

    /** @return array<string, string> table name => CREATE statement */
    private function tables(PDO $pdo, string $driver): array
    {
        $tables = [];

        if ($driver === 'sqlite') {
            $statement = $pdo->query("SELECT name, sql FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name");

            foreach ($statement->fetchAll(PDO::FETCH_NUM) as [$name, $sql]) {
                $tables[(string) $name] = (string) $sql;
            }

            return $tables;
        }

        $names = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);

        foreach ($names as $name) {
            $row = $pdo->query('SHOW CREATE TABLE '.$this->quoteName((string) $name, $driver))->fetch(PDO::FETCH_NUM);
            $tables[(string) $name] = (string) $row[1];
        }

        return $tables;
    }

    /**
     * Streams a table's rows as multi-row INSERT statements. MySQL buffers a whole result by default, so buffering is
     * switched off for the read and restored after.
     *
     * @param  resource  $file
     */
    private function rows($file, PDO $pdo, string $driver, string $table): int
    {
        $buffered = $driver === 'mysql' ? $pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY) : null;

        if ($driver === 'mysql') {
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        }

        try {
            $statement = $pdo->query('SELECT * FROM '.$this->quoteName($table, $driver));
            $statement->setFetchMode(PDO::FETCH_NUM);

            $columns = null;
            $batch = [];
            $count = 0;

            while (($row = $statement->fetch()) !== false) {
                if ($columns === null) {
                    $columns = implode(', ', array_map(fn (int $i): string => $this->quoteName($statement->getColumnMeta($i)['name'], $driver), array_keys($row)));
                }

                $batch[] = '('.implode(', ', array_map(fn ($value): string => $this->value($pdo, $driver, $value), $row)).')';
                $count++;

                if (count($batch) >= self::ROWS_PER_INSERT) {
                    $this->flush($file, $driver, $table, (string) $columns, $batch);
                    $batch = [];
                }
            }

            $statement->closeCursor();
            $this->flush($file, $driver, $table, (string) $columns, $batch);

            return $count;
        } finally {
            if ($driver === 'mysql') {
                $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
            }
        }
    }

    /**
     * @param  resource  $file
     * @param  list<string>  $batch
     */
    private function flush($file, string $driver, string $table, string $columns, array $batch): void
    {
        if ($batch === []) {
            return;
        }

        $this->write($file, "INSERT INTO {$this->quoteName($table, $driver)} ({$columns}) VALUES\n".implode(",\n", $batch).";\n");
    }

    private function value(PDO $pdo, string $driver, mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        $value = (string) $value;

        // Bytes that are not text (a binary column) travel as hex so no character set can change them.
        if ($value !== '' && ! mb_check_encoding($value, 'UTF-8')) {
            return $driver === 'mysql' ? '0x'.bin2hex($value) : "X'".bin2hex($value)."'";
        }

        return (string) $pdo->quote($value);
    }

    private function quoteName(string $name, string $driver): string
    {
        return $driver === 'mysql' ? '`'.str_replace('`', '``', $name).'`' : '"'.str_replace('"', '""', $name).'"';
    }

    private function header(string $driver): string
    {
        $intro = '-- '.config('app.name').' database backup, '.now()->toDateTimeString().' '.config('app.timezone')."\n"
            .'-- Contains personal data. Keep it private and never place it in a web-served folder.'."\n\n";

        return $intro.($driver === 'mysql'
            ? "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET UNIQUE_CHECKS=0;\n\n"
            : "PRAGMA foreign_keys=OFF;\nBEGIN TRANSACTION;\n\n");
    }

    private function footer(string $driver): string
    {
        return $driver === 'mysql'
            ? "SET UNIQUE_CHECKS=1;\nSET FOREIGN_KEY_CHECKS=1;\n"
            : "COMMIT;\nPRAGMA foreign_keys=ON;\n";
    }

    /** @param  resource  $file */
    private function write($file, string $sql): void
    {
        if (gzwrite($file, $sql) === false) {
            throw new RuntimeException('The backup file could not be written. The disk may be full.');
        }
    }
}
