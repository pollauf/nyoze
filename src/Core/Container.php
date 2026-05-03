<?php

namespace Nyoze\Core;

use Closure;
use RuntimeException;

class Container
{
    /** @var array<string, Closure> */
    private array $bindings = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    public function bind(string $key, Closure $factory): void
    {
        $this->bindings[$key] = $factory;
    }

    public function singleton(string $key, Closure $factory): void
    {
        $this->bindings[$key] = function () use ($key, $factory) {
            if (!isset($this->instances[$key])) {
                $this->instances[$key] = $factory($this);
            }
            return $this->instances[$key];
        };
    }

    public function set(string $key, mixed $value): void
    {
        $this->instances[$key] = $value;
    }

    public function get(string $key): mixed
    {
        if (isset($this->instances[$key])) {
            return $this->instances[$key];
        }

        if (isset($this->bindings[$key])) {
            return ($this->bindings[$key])($this);
        }

        throw new RuntimeException("Nothing bound for '{$key}' in container.");
    }

    public function has(string $key): bool
    {
        return isset($this->instances[$key]) || isset($this->bindings[$key]);
    }

    public function make(string $class): object
    {
        if ($this->has($class)) {
            return $this->get($class);
        }

        if (!class_exists($class)) {
            throw new RuntimeException("Class '{$class}' does not exist.");
        }

        return new $class();
    }
}
