<?php

namespace Nyoze\CLI\Commands;

use Nyoze\CLI\Command;
use Nyoze\CLI\Console;
use Nyoze\Data\Database\SchemaBuilder;
use Nyoze\Data\Database\SchemaSnapshot;
use Nyoze\Data\Database\MySqlProvider;

/**
 * Generate migration files from entities.
 * Compares current entity state with schema.json snapshot to generate only diffs.
 */
class MakeMigrationCommand extends Command
{
    public function name(): string
    {
        return 'make:migration';
    }

    public function description(): string
    {
        return 'Generate migration files from entities';
    }

    public function handle(array $args, Console $console): int
    {
        $app = $console->app();
        $entities = $app->registry()->all();

        if (empty($entities)) {
            $console->info('No entities registered.');
            return 0;
        }

        $dbConfig = $app->database();
        $provider = $dbConfig->getProvider();
        $idStrategy = $dbConfig->getIdStrategy();
        $builder = new SchemaBuilder($provider, $idStrategy);

        $dir = 'database/migrations';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        // Load existing snapshot
        $snapshot = new SchemaSnapshot('database/schema.json');
        $oldState = $snapshot->load();

        // Build current state
        $currentState = [];
        foreach ($entities as $entity) {
            if ($entity->isVirtual()) {
                continue;
            }
            $currentState[$entity->name] = SchemaSnapshot::entityToSnapshot($entity, $idStrategy);
        }

        // Compare
        $diff = SchemaSnapshot::diff($oldState, $currentState);

        $timestamp = date('Y_m_d_His');
        $count = 0;

        // Topologically sort new tables so referenced tables are created first
        $newTables = $this->sortByDependencies($diff['new'], $app);

        // Generate CREATE TABLE for new entities (in dependency order)
        foreach ($newTables as $index => $tableName) {
            $entity = $app->registry()->get($tableName);
            $sql = $builder->buildTable($entity);
            if ($sql === '') {
                continue;
            }

            $order = str_pad((string) $index, 3, '0', STR_PAD_LEFT);
            $filename = "{$timestamp}_{$order}_{$tableName}.sql";
            file_put_contents("{$dir}/{$filename}", $sql);
            $console->info("Created: {$filename}");
            $count++;
        }

        // Generate ALTER TABLE for modified entities
        if ($provider instanceof MySqlProvider) {
            foreach ($diff['modified'] as $tableName => $changes) {
                $sql = $provider->alterTable($tableName, $changes);
                if (empty($sql)) {
                    continue;
                }

                $filename = "{$timestamp}_alter_{$tableName}.sql";
                file_put_contents("{$dir}/{$filename}", $sql);
                $console->info("Created: {$filename}");
                $count++;
            }
        }

        // Generate DROP TABLE for removed entities
        foreach ($diff['removed'] as $tableName) {
            $sql = "DROP TABLE IF EXISTS {$tableName};";
            $filename = "{$timestamp}_drop_{$tableName}.sql";
            file_put_contents("{$dir}/{$filename}", $sql);
            $console->info("Created: {$filename}");
            $count++;
        }

        if ($count === 0) {
            $console->info('No changes detected. Schema is up to date.');
            return 0;
        }

        // Save updated snapshot
        $snapshot->save($currentState);
        $console->info("Schema snapshot updated (database/schema.json).");

        return 0;
    }

    /**
     * Topologically sort table names so that tables referenced by foreign keys
     * are created before the tables that depend on them.
     *
     * @param string[] $tableNames
     * @return string[]
     */
    private function sortByDependencies(array $tableNames, \Nyoze\Core\App $app): array
    {
        $tableSet = array_flip($tableNames);

        // Build adjacency: table -> list of tables it depends on (within this batch)
        $deps = [];
        foreach ($tableNames as $name) {
            $deps[$name] = [];
            $entity = $app->registry()->get($name);
            if ($entity === null) {
                continue;
            }
            foreach ($entity->getFields() as $field) {
                if ($field->refEntity !== null && isset($tableSet[$field->refEntity]) && $field->refEntity !== $name) {
                    $deps[$name][] = $field->refEntity;
                }
            }
        }

        // Kahn's algorithm for topological sort
        $sorted = [];
        $visited = [];

        $visit = function (string $name) use (&$visit, &$sorted, &$visited, $deps): void {
            if (isset($visited[$name])) {
                return;
            }
            $visited[$name] = true;

            foreach ($deps[$name] ?? [] as $dep) {
                $visit($dep);
            }

            $sorted[] = $name;
        };

        foreach ($tableNames as $name) {
            $visit($name);
        }

        return $sorted;
    }
}
