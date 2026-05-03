<?php

namespace Nyoze\Domain;

/**
 * Rule — a named invariant check.
 * Implement this instead of inline closures for clarity.
 */
abstract class Rule
{
    abstract public function check(array $data): bool;

    abstract public function message(): string;
}
