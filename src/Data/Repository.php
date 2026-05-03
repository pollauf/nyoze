<?php

namespace Nyoze\Data;

/**
 * Repository interface — the contract for data persistence.
 *
 * Nyoze does not assume any specific database. Implement this interface
 * for PDO, MongoDB, in-memory, file-based, or any other storage.
 */
interface Repository
{
    public function find(string $table, int|string $id): ?array;

    public function findBy(string $table, array $conditions): ?array;

    public function all(string $table, array $conditions = [], ?string $orderBy = null, ?string $direction = 'ASC', ?int $limit = null, ?int $offset = null): array;

    public function save(string $table, array $data): array;

    public function update(string $table, array $data, array $conditions): int;

    public function delete(string $table, int|string $id): bool;

    public function query(): Query;

    // =========================================================================
    // Transactions
    // =========================================================================

    public function beginTransaction(): void;

    public function commit(): void;

    public function rollBack(): void;

    public function inTransaction(): bool;

    /**
     * Execute a callback within a transaction.
     * Commits on success, rolls back on any Throwable.
     * Nested calls are safe — only the outermost transaction controls commit/rollback.
     */
    public function transaction(callable $callback): mixed;
}
