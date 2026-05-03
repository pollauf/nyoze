<?php

namespace Nyoze\Data\Database;

use Nyoze\Domain\FieldType;

class SqliteProvider implements DatabaseProviderInterface
{
    public function name(): string
    {
        return 'sqlite';
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
            $columns[] = '    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP';
        }

        $cols = implode(",\n", $columns);

        return "CREATE TABLE IF NOT EXISTS {$table->name} (\n{$cols}\n);";
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
        $type = $this->sqliteType($column->type);
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
        // SQLite always uses AUTOINCREMENT for backward compatibility
        return 'id INTEGER PRIMARY KEY AUTOINCREMENT';
    }

    /**
     * Internal FieldType → SQLite type mapping.
     * Reproduces the exact mapping from the legacy Schema class.
     */
    private function sqliteType(FieldType $type): string
    {
        return match ($type) {
            FieldType::Id       => 'INTEGER',
            FieldType::String   => 'VARCHAR(255)',
            FieldType::Text     => 'TEXT',
            FieldType::Integer  => 'INTEGER',
            FieldType::BigInt   => 'BIGINT',
            FieldType::Decimal  => 'DECIMAL(12,2)',
            FieldType::Money    => 'DECIMAL(12,2)',
            FieldType::Boolean  => 'BOOLEAN',
            FieldType::DateTime => 'DATETIME',
            FieldType::Date     => 'DATE',
            FieldType::Email    => 'VARCHAR(255)',
            FieldType::Password => 'VARCHAR(255)',
            FieldType::Json     => 'TEXT',
            FieldType::Enum     => 'VARCHAR(50)',
            FieldType::Ref      => 'INTEGER',
        };
    }
}
