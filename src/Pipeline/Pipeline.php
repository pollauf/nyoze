<?php

namespace Nyoze\Pipeline;

use Closure;
use RuntimeException;
use Nyoze\Data\Repository;
use Nyoze\Domain\Action;
use Nyoze\Domain\ActionContext;
use Nyoze\Domain\Entity;
use Nyoze\Domain\ExecutionContext;
use Nyoze\Domain\Invariant;
use Nyoze\Domain\Result;
use Nyoze\Domain\Rule;

/**
 * The Pipeline executes an action through the full lifecycle:
 *
 *   before hooks → BEGIN → handler → invariants → COMMIT → when hooks → after hooks
 *
 * When running as a nested dispatch (depth > 0):
 *   - Transaction is shared (no BEGIN/COMMIT at this level)
 *   - When hooks and after hooks are QUEUED, not executed immediately
 *   - They flush only after the root transaction commits
 *
 * This is the engine. It stays hidden from the developer.
 */
class Pipeline
{
    public function __construct(
        private readonly Entity $entity,
    ) {}

    /**
     * Run a named action through the full pipeline.
     *
     * Root flow:   before → BEGIN → handler → invariants → COMMIT → flush queued hooks → when → after
     * Nested flow: before → handler → invariants → queue when/after hooks → return Result
     */
    public function run(string $actionName, ActionContext $ctx): Result
    {
        $action = $this->entity->getAction($actionName);

        if ($action === null) {
            return Result::fail("Action '{$actionName}' not found on entity '{$this->entity->name}'.", 404);
        }

        $execCtx = $ctx->execution();
        $isRoot  = $execCtx === null || $execCtx->depth() === 0;

        // Push call onto the execution context stack
        if ($execCtx !== null) {
            $execCtx->pushCall($this->entity->name, $actionName);
        }

        try {
            $result = $this->executeAction($action, $actionName, $ctx, $execCtx, $isRoot);
        } finally {
            if ($execCtx !== null) {
                $execCtx->popCall();
            }
        }

        return $result;
    }

    /**
     * Core execution logic, separated for clean try/finally around pushCall/popCall.
     */
    private function executeAction(
        Action            $action,
        string            $actionName,
        ActionContext      $ctx,
        ?ExecutionContext  $execCtx,
        bool              $isRoot,
    ): Result {
        // --- Before hooks ---
        $record = $ctx->entity();
        foreach ($this->entity->getBeforeHooks($actionName) as $hook) {
            $hookResult = $this->callHook($hook, $record, $ctx);
            if ($hookResult instanceof Result && !$hookResult->success) {
                return $hookResult;
            }
            if (is_array($hookResult)) {
                $record = $hookResult;
            }
        }

        // Rebuild context if record was modified by before hooks
        if ($record !== $ctx->entity()) {
            $ctx = new ActionContext(
                $record,
                $ctx->data(),
                ['id' => $ctx->param('id')],
                $ctx->userId(),
                $ctx->repository(),
                $ctx->idGenerator(),
                $execCtx,
            );
        }

        // --- Transaction management ---
        $repo           = $ctx->repository();
        $useTransaction = $repo !== null;

        // Only the root action controls the real transaction
        if ($useTransaction && $isRoot) {
            $repo->beginTransaction();
        }

        try {
            $result = $this->callAction($action, $ctx);

            // Only check invariants if handler succeeded
            if ($result->success) {
                $dataToCheck = is_array($result->data) ? $result->data : ($record ?? []);
                if (!empty($dataToCheck)) {
                    foreach ($this->entity->getInvariants() as $invariant) {
                        if (!$this->checkInvariant($invariant, $dataToCheck)) {
                            if ($useTransaction && $isRoot) {
                                $repo->rollBack();
                                $execCtx?->discardQueuedHooks();
                            }
                            return Result::invalid($invariant->message);
                        }
                    }
                }
            }

            // Root: commit or rollback
            if ($useTransaction && $isRoot) {
                if ($result->success) {
                    $repo->commit();
                } else {
                    $repo->rollBack();
                    $execCtx?->discardQueuedHooks();
                }
            }
        } catch (\Throwable $e) {
            if ($useTransaction && $isRoot) {
                $repo->rollBack();
                $execCtx?->discardQueuedHooks();
            }
            throw $e;
        }

        // --- Post-commit: when hooks and after hooks ---
        if ($result->success) {
            if ($isRoot) {
                // Root: flush all queued hooks from nested dispatches first
                $execCtx?->flushQueuedHooks();

                // Then run this action's own when/after hooks immediately
                $this->runWhenHooks($result, $ctx);
                $this->runAfterHooks($actionName, $result, $ctx);
            } else {
                // Nested: queue when/after hooks for later
                $this->queueWhenHooks($result, $ctx, $execCtx);
                $this->queueAfterHooks($actionName, $result, $ctx, $execCtx);
            }
        }

        return $result;
    }

    // =========================================================================
    // When / After hook helpers
    // =========================================================================

    private function runWhenHooks(Result $result, ActionContext $ctx): void
    {
        if (!is_array($result->data)) return;

        $previous = $ctx->entity();

        foreach ($result->data as $field => $value) {
            // when — fires every time the field equals the value
            foreach ($this->entity->getWhenHooks($field) as $whenDef) {
                if ($whenDef['value'] === $value) {
                    $this->callHook($whenDef['handler'], $result->data, $ctx);
                }
            }

            // whenChanged — fires only if the value actually changed
            foreach ($this->entity->getWhenChangedHooks($field) as $whenDef) {
                if ($whenDef['value'] === $value) {
                    $oldValue = $previous[$field] ?? null;
                    if ($oldValue !== $value) {
                        $this->callHook($whenDef['handler'], $result->data, $ctx);
                    }
                }
            }
        }
    }

    private function runAfterHooks(string $actionName, Result $result, ActionContext $ctx): void
    {
        foreach ($this->entity->getAfterHooks($actionName) as $hook) {
            $this->callHook($hook, $result->data, $ctx);
        }
    }

    private function queueWhenHooks(Result $result, ActionContext $ctx, ?ExecutionContext $execCtx): void
    {
        if ($execCtx === null || !is_array($result->data)) return;

        $previous = $ctx->entity();

        foreach ($result->data as $field => $value) {
            // when — fires every time the field equals the value
            foreach ($this->entity->getWhenHooks($field) as $whenDef) {
                if ($whenDef['value'] === $value) {
                    $handler    = $whenDef['handler'];
                    $resultData = $result->data;
                    $execCtx->queueWhenHook(function () use ($handler, $resultData, $ctx) {
                        $this->callHook($handler, $resultData, $ctx);
                    });
                }
            }

            // whenChanged — fires only if the value actually changed
            foreach ($this->entity->getWhenChangedHooks($field) as $whenDef) {
                if ($whenDef['value'] === $value) {
                    $oldValue = $previous[$field] ?? null;
                    if ($oldValue !== $value) {
                        $handler    = $whenDef['handler'];
                        $resultData = $result->data;
                        $execCtx->queueWhenHook(function () use ($handler, $resultData, $ctx) {
                            $this->callHook($handler, $resultData, $ctx);
                        });
                    }
                }
            }
        }
    }

    private function queueAfterHooks(string $actionName, Result $result, ActionContext $ctx, ?ExecutionContext $execCtx): void
    {
        if ($execCtx === null) return;

        foreach ($this->entity->getAfterHooks($actionName) as $hook) {
            $resultData = $result->data;
            $execCtx->queueAfterHook(function () use ($hook, $resultData, $ctx) {
                $this->callHook($hook, $resultData, $ctx);
            });
        }
    }

    // =========================================================================
    // Public helpers for HttpEngine (CRUD operations)
    // =========================================================================

    /**
     * Run CRUD before hooks + invariants (used by HttpEngine for auto CRUD).
     * Returns a Result on failure, or the (possibly modified) data array on success.
     */
    public function runBeforeHooks(string $event, array $data, ActionContext $ctx): Result|array
    {
        foreach ($this->entity->getBeforeHooks($event) as $hook) {
            $hookResult = $this->callHook($hook, $data, $ctx);
            if ($hookResult instanceof Result && !$hookResult->success) {
                return $hookResult;
            }
            if (is_array($hookResult)) {
                $data = $hookResult;
            }
        }
        return $data;
    }

    public function runAfterHooksForCrud(string $event, mixed $data, ActionContext $ctx): void
    {
        foreach ($this->entity->getAfterHooks($event) as $hook) {
            $this->callHook($hook, $data, $ctx);
        }
    }

    public function checkAllInvariants(array $data): ?Result
    {
        foreach ($this->entity->getInvariants() as $invariant) {
            if (!$this->checkInvariant($invariant, $data)) {
                return Result::invalid($invariant->message);
            }
        }
        return null;
    }

    // =========================================================================
    // Internal
    // =========================================================================

    private function callAction(Action $action, ActionContext $ctx): Result
    {
        $handler = $action->handler;

        if ($handler === null) {
            return Result::ok();
        }

        if ($handler instanceof Closure) {
            $raw = $handler($ctx);
            return $this->normalizeResult($raw);
        }

        if (is_string($handler) && class_exists($handler)) {
            $instance = new $handler();
            $raw = $instance($ctx);
            return $this->normalizeResult($raw);
        }

        throw new RuntimeException("Invalid handler for action '{$action->name}'.");
    }

    private function callHook(Closure|string $hook, mixed $data, ActionContext $ctx): mixed
    {
        if ($hook instanceof Closure) {
            return $hook($data, $ctx);
        }

        if (is_string($hook) && class_exists($hook)) {
            $instance = new $hook();
            return $instance($data, $ctx);
        }

        throw new RuntimeException("Invalid hook handler.");
    }

    private function checkInvariant(Invariant $invariant, array $data): bool
    {
        $checker = $invariant->checker;

        if ($checker instanceof Closure) {
            return $checker($data) !== false;
        }

        if (is_string($checker) && class_exists($checker)) {
            $instance = new $checker();

            if ($instance instanceof Rule) {
                return $instance->check($data);
            }

            return $instance($data) !== false;
        }

        throw new RuntimeException("Invalid invariant checker.");
    }

    private function normalizeResult(mixed $raw): Result
    {
        if ($raw instanceof Result) {
            return $raw;
        }

        if (is_array($raw)) {
            return Result::ok($raw);
        }

        if ($raw === null) {
            return Result::noContent();
        }

        return Result::ok($raw);
    }
}
