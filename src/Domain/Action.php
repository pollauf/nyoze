<?php

namespace Nyoze\Domain;

use Closure;

/**
 * Immutable definition of an entity action.
 *
 * The handler can be:
 *   - A Closure
 *   - A class name (string) implementing __invoke(ActionContext): Result
 *   - null (for built-in entity actions like CRUD)
 *
 * The type distinguishes between:
 *   - 'entity' — built-in CRUD operations declared via EntityAction
 *   - 'custom' — user-defined actions with explicit handlers
 */
final readonly class Action
{
    public function __construct(
        public string              $name,
        public Closure|string|null $handler    = null,
        public string              $httpMethod = 'POST',
        public string              $type       = 'custom',
    ) {}
}
