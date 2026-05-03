<?php

namespace Nyoze\Data\Database;

interface DatabaseProviderInterface
{
    /**
     * Provider identifier name (e.g. "mysql", "sqlite").
     */
    public function name(): string;

    /**
     * Generate CREATE TABLE SQL for a table definition.
     */
    public function createTable(TableDefinition $table): string;

    /**
     * Generate CREATE TABLE SQL for multiple tables, concatenated.
     *
     * @param TableDefinition[] $tables
     */
    public function createAll(array $tables): string;

    /**
     * Generate the SQL fragment for an individual column.
     */
    public function columnSql(ColumnDefinition $column): string;

    /**
     * Generate the SQL fragment for the primary ID column according to the strategy.
     */
    public function idColumnSql(IdStrategy $strategy): string;
}
