<?php

namespace Nyoze\CLI\Commands;

use Nyoze\CLI\Command;
use Nyoze\CLI\Console;
use Nyoze\Data\Database\SchemaBuilder;

/**
 * Dump full SQL schema.
 */
class SchemaDumpCommand extends Command
{
    public function name(): string
    {
        return 'schema:dump';
    }

    public function description(): string
    {
        return 'Dump full SQL schema';
    }

    public function handle(array $args, Console $console): int
    {
        $app = $console->app();
        $entities = $app->registry()->all();

        $dbConfig = $app->database();
        $builder = new SchemaBuilder($dbConfig->getProvider(), $dbConfig->getIdStrategy());

        $sql = $builder->buildAll($entities);

        if (empty($sql)) {
            $console->info('No tables to generate (all entities are virtual).');
            return 0;
        }

        if (in_array('--file', $args, true)) {
            $dir = 'database';
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            file_put_contents("{$dir}/schema.sql", $sql);
            $console->info('Schema written to database/schema.sql');
        } else {
            $console->line($sql);
        }

        return 0;
    }
}
