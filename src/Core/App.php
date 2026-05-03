<?php

namespace Nyoze\Core;

use Nyoze\Data\Database\DatabaseConfig;
use Nyoze\Domain\Entity;
use Nyoze\Domain\EntityDefinition;
use Nyoze\Domain\EntityRegistry;
use Nyoze\Data\Repository;
use Nyoze\Http\HttpEngine;
use Nyoze\Http\Request;

class App
{
    private EntityRegistry $registry;
    private Container $container;
    private ?Repository $repository = null;
    private ?DatabaseConfig $databaseConfig = null;

    /** @var callable|null */
    private $authResolver = null;

    /** @var \Nyoze\Http\Middleware[] */
    private array $middleware = [];

    public function __construct()
    {
        $this->registry  = new EntityRegistry();
        $this->container = new Container();
    }

    /**
     * Load entity definitions from class names.
     *
     *   $app->load([
     *       UsersEntity::class,
     *       ProjectsEntity::class,
     *   ]);
     *
     * @param string[] $definitions  Array of EntityDefinition class names
     */
    public function load(array $definitions): void
    {
        foreach ($definitions as $class) {
            /** @var EntityDefinition $def */
            $def    = new $class();
            $entity = $this->entity($def->name());
            $def->define($entity);
        }
    }

    /**
     * Get or create an entity by name.
     */
    public function entity(string $name): Entity
    {
        if ($this->registry->has($name)) {
            return $this->registry->get($name);
        }

        $entity = new Entity($name);
        $this->registry->register($entity);
        return $entity;
    }

    public function registry(): EntityRegistry
    {
        return $this->registry;
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function database(): DatabaseConfig
    {
        if ($this->databaseConfig === null) {
            $this->databaseConfig = new DatabaseConfig();
        }
        return $this->databaseConfig;
    }

    public function useRepository(Repository $repository): self
    {
        $this->repository = $repository;
        return $this;
    }

    public function repository(): ?Repository
    {
        return $this->repository;
    }

    /**
     * Set a custom auth resolver: fn(Request): string (returns user ID, '0' if unauthenticated).
     */
    public function setAuthResolver(callable $resolver): self
    {
        $this->authResolver = $resolver;
        return $this;
    }

    /**
     * Register a middleware to be applied to all HTTP requests.
     */
    public function addMiddleware(\Nyoze\Http\Middleware $middleware): self
    {
        $this->middleware[] = $middleware;
        return $this;
    }

    /**
     * Run as HTTP application.
     */
    public function run(): void
    {
        $engine = new HttpEngine($this);

        if ($this->authResolver !== null) {
            $engine->setAuthResolver($this->authResolver);
        }

        foreach ($this->middleware as $mw) {
            $engine->addMiddleware($mw);
        }

        $response = $engine->dispatch(Request::capture());
        $response->send();
    }
}
