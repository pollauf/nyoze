<?php

namespace Nyoze\CLI;

/**
 * Base class for CLI commands.
 */
abstract class Command
{
    abstract public function name(): string;

    abstract public function description(): string;

    abstract public function handle(array $args, Console $console): int;
}
