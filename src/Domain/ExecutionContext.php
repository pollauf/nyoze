<?php

namespace Nyoze\Domain;

use Closure;
use Nyoze\Data\Database\IdGeneratorInterface;
use Nyoze\Data\Repository;
use Nyoze\Domain\Exceptions\MaxDepthExceededException;
use Nyoze\Domain\Exceptions\RecursiveDispatchException;
use Nyoze\Pipeline\Pipeline;

/**
 * ExecutionContext — tracks the full lifecycle of a dispatch chain.
 *
 * Created once per root request. Shared across all nested dispatches.
 * Carries correlation ID, call stack, queued hooks, and dispatch logic.
 */
class ExecutionContext
{
    private array $callStack = [];
    private int $depth = 0;
    private string $rootEntity = '';
    private string $rootAction = '';

    /** @var list<Closure> */
    private array $queuedWhenHooks = [];

    /** @var list<Closure> */
    private array $queuedAfterHooks = [];

    private const MAX_DEPTH = 10;

    public function __construct(
        private readonly string                $correlationId,
        private readonly EntityRegistry        $registry,
        private readonly ?Repository           $repository,
        private readonly ?IdGeneratorInterface  $idGenerator,
        private readonly int|string            $userId,
    ) {}

    // =========================================================================
    // Call stack management
    // =========================================================================

    /**
     * Push a new frame onto the call stack before executing an action.
     *
     * @throws MaxDepthExceededException
     * @throws RecursiveDispatchException
     */
    public function pushCall(string $entity, string $action): void
    {
        if ($this->depth >= self::MAX_DEPTH) {
            throw new MaxDepthExceededException(
                self::MAX_DEPTH,
                $this->callStack,
                $this->correlationId,
            );
        }

        // Recursion detection: same entity+action already in the stack
        foreach ($this->callStack as $frame) {
            if ($frame['entity'] === $entity && $frame['action'] === $action) {
                throw new RecursiveDispatchException(
                    $entity,
                    $action,
                    $this->callStack,
                    $this->correlationId,
                );
            }
        }

        if ($this->depth === 0) {
            $this->rootEntity = $entity;
            $this->rootAction = $action;
        }

        $this->callStack[] = [
            'entity' => $entity,
            'action' => $action,
            'depth'  => $this->depth,
        ];
        $this->depth++;
    }

    /**
     * Pop the last frame after an action completes.
     */
    public function popCall(): void
    {
        array_pop($this->callStack);
        $this->depth--;
    }

    /**
     * Whether the current execution is the root (outermost) action.
     * depth === 1 means we are inside the root action's handler.
     */
    public function isRoot(): bool
    {
        return $this->depth <= 1;
    }

    // =========================================================================
    // Accessors
    // =========================================================================

    public function correlationId(): string { return $this->correlationId; }
    public function callStack(): array { return $this->callStack; }
    public function depth(): int { return $this->depth; }
    public function rootEntity(): string { return $this->rootEntity; }
    public function rootAction(): string { return $this->rootAction; }

    public function currentEntity(): ?string
    {
        $last = end($this->callStack);
        return $last !== false ? $last['entity'] : null;
    }

    public function currentAction(): ?string
    {
        $last = end($this->callStack);
        return $last !== false ? $last['action'] : null;
    }

    public function parentEntity(): ?string
    {
        $count = count($this->callStack);
        return $count >= 2 ? $this->callStack[$count - 2]['entity'] : null;
    }

    public function parentAction(): ?string
    {
        $count = count($this->callStack);
        return $count >= 2 ? $this->callStack[$count - 2]['action'] : null;
    }

    // =========================================================================
    // Hook queuing (deferred until root commit)
    // =========================================================================

    public function queueWhenHook(Closure $hook): void
    {
        $this->queuedWhenHooks[] = $hook;
    }

    public function queueAfterHook(Closure $hook): void
    {
        $this->queuedAfterHooks[] = $hook;
    }

    /**
     * Execute all queued when + after hooks. Called only after root commit.
     */
    public function flushQueuedHooks(): void
    {
        // When hooks first (in dispatch order), then after hooks
        foreach ($this->queuedWhenHooks as $hook) {
            $hook();
        }
        foreach ($this->queuedAfterHooks as $hook) {
            $hook();
        }
        $this->queuedWhenHooks  = [];
        $this->queuedAfterHooks = [];
    }

    /**
     * Discard all queued hooks (called on rollback).
     */
    public function discardQueuedHooks(): void
    {
        $this->queuedWhenHooks  = [];
        $this->queuedAfterHooks = [];
    }

    // =========================================================================
    // Dispatch — the core cross-entity mechanism
    // =========================================================================

    /**
     * Dispatch an action on another entity through the full pipeline.
     *
     * @param string      $entity   Target entity name
     * @param string      $action   Action name on that entity
     * @param array       $payload  Input data for the action
     * @param string|null $id       Optional record ID (for non-virtual entities)
     */
    public function dispatch(
        string  $entity,
        string  $action,
        array   $payload = [],
        ?string $id = null,
    ): Result {
        $entityDef = $this->registry->get($entity);

        $record = null;
        if ($id !== null && $this->repository !== null) {
            $record = $this->repository->find($entity, $id);
            if (!$record) {
                return Result::notFound("{$entity} not found");
            }
        }

        $childCtx = new ActionContext(
            record:           $record,
            input:            $payload,
            params:           ['id' => $id ?? '0'],
            userId:           $this->userId,
            repository:       $this->repository,
            idGenerator:      $this->idGenerator,
            executionContext:  $this,
        );

        $pipeline = new Pipeline($entityDef);
        return $pipeline->run($action, $childCtx);
    }

    // =========================================================================
    // Formatting
    // =========================================================================

    public function formatCallStack(): string
    {
        return implode(' → ', array_map(
            fn(array $f) => "{$f['entity']}.{$f['action']}",
            $this->callStack,
        ));
    }
}
