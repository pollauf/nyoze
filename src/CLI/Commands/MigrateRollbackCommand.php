<?php

namespace Nyoze\CLI\Commands;

use Nyoze\CLI\Command;
use Nyoze\CLI\Console;
use Nyoze\Data\Database\MigrationRunner;

/**
 * Rollback last migration.
 */
class MigrateRollbackCommand extends Command
{
    public function name(): string
    {
        return 'migrate:rollback';
    }

    public function description(): string
    {
        return 'Rollback last migration';
    }

    public function handle(array $args, Console $console): int
    {
        $pdo = $console->app()->database()->getPdo();

        if ($pdo === null) {
            $console->error('No database connection configured.');
            return 1;
        }

        $runner = new MigrationRunner($pdo);
        $result = $runner->rollback();

        if ($result['rolled_back'] !== null) {
            $console->info("Rolled back: {$result['rolled_back']}");
            return 0;
        }

        if ($result['manual_required']) {
            $console->error('No rollback file found. Manual rollback required.');
            return 1;
        }

        if ($result['error'] !== null) {
            $console->error("Rollback failed: {$result['error']}");
            return 1;
        }

        $console->info('Nothing to rollback.');
        return 0;
    }
}
