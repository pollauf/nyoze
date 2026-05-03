<?php

namespace Nyoze\Data\Database;

class SnowflakeGenerator implements IdGeneratorInterface
{
    // Bit layout (64 bits total):
    // 1 bit  — sign (always 0)
    // 41 bits — timestamp in ms since custom epoch
    // 10 bits — nodeId (0–1023)
    // 12 bits — sequence (0–4095)

    private const EPOCH = 1700000000000; // 2023-11-14T22:13:20Z — custom epoch
    private const NODE_BITS = 10;
    private const SEQUENCE_BITS = 12;
    private const MAX_NODE_ID = (1 << self::NODE_BITS) - 1;       // 1023
    private const MAX_SEQUENCE = (1 << self::SEQUENCE_BITS) - 1;   // 4095

    private int $nodeId;
    private int $sequence = 0;
    private int $lastTimestamp = -1;

    /**
     * @param int $nodeId Node identifier (0–1023)
     * @throws \InvalidArgumentException if nodeId is out of range
     */
    public function __construct(int $nodeId = 0)
    {
        if ($nodeId < 0 || $nodeId > self::MAX_NODE_ID) {
            throw new \InvalidArgumentException(
                "nodeId must be between 0 and " . self::MAX_NODE_ID . ", got {$nodeId}"
            );
        }

        $this->nodeId = $nodeId;
    }

    /**
     * Generate the next Snowflake ID.
     *
     * @return int 64-bit ID
     * @throws \RuntimeException if the clock moves backwards
     */
    public function next(): int
    {
        $timestamp = $this->currentTimeMillis();

        if ($timestamp < $this->lastTimestamp) {
            throw new \RuntimeException(
                "Clock moved backwards. Refusing to generate ID for "
                . ($this->lastTimestamp - $timestamp) . " milliseconds"
            );
        }

        if ($timestamp === $this->lastTimestamp) {
            $this->sequence++;

            if ($this->sequence > self::MAX_SEQUENCE) {
                $timestamp = $this->waitNextMillis($this->lastTimestamp);
                $this->sequence = 0;
            }
        } else {
            $this->sequence = 0;
        }

        $this->lastTimestamp = $timestamp;

        return (($timestamp - self::EPOCH) << (self::NODE_BITS + self::SEQUENCE_BITS))
             | ($this->nodeId << self::SEQUENCE_BITS)
             | $this->sequence;
    }

    /**
     * Extract the components of a Snowflake ID.
     *
     * @return array{timestamp: int, nodeId: int, sequence: int}
     */
    public static function parse(int $id): array
    {
        $sequence  = $id & self::MAX_SEQUENCE;
        $nodeId    = ($id >> self::SEQUENCE_BITS) & self::MAX_NODE_ID;
        $timestamp = ($id >> (self::NODE_BITS + self::SEQUENCE_BITS)) + self::EPOCH;

        return [
            'timestamp' => $timestamp,
            'nodeId'    => $nodeId,
            'sequence'  => $sequence,
        ];
    }

    /**
     * Return the current timestamp in milliseconds.
     */
    private function currentTimeMillis(): int
    {
        return (int)(microtime(true) * 1000);
    }

    /**
     * Wait until the next millisecond.
     */
    private function waitNextMillis(int $lastTimestamp): int
    {
        $timestamp = $this->currentTimeMillis();

        while ($timestamp <= $lastTimestamp) {
            $timestamp = $this->currentTimeMillis();
        }

        return $timestamp;
    }
}
