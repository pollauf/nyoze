<?php

namespace Nyoze\Data\Database;

use Nyoze\Domain\Entity;
use Nyoze\Domain\FieldType;

/**
 * Manages a JSON snapshot of the current schema state.
 * Used by make:migration to detect changes and generate diffs.
 */
class SchemaSnapshot
{
    private string $path;

    public function __construct(string $path = 'database/schema.json')
    {
        $this->path = $path;
    }

    /**
     * Load the existing snapshot from disk.
     *
     * @return array<string, array> Keyed by table name
     */
    public function load(): array
    {
        if (!file_exists($this->path)) {
            return [];
        }

        $json = file_get_contents($this->path);
        $data = json_decode($json, true);

        return $data['tables'] ?? [];
    }

    /**
     * Save the current schema state to disk.
     *
     * @param array<string, array> $tables
     */
    public function save(array $tables): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $data = [
            'version' => 1,
            'generated_at' => date('Y-m-d H:i:s'),
            'tables' => $tables,
        ];

        file_put_contents($this->path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
    }

    /**
     * Build a snapshot representation of an entity.
     *
     * @return array{columns: array, idStrategy: string, timestamps: bool}
     */
    public static function entityToSnapshot(Entity $entity, IdStrategy $idStrategy): array
    {
        $columns = [];

        foreach ($entity->getFields() as $field) {
            if ($field->isId()) {
                continue;
            }

            $columns[$field->name] = [
                'type'       => $field->type->value,
                'required'   => $field->required,
                'unique'     => $field->unique,
                'nullable'   => $field->nullable,
                'default'    => $field->default,
                'hasDefault' => $field->hasDefault,
                'refEntity'  => $field->refEntity,
            ];
        }

        return [
            'columns'    => $columns,
            'idStrategy' => $idStrategy->value,
            'timestamps' => true,
        ];
    }

    /**
     * Compare current entity state with snapshot and return changes.
     *
     * @return array{new: string[], modified: array<string, array>, removed: string[]}
     */
    public static function diff(array $snapshot, array $current): array
    {
        $new = [];
        $modified = [];
        $removed = [];

        // Find new and modified tables
        foreach ($current as $table => $definition) {
            if (!isset($snapshot[$table])) {
                $new[] = $table;
            } elseif ($snapshot[$table] !== $definition) {
                // Detect column-level changes
                $oldCols = $snapshot[$table]['columns'] ?? [];
                $newCols = $definition['columns'] ?? [];

                $changes = [];

                // New columns
                foreach ($newCols as $colName => $colDef) {
                    if (!isset($oldCols[$colName])) {
                        $changes['added'][$colName] = $colDef;
                    } elseif ($oldCols[$colName] !== $colDef) {
                        $changes['altered'][$colName] = $colDef;
                    }
                }

                // Removed columns
                foreach ($oldCols as $colName => $colDef) {
                    if (!isset($newCols[$colName])) {
                        $changes['dropped'][] = $colName;
                    }
                }

                if (!empty($changes)) {
                    $modified[$table] = $changes;
                }
            }
        }

        // Find removed tables
        foreach ($snapshot as $table => $definition) {
            if (!isset($current[$table])) {
                $removed[] = $table;
            }
        }

        return [
            'new'      => $new,
            'modified' => $modified,
            'removed'  => $removed,
        ];
    }
}
