<?php

namespace Nyoze\Http;

use Throwable;
use Nyoze\Core\App;
use Nyoze\Data\Database\IdStrategy;
use Nyoze\Domain\ActionContext;
use Nyoze\Domain\Entity;
use Nyoze\Domain\ExecutionContext;
use Nyoze\Domain\Result;
use Nyoze\Pipeline\Pipeline;

/**
 * HTTP Engine — generates REST routes from entity capabilities.
 *
 * All routes are derived from ->can() declarations:
 *
 * Entity actions (via EntityAction):
 *   GET    /api/{entity}           → list   (if declared)
 *   POST   /api/{entity}           → create (if declared)
 *   GET    /api/{entity}/:id       → get    (if declared)
 *   PUT    /api/{entity}/:id       → update (if declared)
 *   DELETE /api/{entity}/:id       → delete (if declared)
 *
 * Custom actions:
 *   {M}    /api/{entity}/:id/{action}  → custom action (regular entity)
 *   {M}    /api/{entity}/{action}      → custom action (virtual entity)
 *
 * No implicit CRUD. If a capability is not declared, the route does not exist.
 */
class HttpEngine
{
    /** @var Middleware[] */
    private array $middleware = [];

    /** @var callable|null */
    private $authResolver = null;

    private string $prefix = '/api';

    public function __construct(
        private readonly App $app,
    ) {}

    public function prefix(string $prefix): self
    {
        $this->prefix = '/' . trim($prefix, '/');
        return $this;
    }

    public function addMiddleware(Middleware $middleware): self
    {
        $this->middleware[] = $middleware;
        return $this;
    }

    /**
     * Set a custom auth resolver: fn(Request): int (returns user ID, 0 if unauthenticated).
     */
    public function setAuthResolver(callable $resolver): self
    {
        $this->authResolver = $resolver;
        return $this;
    }

    public function dispatch(Request $request): Response
    {
        try {
            $handler = $this->buildMiddlewarePipeline($request);
            return $handler($request);
        } catch (Throwable $e) {
            error_log('[Nyoze] Dispatch error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            return Response::fromResult(
                Result::fail($e->getMessage(), 500)
            );
        }
    }

    /**
     * Build the middleware pipeline as a chain of closures.
     */
    private function buildMiddlewarePipeline(Request $request): \Closure
    {
        $handler = function (Request $request): Response {
            return Response::fromResult($this->resolve($request));
        };

        foreach (array_reverse($this->middleware) as $middleware) {
            $handler = function (Request $request) use ($middleware, $handler): Response {
                return $middleware->handle($request, $handler);
            };
        }

        return $handler;
    }

    private function resolve(Request $request): Result
    {
        $method = $request->method();
        $uri    = $request->uri();

        foreach ($this->app->registry()->all() as $entity) {
            $base = $this->prefix . '/' . $entity->name;

            // Try entity capabilities (CRUD) — only if declared
            if (!$entity->isVirtual()) {
                $result = $this->tryEntityActions($method, $uri, $request, $entity, $base);
                if ($result !== null) return $result;
            }

            // Try custom actions
            $result = $this->tryCustomActions($method, $uri, $request, $entity, $base);
            if ($result !== null) return $result;
        }

        return Result::notFound('Route not found');
    }

    // =========================================================================
    // Entity Actions (CRUD via capabilities)
    // =========================================================================

    /**
     * Handle built-in entity actions declared via EntityAction.
     * Only processes actions that were explicitly declared with ->can().
     */
    private function tryEntityActions(string $method, string $uri, Request $request, Entity $entity, string $base): ?Result
    {
        $repo = $this->app->repository();
        if ($repo === null) return null;

        // LIST: GET /api/{entity}
        if ($method === 'GET' && $uri === $base && $entity->hasCapability('list')) {
            $userId = $this->resolveAuth($request, $entity);
            if ($userId instanceof Result) return $userId;
            $conditions = [];
            if ($entity->getOwnerField()) {
                $conditions[$entity->getOwnerField()] = $userId;
            }
            foreach ($request->queryAll() as $key => $value) {
                if ($entity->getField($key) !== null) {
                    $conditions[$key] = $value;
                }
            }
            $data = $repo->all($entity->name, $conditions);
            return Result::ok($data);
        }

        // CREATE: POST /api/{entity}
        if ($method === 'POST' && $uri === $base && $entity->hasCapability('create')) {
            $userId   = $this->resolveAuth($request, $entity);
            if ($userId instanceof Result) return $userId;
            $ctx      = $this->buildContext(null, $request, $userId);
            $pipeline = new Pipeline($entity);

            $data = $this->filterEntityFields($entity, $request->all());
            if ($entity->getOwnerField()) {
                $data[$entity->getOwnerField()] = $userId;
            }

            $hookResult = $pipeline->runBeforeHooks('create', $data, $ctx);
            if ($hookResult instanceof Result) return $hookResult;
            $data = $hookResult;

            $invResult = $pipeline->checkAllInvariants($data);
            if ($invResult !== null) return $invResult;

            $repo->beginTransaction();
            try {
                $saved = $repo->save($entity->name, $data);
                $pipeline->runAfterHooksForCrud('create', $saved, $ctx);
                $repo->commit();
            } catch (\Throwable $e) {
                $repo->rollBack();
                throw $e;
            }
            return Result::created($saved);
        }

        // GET: GET /api/{entity}/:id
        $params = $this->matchRoute('GET', $method, $base . '/:id', $uri);
        if ($params !== null && $entity->hasCapability('get')) {
            $userId = $this->resolveAuth($request, $entity);
            if ($userId instanceof Result) return $userId;
            $id     = $params['id'];
            $record = $repo->find($entity->name, $id);

            if (!$record) return Result::notFound("{$entity->name} not found");
            if ($entity->getOwnerField() && (string) ($record[$entity->getOwnerField()] ?? '') !== (string) $userId) {
                return Result::notFound("{$entity->name} not found");
            }

            return Result::ok($record);
        }

        // UPDATE: PUT /api/{entity}/:id
        $params = $this->matchRoute('PUT', $method, $base . '/:id', $uri);
        if ($params !== null && $entity->hasCapability('update')) {
            $userId = $this->resolveAuth($request, $entity);
            if ($userId instanceof Result) return $userId;
            $id     = $params['id'];
            $record = $repo->find($entity->name, $id);

            if (!$record) return Result::notFound("{$entity->name} not found");
            if ($entity->getOwnerField() && (string) ($record[$entity->getOwnerField()] ?? '') !== (string) $userId) {
                return Result::notFound("{$entity->name} not found");
            }

            $ctx      = $this->buildContext($record, $request, $userId);
            $pipeline = new Pipeline($entity);
            $data     = $this->filterEntityFields($entity, array_filter($request->all(), fn($v) => $v !== null));
            $data['id'] = $id;

            $hookResult = $pipeline->runBeforeHooks('update', $data, $ctx);
            if ($hookResult instanceof Result) return $hookResult;
            $data = $hookResult;

            $invResult = $pipeline->checkAllInvariants(array_merge($record, $data));
            if ($invResult !== null) return $invResult;

            $repo->beginTransaction();
            try {
                $saved = $repo->save($entity->name, $data);
                $pipeline->runAfterHooksForCrud('update', $saved, $ctx);
                $repo->commit();
            } catch (\Throwable $e) {
                $repo->rollBack();
                throw $e;
            }
            return Result::ok($saved);
        }

        // DELETE: DELETE /api/{entity}/:id
        $params = $this->matchRoute('DELETE', $method, $base . '/:id', $uri);
        if ($params !== null && $entity->hasCapability('delete')) {
            $userId = $this->resolveAuth($request, $entity);
            if ($userId instanceof Result) return $userId;
            $id     = $params['id'];
            $record = $repo->find($entity->name, $id);

            if (!$record) return Result::notFound("{$entity->name} not found");
            if ($entity->getOwnerField() && (string) ($record[$entity->getOwnerField()] ?? '') !== (string) $userId) {
                return Result::notFound("{$entity->name} not found");
            }

            $ctx      = $this->buildContext($record, $request, $userId);
            $pipeline = new Pipeline($entity);

            $hookResult = $pipeline->runBeforeHooks('delete', ['id' => $id], $ctx);
            if ($hookResult instanceof Result) return $hookResult;

            $repo->beginTransaction();
            try {
                $repo->delete($entity->name, $id);
                $pipeline->runAfterHooksForCrud('delete', ['id' => $id], $ctx);
                $repo->commit();
            } catch (\Throwable $e) {
                $repo->rollBack();
                throw $e;
            }
            return Result::noContent();
        }

        return null;
    }

    // =========================================================================
    // Custom Actions
    // =========================================================================

    private function tryCustomActions(string $method, string $uri, Request $request, Entity $entity, string $base): ?Result
    {
        foreach ($entity->getActions() as $action) {
            // Skip entity actions — they are handled by tryEntityActions
            if ($action->type === 'entity') continue;

            $pattern = $entity->isVirtual()
                ? $base . '/' . $action->name
                : $base . '/:id/' . $action->name;

            $params = $this->matchRoute($action->httpMethod, $method, $pattern, $uri);
            if ($params === null) continue;

            $request->setParams($params);
            $userId = $this->resolveAuth($request, $entity);
            if ($userId instanceof Result) return $userId;
            $repo   = $this->app->repository();

            $record = null;
            $id     = $params['id'] ?? '0';

            if (!$entity->isVirtual() && $id !== '0' && $id !== '' && $repo) {
                $record = $repo->find($entity->name, $id);
                if (!$record) return Result::notFound("{$entity->name} not found");
                if ($entity->getOwnerField() && (string) ($record[$entity->getOwnerField()] ?? '') !== (string) $userId) {
                    return Result::notFound("{$entity->name} not found");
                }
            }

            $ctx      = $this->buildContext($record, $request, $userId);
            $pipeline = new Pipeline($entity);
            return $pipeline->run($action->name, $ctx);
        }

        return null;
    }

    // =========================================================================
    // Auth
    // =========================================================================

    private function resolveAuth(Request $request, Entity $entity): string|Result
    {
        if ($this->authResolver) {
            $userId = (string) ($this->authResolver)($request);
        } else {
            $userId = $this->defaultAuthResolver($request);
        }

        if ($entity->needsAuth() && (!$userId || $userId === '0')) {
            return Result::unauthorized();
        }

        return $userId ?: '0';
    }

    private function defaultAuthResolver(Request $request): string
    {
        return '0';
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function buildContext(?array $record, Request $request, string|int $userId): ActionContext
    {
        $dbConfig = $this->app->database();
        $idGenerator = null;

        if ($dbConfig->getIdStrategy() === IdStrategy::Snowflake) {
            $idGenerator = $dbConfig->getSnowflakeGenerator();
        }

        $repo = $this->app->repository();

        // Create an ExecutionContext so actions can dispatch to other entities
        $execCtx = new ExecutionContext(
            correlationId: bin2hex(random_bytes(8)),
            registry:      $this->app->registry(),
            repository:    $repo,
            idGenerator:   $idGenerator,
            userId:        $userId,
        );

        return new ActionContext(
            record:           $record,
            input:            $request->all(),
            params:           ['id' => $request->param('id')],
            userId:           $userId,
            repository:       $repo,
            idGenerator:      $idGenerator,
            executionContext:  $execCtx,
        );
    }

    private function matchRoute(string $routeMethod, string $requestMethod, string $pattern, string $uri): ?array
    {
        if ($routeMethod !== $requestMethod) return null;

        $regex = preg_replace('/:([a-z_]+)/', '(?P<$1>[^/]+)', $pattern);
        $regex = '#^' . $regex . '$#';

        if (!preg_match($regex, $uri, $matches)) return null;

        $params = [];
        foreach ($matches as $key => $value) {
            if (is_string($key)) {
                $params[$key] = $value;
            }
        }
        return $params;
    }

    private function filterEntityFields(Entity $entity, array $data): array
    {
        $fields = $entity->getFields();
        if (empty($fields)) {
            return $data;
        }

        $allowed = ['id'];
        foreach ($fields as $field) {
            $allowed[] = $field->name;
        }

        return array_intersect_key($data, array_flip($allowed));
    }
}
