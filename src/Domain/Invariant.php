<?php

namespace Nyoze\Domain;

use Closure;

/**
 * An invariant is a rule that must hold true for the entity state to be valid.
 *
 * The checker can be:
 *   - A Closure(array $data): bool
 *   - A class name (string) implementing __invoke(array $data): bool
 */
final readonly class Invariant
{
    public function __construct(
        public Closure|string $checker,
        public string         $message = 'Invariant violated',
    ) {}
}
