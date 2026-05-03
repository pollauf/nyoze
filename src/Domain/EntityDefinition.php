<?php

namespace Nyoze\Domain;

/**
 * Base class for entity definitions.
 * Each entity gets its own file, its own class.
 */
abstract class EntityDefinition
{
    abstract public function name(): string;

    abstract public function define(Entity $entity): void;
}
