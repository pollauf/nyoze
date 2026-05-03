<?php

namespace Nyoze\Domain;

use Nyoze\Data\Database\IdGeneratorInterface;
use Nyoze\Data\Repository;

/**
 * Context passed to every action.
 * Orchestrates access to input, user, repository, helpers, and cross-entity dispatch.
 */
class ActionContext
{
    public function __construct(
        private readonly ?array                 $record,
        private readonly array                  $input,
        private readonly array                  $params,
        private readonly int|string             $userId,
        private readonly ?Repository            $repository       = null,
        private readonly ?IdGeneratorInterface  $idGenerator       = null,
        private readonly ?ExecutionContext       $executionContext  = null,
    ) {}

    /** The current entity record (null for virtual or new). */
    public function entity(): ?array
    {
        return $this->record;
    }

    /** All input data. */
    public function data(): array
    {
        return $this->input;
    }

    /** Single input value. */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->input[$key] ?? $default;
    }

    /** Route parameter. */
    public function param(string $key, mixed $default = null): mixed
    {
        return $this->params[$key] ?? $default;
    }

    /** Current user ID (0 if unauthenticated). */
    public function userId(): int|string
    {
        return $this->userId;
    }

    /** Repository access — shorthand. */
    public function repo(): Repository
    {
        if ($this->repository === null) {
            throw new \RuntimeException('No repository configured.');
        }
        return $this->repository;
    }

    /** Full repository (alias). */
    public function repository(): ?Repository
    {
        return $this->repository;
    }

    /** ID generator access (Snowflake or other configured strategy). */
    public function ids(): IdGeneratorInterface
    {
        if ($this->idGenerator === null) {
            throw new \RuntimeException('No ID generator configured.');
        }
        return $this->idGenerator;
    }

    /** Raw ID generator (nullable — used internally to preserve across context rebuilds). */
    public function idGenerator(): ?IdGeneratorInterface
    {
        return $this->idGenerator;
    }

    /**
     * Access the execution context (correlation ID, call stack, depth).
     * Returns null if the action was not invoked through the pipeline.
     */
    public function execution(): ?ExecutionContext
    {
        return $this->executionContext;
    }

    // =========================================================================
    // Cross-entity dispatch
    // =========================================================================

    /**
     * Dispatch an action on another entity through the full pipeline.
     *
     * This is the ONLY correct way for an action to invoke logic on another entity.
     * The dispatched action runs through the complete pipeline:
     *   before hooks → handler → invariants → when hooks → after hooks
     *
     * Transaction is shared with the caller — all writes are atomic.
     *
     * @param string      $entity   Target entity name (e.g. 'credits', 'subscriptions')
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
        if ($this->executionContext === null) {
            throw new \RuntimeException(
                'Cannot dispatch without an ExecutionContext. '
                . 'This action was not invoked through the pipeline.'
            );
        }
        return $this->executionContext->dispatch($entity, $action, $payload, $id);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /** Current datetime string. */
    public function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    /** Hash a password. */
    public function hash(string $value): string
    {
        return password_hash($value, PASSWORD_DEFAULT);
    }

    /** Verify a password against a hash. */
    public function verifyHash(string $value, string $hash): bool
    {
        return password_verify($value, $hash);
    }
}
