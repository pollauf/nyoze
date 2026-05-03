<?php

namespace Nyoze\Data\Database;

use PDO;
use PDOException;

/**
 * Component responsible for running pending migrations
 * and managing the nyoze_migrations table.
 */
class MigrationRunner
{
    private const TABLE = 'nyoze_migrations';

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $migrationsPath = 'database/migrations',
    ) {}

    /**
     * Create the nyoze_migrations table if it does not exist.
     */
    public function ensureTable(): void
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'mysql') {
            $idColumn = 'id INT AUTO_INCREMENT PRIMARY KEY';
        } else {
            $idColumn = 'id INTEGER PRIMARY KEY AUTOINCREMENT';
        }

        $sql = sprintf(
            'CREATE TABLE IF NOT EXISTS %s ('
            . '%s, '
            . 'migration VARCHAR(255) NOT NULL, '
            . 'executed_at DATETIME DEFAULT CURRENT_TIMESTAMP'
            . ')',
            self::TABLE,
            $idColumn,
        );

        $this->pdo->exec($sql);
    }

    /**
     * Return the list of already executed migrations.
     *
     * @return string[] file names
     */
    public function getExecuted(): array
    {
        $stmt = $this->pdo->query(
            sprintf('SELECT migration FROM %s ORDER BY id ASC', self::TABLE),
        );

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Return the list of pending migrations (in chronological order).
     *
     * @return string[] pending file names
     */
    public function getPending(): array
    {
        $this->ensureTable();

        $executed = $this->getExecuted();

        $pattern = rtrim($this->migrationsPath, '/') . '/*.sql';
        $files = glob($pattern) ?: [];

        $pending = [];

        foreach ($files as $file) {
            $basename = basename($file);

            // Exclude rollback files
            if (str_ends_with($basename, '_rollback.sql')) {
                continue;
            }

            if (!in_array($basename, $executed, true)) {
                $pending[] = $basename;
            }
        }

        sort($pending);

        return $pending;
    }

    /**
     * Run all pending migrations.
     *
     * @return array{executed: string[], failed: ?string, error: ?string}
     */
    public function run(): array
    {
        $this->ensureTable();

        $pending = $this->getPending();
        $executedList = [];

        foreach ($pending as $filename) {
            $filePath = rtrim($this->migrationsPath, '/') . '/' . $filename;
            $sql = file_get_contents($filePath);

            if ($sql === false) {
                return [
                    'executed' => $executedList,
                    'failed'   => $filename,
                    'error'    => "Could not read file: {$filePath}",
                ];
            }

            try {
                $this->pdo->exec($sql);
                $this->recordMigration($filename);
                $executedList[] = $filename;
            } catch (PDOException $e) {
                return [
                    'executed' => $executedList,
                    'failed'   => $filename,
                    'error'    => $e->getMessage(),
                ];
            }
        }

        return [
            'executed' => $executedList,
            'failed'   => null,
            'error'    => null,
        ];
    }

    /**
     * Roll back the last executed migration.
     *
     * @return array{rolled_back: ?string, manual_required: bool, error: ?string}
     */
    public function rollback(): array
    {
        $this->ensureTable();

        $stmt = $this->pdo->query(
            sprintf('SELECT migration FROM %s ORDER BY id DESC LIMIT 1', self::TABLE),
        );

        $lastMigration = $stmt->fetchColumn();

        if ($lastMigration === false) {
            return [
                'rolled_back'     => null,
                'manual_required' => false,
                'error'           => null,
            ];
        }

        // Derive rollback filename: replace .sql with _rollback.sql
        $rollbackFilename = str_replace('.sql', '_rollback.sql', $lastMigration);
        $rollbackPath = rtrim($this->migrationsPath, '/') . '/' . $rollbackFilename;

        if (!file_exists($rollbackPath)) {
            return [
                'rolled_back'     => null,
                'manual_required' => true,
                'error'           => null,
            ];
        }

        $sql = file_get_contents($rollbackPath);

        if ($sql === false) {
            return [
                'rolled_back'     => null,
                'manual_required' => false,
                'error'           => "Could not read rollback file: {$rollbackPath}",
            ];
        }

        try {
            $this->pdo->exec($sql);
            $this->removeMigration($lastMigration);

            return [
                'rolled_back'     => $lastMigration,
                'manual_required' => false,
                'error'           => null,
            ];
        } catch (PDOException $e) {
            return [
                'rolled_back'     => null,
                'manual_required' => false,
                'error'           => $e->getMessage(),
            ];
        }
    }

    /**
     * Record a migration as executed.
     */
    private function recordMigration(string $filename): void
    {
        $stmt = $this->pdo->prepare(
            sprintf('INSERT INTO %s (migration) VALUES (:migration)', self::TABLE),
        );

        $stmt->execute(['migration' => $filename]);
    }

    /**
     * Remove the record of a migration.
     */
    private function removeMigration(string $filename): void
    {
        $stmt = $this->pdo->prepare(
            sprintf('DELETE FROM %s WHERE migration = :migration', self::TABLE),
        );

        $stmt->execute(['migration' => $filename]);
    }
}
