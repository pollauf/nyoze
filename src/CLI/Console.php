<?php

namespace Nyoze\CLI;

use Nyoze\Core\App;
use Nyoze\Data\Schema;

/**
 * Minimal CLI console for Nyoze applications.
 */
class Console
{
    /** @var Command[] */
    private array $commands = [];

    public function __construct(
        private readonly App $app,
    ) {
        $this->registerBuiltIn();
    }

    public function register(Command $command): self
    {
        $this->commands[$command->name()] = $command;
        return $this;
    }

    public function run(array $argv): int
    {
        $name = $argv[1] ?? 'help';
        $args = array_slice($argv, 2);

        if ($name === 'help' || $name === '--help') {
            return $this->showHelp();
        }

        if (!isset($this->commands[$name])) {
            $this->error("Unknown command: {$name}");
            return 1;
        }

        return $this->commands[$name]->handle($args, $this);
    }

    public function app(): App
    {
        return $this->app;
    }

    public function info(string $message): void
    {
        echo "\033[32m{$message}\033[0m" . PHP_EOL;
    }

    public function error(string $message): void
    {
        echo "\033[31m{$message}\033[0m" . PHP_EOL;
    }

    public function line(string $message = ''): void
    {
        echo $message . PHP_EOL;
    }

    private function showHelp(): int
    {
        $this->line('Nyoze CLI');
        $this->line('');
        $this->line('Available commands:');
        foreach ($this->commands as $cmd) {
            $this->line("  {$cmd->name()}  — {$cmd->description()}");
        }
        return 0;
    }

    private function registerBuiltIn(): void
    {
        $this->register(new class extends Command {
            public function name(): string { return 'entities'; }
            public function description(): string { return 'List all registered entities'; }
            public function handle(array $args, Console $console): int
            {
                $entities = $console->app()->registry()->all();
                if (empty($entities)) {
                    $console->info('No entities registered.');
                    return 0;
                }
                foreach ($entities as $entity) {
                    $type    = $entity->isVirtual() ? '(virtual)' : '';
                    $auth    = $entity->needsAuth() ? '[auth]' : '';
                    $fields  = count($entity->getFields());
                    $actions = count($entity->getActions());
                    $console->line("  {$entity->name} {$type} {$auth} — {$fields} fields, {$actions} actions");
                }
                return 0;
            }
        });

        $this->register(new class extends Command {
            public function name(): string { return 'schema'; }
            public function description(): string { return 'Generate SQL schema for all entities'; }
            public function handle(array $args, Console $console): int
            {
                $entities = $console->app()->registry()->all();
                $sql = Schema::createAll($entities);
                if (empty($sql)) {
                    $console->info('No tables to generate (all entities are virtual).');
                    return 0;
                }
                $console->line($sql);
                return 0;
            }
        });

        $this->register(new Commands\MakeMigrationCommand());
        $this->register(new Commands\SchemaDumpCommand());
        $this->register(new Commands\MigrateCommand());
        $this->register(new Commands\MigrateRollbackCommand());
    }
}
