<?php

namespace Nyoze\Domain;

/**
 * Declarative field factory — the public API for defining entity fields.
 *
 * Usage:
 *   Field::string("name")->required()
 *   Field::email("email")->unique()
 *   Field::password("password")->hidden()
 *   Field::integer("stock")->default(0)
 *   Field::money("price")->required()
 *   Field::enum("status", OrderStatus::class)
 *   Field::ref("id_user", "users")
 */
class Field
{
    public static function id(string $name = 'id'): FieldBuilder
    {
        return new FieldBuilder($name, FieldType::Id);
    }

    public static function string(string $name): FieldBuilder
    {
        return new FieldBuilder($name, FieldType::String);
    }

    public static function text(string $name): FieldBuilder
    {
        return new FieldBuilder($name, FieldType::Text);
    }

    public static function integer(string $name): FieldBuilder
    {
        return new FieldBuilder($name, FieldType::Integer);
    }

    public static function bigint(string $name): FieldBuilder
    {
        return new FieldBuilder($name, FieldType::BigInt);
    }

    public static function decimal(string $name): FieldBuilder
    {
        return new FieldBuilder($name, FieldType::Decimal);
    }

    public static function boolean(string $name): FieldBuilder
    {
        return new FieldBuilder($name, FieldType::Boolean);
    }

    public static function datetime(string $name): FieldBuilder
    {
        return new FieldBuilder($name, FieldType::DateTime);
    }

    public static function date(string $name): FieldBuilder
    {
        return new FieldBuilder($name, FieldType::Date);
    }

    public static function email(string $name): FieldBuilder
    {
        return new FieldBuilder($name, FieldType::Email);
    }

    public static function password(string $name): FieldBuilder
    {
        return new FieldBuilder($name, FieldType::Password);
    }

    public static function money(string $name): FieldBuilder
    {
        return new FieldBuilder($name, FieldType::Money);
    }

    public static function json(string $name): FieldBuilder
    {
        return new FieldBuilder($name, FieldType::Json);
    }

    public static function enum(string $name, string $enumClass): FieldBuilder
    {
        return (new FieldBuilder($name, FieldType::Enum))->enumClass($enumClass);
    }

    public static function ref(string $name, string $entity): FieldBuilder
    {
        return (new FieldBuilder($name, FieldType::Ref))->references($entity);
    }
}
