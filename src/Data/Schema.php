<?php

namespace Nyoze\Data;

use Nyoze\Core\App;
use Nyoze\Data\Database\IdStrategy;
use Nyoze\Data\Database\SchemaBuilder;
use Nyoze\Data\Database\SqliteProvider;
use Nyoze\Domain\Entity;
use Nyoze\Domain\FieldDefinition;
use Nyoze\Domain\FieldType;

/**
 * Generates SQL schema from entity field definitions.
 * Useful for migrations, bootstrapping, or introspection.
 *
 * Delegates internally to SchemaBuilder with the configured provider.
 * When no provider is configured, uses SqliteProvider to reproduce
 * the legacy SQLite-compatible behavior.
 */
class Schema
{
    private static ?App $app = null;

    /**
     * Set the App instance for provider resolution.
     * Called during application bootstrap.
     */
    public static function setApp(App $app): void
    {
        self::$app = $app;
    }

    /**
     * Generate CREATE TABLE SQL for an entity.
     */
    public static function createTable(Entity $entity): string
    {
        return self::getBuilder()->buildTable($entity);
    }

    /**
     * Generate CREATE TABLE SQL for all entities.
     *
     * @param Entity[] $entities
     */
    public static function createAll(array $entities): string
    {
        return self::getBuilder()->buildAll($entities);
    }

    /**
     * Obtain a SchemaBuilder using the configured provider or SqliteProvider fallback.
     */
    private static function getBuilder(): SchemaBuilder
    {
        if (self::$app !== null) {
            $dbConfig = self::$app->database();
            return new SchemaBuilder(
                $dbConfig->getProvider(),
                $dbConfig->getIdStrategy(),
            );
        }

        // Fallback: SqliteProvider with Snowflake (reproduces legacy behavior)
        return new SchemaBuilder(
            new SqliteProvider(),
            IdStrategy::Snowflake,
        );
    }

    /**
     * @deprecated Kept for backward compatibility. No longer called by main methods.
     */
    private static function columnSql(FieldDefinition $field): string
    {
        $type = match ($field->type) {
            FieldType::Id       => 'INTEGER',
            FieldType::String,
            FieldType::Email,
            FieldType::Password => 'VARCHAR(255)',
            FieldType::Text     => 'TEXT',
            FieldType::Integer  => 'INTEGER',
            FieldType::BigInt   => 'BIGINT',
            FieldType::Decimal,
            FieldType::Money    => 'DECIMAL(12,2)',
            FieldType::Boolean  => 'BOOLEAN',
            FieldType::DateTime => 'DATETIME',
            FieldType::Date     => 'DATE',
            FieldType::Json     => 'TEXT',
            FieldType::Enum     => 'VARCHAR(50)',
            FieldType::Ref      => 'INTEGER',
        };

        $sql = "{$field->name} {$type}";

        if ($field->required) {
            $sql .= ' NOT NULL';
        }

        if ($field->hasDefault && $field->default !== '__NOW__') {
            $default = is_bool($field->default)
                ? ($field->default ? '1' : '0')
                : (is_string($field->default) ? "'{$field->default}'" : $field->default);
            $sql .= " DEFAULT {$default}";
        }

        if ($field->default === '__NOW__') {
            $sql .= ' DEFAULT CURRENT_TIMESTAMP';
        }

        if ($field->unique) {
            $sql .= ' UNIQUE';
        }

        return $sql;
    }
}
