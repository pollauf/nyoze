<?php

namespace Nyoze\Data\Database;

use Nyoze\Domain\Entity;

/**
 * Central orchestrator that converts entities into TableDefinition
 * and delegates SQL generation to the configured provider.
 */
class SchemaBuilder
{
    public function __construct(
        private readonly DatabaseProviderInterface $provider,
        private readonly IdStrategy $defaultStrategy = IdStrategy::Snowflake,
    ) {}

    /**
     * Generate CREATE TABLE SQL for an entity.
     * Returns an empty string for virtual entities.
     */
    public function buildTable(Entity $entity, ?IdStrategy $overrideStrategy = null): string
    {
        if ($entity->isVirtual()) {
            return '';
        }

        $strategy = $overrideStrategy ?? $this->defaultStrategy;
        $table = $this->buildTableDefinition($entity, $strategy);

        return $this->provider->createTable($table);
    }

    /**
     * Generate SQL for all non-virtual entities.
     *
     * @param Entity[] $entities
     */
    public function buildAll(array $entities): string
    {
        $statements = [];

        foreach ($entities as $entity) {
            $sql = $this->buildTable($entity);
            if ($sql !== '') {
                $statements[] = $sql;
            }
        }

        return implode("\n\n", $statements);
    }

    /**
     * Convert an entity's fields into ColumnDefinition[].
     * Skips fields of type FieldType::Id.
     *
     * @return ColumnDefinition[]
     */
    private function buildColumns(Entity $entity): array
    {
        $columns = [];

        foreach ($entity->getFields() as $field) {
            if ($field->isId()) {
                continue;
            }

            $columns[] = ColumnDefinition::fromField($field);
        }

        return $columns;
    }

    /**
     * Build a TableDefinition from an entity.
     */
    private function buildTableDefinition(Entity $entity, IdStrategy $strategy): TableDefinition
    {
        return new TableDefinition(
            name:       $entity->name,
            columns:    $this->buildColumns($entity),
            idStrategy: $strategy,
            timestamps: true,
        );
    }
}
