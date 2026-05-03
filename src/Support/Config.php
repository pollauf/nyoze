<?php

namespace Nyoze\Support;

/**
 * Simple configuration loader — reads .env files and provides typed access.
 */
class Config
{
    private array $data = [];

    public function __construct(array $data = [])
    {
        $this->data = $data;
    }

    /**
     * Load a .env file into the config.
     */
    public static function fromEnv(string $path): self
    {
        $config = new self();

        if (!file_exists($path)) {
            return $config;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;

            $pos = strpos($line, '=');
            if ($pos === false) continue;

            $key   = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));

            // Remove surrounding quotes
            if (preg_match('/^"(.*)"$/', $value, $m)) $value = $m[1];
            if (preg_match("/^'(.*)'$/", $value, $m)) $value = $m[1];

            $config->data[$key] = $value;
            $_ENV[$key]         = $value;
        }

        return $config;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $env = getenv($key);
        if ($env !== false) {
            return $env;
        }

        return $this->data[$key] ?? $_ENV[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function all(): array
    {
        return $this->data;
    }
}
