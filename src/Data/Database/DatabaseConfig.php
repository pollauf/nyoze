<?php

namespace Nyoze\Data\Database;

use InvalidArgumentException;
use PDO;
use Nyoze\Data\PdoRepository;

class DatabaseConfig
{
    private ?DatabaseProviderInterface $provider = null;
    private ?PDO $pdo = null;
    private IdStrategy $idStrategy = IdStrategy::Snowflake;
    private ?SnowflakeGenerator $snowflake = null;
    private int $nodeId = 0;

    /**
     * Set the database provider.
     */
    public function provider(DatabaseProviderInterface $provider): self
    {
        $this->provider = $provider;
        return $this;
    }

    /**
     * Set the PDO connection parameters.
     *
     * @param array{dsn: string, user?: string, pass?: string, options?: array} $params
     */
    public function connection(array $params): self
    {
        $dsn     = $params['dsn'];
        $user    = $params['user'] ?? null;
        $pass    = $params['pass'] ?? null;
        $options = $params['options'] ?? [];

        $this->pdo = new PDO($dsn, $user, $pass, $options);

        return $this;
    }

    /**
     * Set the global ID strategy.
     */
    public function idStrategy(IdStrategy $strategy): self
    {
        $this->idStrategy = $strategy;
        return $this;
    }

    /**
     * Set the nodeId for the SnowflakeGenerator.
     */
    public function nodeId(int $nodeId): self
    {
        $this->nodeId = $nodeId;
        // Reset cached snowflake so it gets recreated with the new nodeId
        $this->snowflake = null;
        return $this;
    }

    /**
     * Shortcut: configure MySqlProvider + connection + Snowflake.
     *
     * @param array{host: string, port?: int, database: string, user: string, pass: string, charset?: string} $params
     * @throws InvalidArgumentException if required fields are missing
     */
    public function mysql(array $params): self
    {
        $required = ['host', 'database', 'user'];
        $missing  = [];

        foreach ($required as $field) {
            if (!isset($params[$field]) || $params[$field] === '') {
                $missing[] = $field;
            }
        }

        // 'pass' must be present as a key but can be empty (common in dev)
        if (!array_key_exists('pass', $params)) {
            $missing[] = 'pass';
        }

        if (!empty($missing)) {
            throw new InvalidArgumentException(
                'Missing required mysql parameters: ' . implode(', ', $missing)
            );
        }

        $host    = $params['host'];
        $port    = $params['port'] ?? 3306;
        $database = $params['database'];
        $user    = $params['user'];
        $pass    = $params['pass'];
        $charset = $params['charset'] ?? 'utf8mb4';

        $dsn = "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";

        $this->provider = new MySqlProvider();
        $this->idStrategy = IdStrategy::Snowflake;
        $this->pdo = new PDO($dsn, $user, $pass);

        return $this;
    }

    /**
     * Return the configured provider (or SqliteProvider as fallback).
     */
    public function getProvider(): DatabaseProviderInterface
    {
        return $this->provider ?? new SqliteProvider();
    }

    /**
     * Return the configured IdStrategy.
     */
    public function getIdStrategy(): IdStrategy
    {
        return $this->idStrategy;
    }

    /**
     * Return the configured PDO instance.
     */
    public function getPdo(): ?PDO
    {
        return $this->pdo;
    }

    /**
     * Return the SnowflakeGenerator (created on demand).
     */
    public function getSnowflakeGenerator(): SnowflakeGenerator
    {
        if ($this->snowflake === null) {
            $this->snowflake = new SnowflakeGenerator($this->nodeId);
        }

        return $this->snowflake;
    }

    /**
     * Create and return a PdoRepository with the configured connection.
     *
     * @throws \RuntimeException if no PDO connection has been configured
     */
    public function createRepository(): PdoRepository
    {
        if ($this->pdo === null) {
            throw new \RuntimeException('No PDO connection configured. Call connection() or mysql() first.');
        }

        $idGenerator = null;

        if ($this->idStrategy === IdStrategy::Snowflake) {
            $idGenerator = $this->getSnowflakeGenerator();
        }

        return new PdoRepository($this->pdo, $idGenerator);
    }
}
