<?php

namespace Nyoze\Domain;

/**
 * Represents a built-in entity capability (CRUD operation).
 *
 * EntityAction objects are passed to Entity::can() to declare
 * standard operations with automatic HTTP method inference.
 *
 * Usage:
 *   ->can(EntityAction::create())
 *   ->can(EntityAction::list())
 *   ->can(EntityAction::get())
 *   ->can(EntityAction::update())
 *   ->can(EntityAction::delete())
 *   ->can(EntityAction::all())
 */
final readonly class EntityAction
{
    private function __construct(
        public string $name,
        public string $httpMethod,
    ) {}

    public static function create(): self
    {
        return new self('create', 'POST');
    }

    public static function list(): self
    {
        return new self('list', 'GET');
    }

    public static function get(): self
    {
        return new self('get', 'GET');
    }

    public static function update(): self
    {
        return new self('update', 'PUT');
    }

    public static function delete(): self
    {
        return new self('delete', 'DELETE');
    }

    /**
     * Returns all standard CRUD operations.
     *
     * @return self[]
     */
    public static function all(): array
    {
        return [
            self::create(),
            self::list(),
            self::get(),
            self::update(),
            self::delete(),
        ];
    }

    /**
     * Valid entity action names.
     */
    public static function validNames(): array
    {
        return ['create', 'list', 'get', 'update', 'delete'];
    }
}
