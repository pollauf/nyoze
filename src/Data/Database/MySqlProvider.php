<?php

namespace Nyoze\Data\Database;

use Nyoze\Domain\FieldType;

class MySqlProvider implements DatabaseProviderInterface
{
    public function name(): string
    {
        return 'mysql';
    }

    public function createTable(TableDefinition $table): string
    {
        $columns = [];

        $columns[] = '    ' . $this->idColumnSql($table->idStrategy);

        foreach ($table->columns as $column) {
            $columns[] = '    ' . $this->columnSql($column);
        }

        if ($table->timestamps) {
            $columns[] = '    created_at DATETIME DEFAULT CURRENT_TIMESTAMP';
            $columns[] = '    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP';
        }

        // Add FOREIGN KEY constraints for Ref columns
        foreach ($table->columns as $column) {
            if ($column->type === \Nyoze\Domain\FieldType::Ref && $column->refEntity !== null) {
                $columns[] = "    FOREIGN KEY ({$column->name}) REFERENCES `{$column->refEntity}`(id)";
            }
        }

        $cols = implode(",\n", $columns);

        return "CREATE TABLE IF NOT EXISTS `{$table->name}` (\n{$cols}\n);";
    }

    public function createAll(array $tables): string
    {
        $statements = [];

        foreach ($tables as $table) {
            $statements[] = $this->createTable($table);
        }

        return implode("\n\n", $statements);
    }

    public function columnSql(ColumnDefinition $column): string
    {
        $type = $this->mysqlType($column->type);
        $sql = "{$column->name} {$type}";

        if ($column->required) {
            $sql .= ' NOT NULL';
        }

        if ($column->hasDefault && $column->default !== '__NOW__') {
            $default = is_bool($column->default)
                ? ($column->default ? '1' : '0')
                : (is_string($column->default) ? "'{$column->default}'" : $column->default);
            $sql .= " DEFAULT {$default}";
        }

        if ($column->hasDefault && $column->default === '__NOW__') {
            $sql .= ' DEFAULT CURRENT_TIMESTAMP';
        }

        if ($column->unique) {
            $sql .= ' UNIQUE';
        }

        return $sql;
    }

    public function idColumnSql(IdStrategy $strategy): string
    {
        return match ($strategy) {
            IdStrategy::Snowflake     => 'id BIGINT UNSIGNED NOT NULL PRIMARY KEY',
            IdStrategy::AutoIncrement => 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
            IdStrategy::Uuid          => 'id CHAR(36) NOT NULL PRIMARY KEY',
            IdStrategy::Ulid          => 'id CHAR(26) NOT NULL PRIMARY KEY',
        };
    }

    /**
     * Internal FieldType → MySQL type mapping.
     */
    private function mysqlType(FieldType $type): string
    {
        return match ($type) {
            FieldType::Id       => 'BIGINT UNSIGNED',
            FieldType::String   => 'VARCHAR(255)',
            FieldType::Text     => 'TEXT',
            FieldType::Integer  => 'INT',
            FieldType::BigInt   => 'BIGINT',
            FieldType::Decimal  => 'DECIMAL(12,2)',
            FieldType::Money    => 'DECIMAL(12,2)',
            FieldType::Boolean  => 'TINYINT(1)',
            FieldType::DateTime => 'DATETIME',
            FieldType::Date     => 'DATE',
            FieldType::Email    => 'VARCHAR(255)',
            FieldType::Password => 'VARCHAR(255)',
            FieldType::Json     => 'JSON',
            FieldType::Enum     => 'VARCHAR(50)',
            FieldType::Ref      => 'BIGINT UNSIGNED',
        };
    }

    /**
     * Generate ALTER TABLE SQL for schema changes.
     *
     * @param string $table Table name
     * @param array{added?: array, altered?: array, dropped?: string[]} $changes
     */
    public function alterTable(string $table, array $changes): string
    {
        $statements = [];

        // Add new columns
        if (!empty($changes['added'])) {
            foreach ($changes['added'] as $colName => $colDef) {
                $column = new ColumnDefinition(
                    name:       $colName,
                    type:       FieldType::from($colDef['type']),
                    required:   $colDef['required'] ?? false,
                    unique:     $colDef['unique'] ?? false,
                    nullable:   $colDef['nullable'] ?? false,
                    default:    $colDef['default'] ?? null,
                    hasDefault: $colDef['hasDefault'] ?? false,
                    refEntity:  $colDef['refEntity'] ?? null,
                );
                $statements[] = "ALTER TABLE `{$table}` ADD COLUMN {$this->columnSql($column)};";

                // Add FK if it's a ref
                if ($column->type === FieldType::Ref && $column->refEntity !== null) {
                    $statements[] = "ALTER TABLE `{$table}` ADD FOREIGN KEY ({$colName}) REFERENCES `{$column->refEntity}`(id);";
                }
            }
        }

        // Modify existing columns
        if (!empty($changes['altered'])) {
            foreach ($changes['altered'] as $colName => $colDef) {
                $column = new ColumnDefinition(
                    name:       $colName,
                    type:       FieldType::from($colDef['type']),
                    required:   $colDef['required'] ?? false,
                    unique:     $colDef['unique'] ?? false,
                    nullable:   $colDef['nullable'] ?? false,
                    default:    $colDef['default'] ?? null,
                    hasDefault: $colDef['hasDefault'] ?? false,
                    refEntity:  $colDef['refEntity'] ?? null,
                );
                $statements[] = "ALTER TABLE `{$table}` MODIFY COLUMN {$this->columnSql($column)};";
            }
        }

        // Drop columns
        if (!empty($changes['dropped'])) {
            foreach ($changes['dropped'] as $colName) {
                $statements[] = "ALTER TABLE `{$table}` DROP COLUMN {$colName};";
            }
        }

        return implode("\n", $statements);
    }
}
