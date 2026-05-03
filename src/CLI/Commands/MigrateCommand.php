<?php

namespace Nyoze\CLI\Commands;

use Nyoze\CLI\Command;
use Nyoze\CLI\Console;
use Nyoze\Data\Database\MigrationRunner;

/**
 * Run pending migrations.
 */
class MigrateCommand extends Command
{
    public function name(): string
    {
        return 'migrate';
    }

    public function description(): string
    {
        return 'Run pending migrations';
    }

    public function handle(array $args, Console $console): int
    {
        $pdo = $console->app()->database()->getPdo();

        if ($pdo === null) {
            $console->error('No database connection configured.');
            return 1;
        }

        $runner = new MigrationRunner($pdo);
        $result = $runner->run();

        if (empty($result['executed']) && $result['failed'] === null) {
            $console->info('Nothing to migrate.');
            return 0;
        }

        foreach ($result['executed'] as $migration) {
            $console->info("✓ {$migration}");
        }

        if ($result['failed'] !== null) {
            $console->error("✗ {$result['failed']}: {$result['error']}");
            return 1;
        }

        return 0;
    }
}
