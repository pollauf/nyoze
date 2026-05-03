<?php

namespace Nyoze\Data;

use InvalidArgumentException;
use PDO;

class PdoQuery implements Query
{
    use SqlIdentifierTrait;

    private string $table   = '';
    private array  $columns = ['*'];
    private array  $where   = [];
    private array  $params  = [];
    private array  $orderBy = [];
    private array  $joins   = [];
    private ?int   $limit   = null;
    private ?int   $offset  = null;
    private int    $paramIndex = 0;

    /** Allowed JOIN types. */
    private const ALLOWED_JOIN_TYPES = ['INNER', 'LEFT', 'RIGHT', 'CROSS', 'LEFT OUTER', 'RIGHT OUTER'];

    public function __construct(
        private readonly PDO $pdo,
    ) {}

    /**
     * Validate a JOIN ON condition.
     *
     * Allows identifiers, comparison operators (=, <, >, <=, >=, !=, <>),
     * logical operators (AND, OR), parentheses and whitespace.
     *
     * @throws InvalidArgumentException
     */
    private function validateJoinCondition(string $on): void
    {
        // Strip allowed tokens and check nothing dangerous remains
        $cleaned = preg_replace(
            '/\b(AND|OR)\b/i',
            '',
            $on
        );
        // Remove identifiers (word chars, dots, backticks)
        $cleaned = preg_replace('/[a-zA-Z_][a-zA-Z0-9_.`]*/', '', $cleaned);
        // Remove comparison operators
        $cleaned = preg_replace('/[<>=!]+/', '', $cleaned);
        // Remove parentheses and whitespace
        $cleaned = preg_replace('/[\s()]+/', '', $cleaned);

        if ($cleaned !== '') {
            throw new InvalidArgumentException(
                "Invalid JOIN condition: '{$on}'. Only identifiers, comparison operators and AND/OR are allowed."
            );
        }
    }

    // =========================================================================
    // Query builder methods
    // =========================================================================

    public function select(string ...$columns): self
    {
        foreach ($columns as $col) {
            // Allow aggregate expressions like COUNT(*) as _count
            if (preg_match('/^[A-Z]+\(.*\)/i', $col)) {
                continue;
            }
            $this->validateIdentifier($col);
        }
        $this->columns = $columns;
        return $this;
    }

    public function from(string $table): self
    {
        $this->validateIdentifier($table);
        $this->table = $this->quoteIdentifier($table);
        return $this;
    }

    public function where(string $column, mixed $value, string $operator = '='): self
    {
        $this->validateIdentifier($column);
        $quoted = $this->quoteIdentifier($column);
        $key = $this->paramKey($column);
        $this->where[]     = "{$quoted} {$operator} :{$key}";
        $this->params[$key] = $value;
        return $this;
    }

    public function whereIn(string $column, array $values): self
    {
        $this->validateIdentifier($column);
        $quoted = $this->quoteIdentifier($column);
        $placeholders = [];
        foreach ($values as $val) {
            $key = $this->paramKey($column);
            $placeholders[]     = ":{$key}";
            $this->params[$key] = $val;
        }
        $this->where[] = "{$quoted} IN (" . implode(', ', $placeholders) . ")";
        return $this;
    }

    public function whereLike(string $column, string $pattern): self
    {
        $this->validateIdentifier($column);
        $quoted = $this->quoteIdentifier($column);
        $key = $this->paramKey($column);
        $this->where[]      = "{$quoted} LIKE :{$key}";
        $this->params[$key] = $pattern;
        return $this;
    }

    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $this->validateIdentifier($column);
        $dir = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
        $this->orderBy[] = $this->quoteIdentifier($column) . " {$dir}";
        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    public function offset(int $offset): self
    {
        $this->offset = $offset;
        return $this;
    }

    public function join(string $table, string $on, string $type = 'INNER'): self
    {
        $this->validateIdentifier($table);
        $this->validateJoinCondition($on);

        $joinType = strtoupper($type);
        if (!in_array($joinType, self::ALLOWED_JOIN_TYPES, true)) {
            throw new InvalidArgumentException(
                "Invalid JOIN type: '{$type}'. Allowed types: " . implode(', ', self::ALLOWED_JOIN_TYPES)
            );
        }

        $this->joins[] = "{$joinType} JOIN " . $this->quoteIdentifier($table) . " ON {$on}";
        return $this;
    }

    public function get(): array
    {
        $stmt = $this->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function first(): ?array
    {
        $this->limit = 1;
        $stmt = $this->execute();
        $row  = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function count(): int
    {
        $this->columns = ['COUNT(*) as _count'];
        $row = $this->first();
        return (int) ($row['_count'] ?? 0);
    }

    public function exists(): bool
    {
        return $this->first() !== null;
    }

    // =========================================================================
    // Internal
    // =========================================================================

    private function paramKey(string $column): string
    {
        $clean = preg_replace('/[^a-zA-Z0-9_]/', '_', $column);
        return $clean . '_' . (++$this->paramIndex);
    }

    private function buildSql(): string
    {
        $quotedCols = array_map(function (string $col): string {
            // Aggregate expressions like COUNT(*) — pass through
            if (preg_match('/^[A-Z]+\(.*\)/i', $col)) {
                return $col;
            }
            return $this->quoteIdentifier($col);
        }, $this->columns);

        $cols = implode(', ', $quotedCols);
        $sql  = "SELECT {$cols} FROM {$this->table}";

        foreach ($this->joins as $join) {
            $sql .= " {$join}";
        }

        if (!empty($this->where)) {
            $sql .= " WHERE " . implode(' AND ', $this->where);
        }

        if (!empty($this->orderBy)) {
            $sql .= " ORDER BY " . implode(', ', $this->orderBy);
        }

        if ($this->limit !== null) {
            $sql .= " LIMIT {$this->limit}";
        }

        if ($this->offset !== null) {
            $sql .= " OFFSET {$this->offset}";
        }

        return $sql;
    }

    private function execute(): \PDOStatement
    {
        $stmt = $this->pdo->prepare($this->buildSql());
        $stmt->execute($this->params);
        return $stmt;
    }
}
