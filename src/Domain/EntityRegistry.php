<?php

namespace Nyoze\Domain;

use RuntimeException;

class EntityRegistry
{
    /** @var array<string, Entity> */
    private array $entities = [];

    public function register(Entity $entity): void
    {
        $this->entities[$entity->name] = $entity;
    }

    public function get(string $name): Entity
    {
        if (!isset($this->entities[$name])) {
            throw new RuntimeException("Entity '{$name}' is not registered.");
        }
        return $this->entities[$name];
    }

    public function has(string $name): bool
    {
        return isset($this->entities[$name]);
    }

    /** @return Entity[] */
    public function all(): array
    {
        return $this->entities;
    }

    /** @return string[] */
    public function names(): array
    {
        return array_keys($this->entities);
    }
}
