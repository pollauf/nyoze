<?php

namespace Nyoze\Data;

use PDO;
use Nyoze\Data\Database\IdGeneratorInterface;

/**
 * PDO-based repository implementation — the default for SQL databases.
 */
class PdoRepository implements Repository
{
    use SqlIdentifierTrait;

    private int $transactionDepth = 0;

    public function __construct(
        private readonly PDO $pdo,
        private readonly ?IdGeneratorInterface $idGenerator = null,
    ) {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function find(string $table, int|string $id): ?array
    {
        $t = $this->quoteTable($table);
        $stmt = $this->pdo->prepare("SELECT * FROM {$t} WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findBy(string $table, array $conditions): ?array
    {
        $t = $this->quoteTable($table);
        $where  = [];
        $params = [];
        foreach ($conditions as $col => $val) {
            $this->validateIdentifier($col);
            $where[]        = $this->quoteIdentifier($col) . " = :{$col}";
            $params[$col]   = $val;
        }

        $sql  = "SELECT * FROM {$t} WHERE " . implode(' AND ', $where) . " LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function all(
        string $table,
        array $conditions = [],
        ?string $orderBy = null,
        ?string $direction = 'ASC',
        ?int $limit = null,
        ?int $offset = null,
    ): array {
        $t = $this->quoteTable($table);
        $sql    = "SELECT * FROM {$t}";
        $params = [];

        if (!empty($conditions)) {
            $where = [];
            foreach ($conditions as $col => $val) {
                $this->validateIdentifier($col);
                $where[]      = $this->quoteIdentifier($col) . " = :{$col}";
                $params[$col] = $val;
            }
            $sql .= " WHERE " . implode(' AND ', $where);
        }

        if ($orderBy) {
            $this->validateIdentifier($orderBy);
            $dir = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
            $sql .= " ORDER BY " . $this->quoteIdentifier($orderBy) . " {$dir}";
        }

        if ($limit !== null) {
            $sql .= " LIMIT " . (int) $limit;
        }

        if ($offset !== null) {
            $sql .= " OFFSET " . (int) $offset;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function save(string $table, array $data): array
    {
        $id = $data['id'] ?? null;

        if ($id === null) {
            return $this->insert($table, $data);
        }

        $this->updateById($table, $data, $id);
        return $this->find($table, $id) ?? $data;
    }

    public function update(string $table, array $data, array $conditions): int
    {
        $t = $this->quoteTable($table);
        $set    = [];
        $params = [];

        foreach ($data as $col => $val) {
            $this->validateIdentifier($col);
            $set[]              = $this->quoteIdentifier($col) . " = :set_{$col}";
            $params["set_{$col}"] = is_array($val) ? json_encode($val) : $val;
        }

        $where = [];
        foreach ($conditions as $col => $val) {
            $this->validateIdentifier($col);
            $where[]                = $this->quoteIdentifier($col) . " = :where_{$col}";
            $params["where_{$col}"] = $val;
        }

        $sql  = "UPDATE {$t} SET " . implode(', ', $set) . " WHERE " . implode(' AND ', $where);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public function delete(string $table, int|string $id): bool
    {
        $t = $this->quoteTable($table);
        $stmt = $this->pdo->prepare("DELETE FROM {$t} WHERE id = :id");
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public function query(): Query
    {
        return new PdoQuery($this->pdo);
    }

    // =========================================================================
    // Transactions
    // =========================================================================

    public function beginTransaction(): void
    {
        if ($this->transactionDepth === 0) {
            $this->pdo->beginTransaction();
        }
        $this->transactionDepth++;
    }

    public function commit(): void
    {
        if ($this->transactionDepth <= 0) {
            return;
        }
        $this->transactionDepth--;
        if ($this->transactionDepth === 0) {
            $this->pdo->commit();
        }
    }

    public function rollBack(): void
    {
        if ($this->transactionDepth <= 0) {
            return;
        }
        $this->transactionDepth = 0;
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function inTransaction(): bool
    {
        return $this->transactionDepth > 0;
    }

    public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();
        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->rollBack();
            throw $e;
        }
    }

    // =========================================================================
    // Internal
    // =========================================================================

    private function insert(string $table, array $data): array
    {
        $t = $this->quoteTable($table);

        // Auto-generate ID if no id provided and generator is configured
        if (!isset($data['id']) && $this->idGenerator !== null) {
            $data['id'] = $this->idGenerator->next();
        }

        $hasGeneratedId = isset($data['id']);

        if (!$hasGeneratedId) {
            unset($data['id']);
        }

        // JSON-encode array values and strip non-column entries
        $clean = [];
        foreach ($data as $col => $val) {
            $clean[$col] = is_array($val) ? json_encode($val) : $val;
        }

        $columns      = array_keys($clean);
        $placeholders = array_map(fn($c) => ":{$c}", $columns);

        // Validate and quote column names to prevent SQL injection
        array_walk($columns, fn(string $c) => $this->validateIdentifier($c));
        $quotedCols   = array_map(fn($c) => $this->quoteIdentifier($c), $columns);

        $sql = "INSERT INTO {$t} (" . implode(', ', $quotedCols) . ") VALUES (" . implode(', ', $placeholders) . ")";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($clean);

        if (!$hasGeneratedId) {
            $data['id'] = (int) $this->pdo->lastInsertId();
        }

        return $data;
    }

    private function updateById(string $table, array $data, int|string $id): void
    {
        $t = $this->quoteTable($table);
        $set    = [];
        $params = ['id' => $id];

        foreach ($data as $col => $val) {
            if ($col === 'id') continue;
            $this->validateIdentifier($col);
            $set[]        = $this->quoteIdentifier($col) . " = :{$col}";
            $params[$col] = is_array($val) ? json_encode($val) : $val;
        }

        if (empty($set)) return;

        $sql  = "UPDATE {$t} SET " . implode(', ', $set) . " WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    private function quoteTable(string $table): string
    {
        return '`' . str_replace('`', '``', $table) . '`';
    }
}
