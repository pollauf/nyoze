<?php

namespace Nyoze\Data\Database;

final readonly class TableDefinition
{
    /**
     * @param string             $name       Table name
     * @param ColumnDefinition[] $columns    Columns (excluding id and timestamps)
     * @param IdStrategy         $idStrategy ID strategy
     * @param bool               $timestamps Whether to include created_at/updated_at
     */
    public function __construct(
        public string     $name,
        public array      $columns,
        public IdStrategy $idStrategy = IdStrategy::Snowflake,
        public bool       $timestamps = true,
    ) {}
}
