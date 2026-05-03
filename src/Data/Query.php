<?php

namespace Nyoze\Data;

/**
 * Fluent query builder interface.
 */
interface Query
{
    public function select(string ...$columns): self;
    public function from(string $table): self;
    public function where(string $column, mixed $value, string $operator = '='): self;
    public function whereIn(string $column, array $values): self;
    public function whereLike(string $column, string $pattern): self;
    public function orderBy(string $column, string $direction = 'ASC'): self;
    public function limit(int $limit): self;
    public function offset(int $offset): self;
    public function join(string $table, string $on, string $type = 'INNER'): self;
    public function get(): array;
    public function first(): ?array;
    public function count(): int;
    public function exists(): bool;
}
