<?php

namespace Nyoze\Data\Database;

/**
 * ULID generator using Crockford's Base32 encoding.
 *
 * Generates 26-character ULIDs where:
 * - First 10 characters encode the timestamp in milliseconds
 * - Last 16 characters are cryptographically random
 *
 * ULIDs are lexicographically sortable and monotonically increasing
 * within the same millisecond (random portion increments).
 *
 * @see https://github.com/ulid/spec
 */
class UlidGenerator implements IdGeneratorInterface
{
    private const ENCODING = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    private int $lastTimestamp = -1;

    /** @var int[] */
    private array $lastRandom = [];

    /**
     * Generate the next ULID.
     *
     * @return string 26-character ULID string
     */
    public function next(): string
    {
        $timestamp = $this->currentTimeMillis();

        if ($timestamp === $this->lastTimestamp && !empty($this->lastRandom)) {
            $this->lastRandom = $this->incrementRandom($this->lastRandom);
        } else {
            $this->lastRandom = $this->generateRandom();
            $this->lastTimestamp = $timestamp;
        }

        return $this->encodeTimestamp($timestamp) . $this->encodeRandom($this->lastRandom);
    }

    private function encodeTimestamp(int $timestamp): string
    {
        $chars = '';
        for ($i = 9; $i >= 0; $i--) {
            $chars = self::ENCODING[$timestamp & 0x1F] . $chars;
            $timestamp >>= 5;
        }
        return $chars;
    }

    /** @param int[] $bytes */
    private function encodeRandom(array $bytes): string
    {
        $bitBuffer = 0;
        $bitsInBuffer = 0;
        $result = [];

        foreach ($bytes as $byte) {
            $bitBuffer = ($bitBuffer << 8) | $byte;
            $bitsInBuffer += 8;

            while ($bitsInBuffer >= 5) {
                $bitsInBuffer -= 5;
                $result[] = self::ENCODING[($bitBuffer >> $bitsInBuffer) & 0x1F];
            }
        }

        if ($bitsInBuffer > 0) {
            $result[] = self::ENCODING[($bitBuffer << (5 - $bitsInBuffer)) & 0x1F];
        }

        return implode('', $result);
    }

    /** @return int[] */
    private function generateRandom(): array
    {
        $bytes = random_bytes(10);
        $result = [];
        for ($i = 0; $i < 10; $i++) {
            $result[] = ord($bytes[$i]);
        }
        return $result;
    }

    /**
     * @param int[] $random
     * @return int[]
     */
    private function incrementRandom(array $random): array
    {
        for ($i = count($random) - 1; $i >= 0; $i--) {
            if ($random[$i] < 255) {
                $random[$i]++;
                return $random;
            }
            $random[$i] = 0;
        }

        throw new \RuntimeException('ULID random component overflow.');
    }

    private function currentTimeMillis(): int
    {
        return (int)(microtime(true) * 1000);
    }
}
