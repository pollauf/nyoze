<?php

namespace Nyoze\Data\Database;

/**
 * UUID v4 generator using cryptographic randomness.
 *
 * Generates RFC 4122 compliant UUID v4 strings in the format:
 * xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx
 * where y is one of 8, 9, a, or b.
 */
class UuidGenerator implements IdGeneratorInterface
{
    /**
     * Generate the next UUID v4.
     *
     * @return string UUID v4 string (36 characters with hyphens)
     */
    public function next(): string
    {
        $bytes = random_bytes(16);

        // Set version to 4 (0100 in high nibble of byte 6)
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);

        // Set variant to RFC 4122 (10xx in high bits of byte 8)
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return sprintf(
            '%s-%s-%s-%s-%s',
            bin2hex(substr($bytes, 0, 4)),
            bin2hex(substr($bytes, 4, 2)),
            bin2hex(substr($bytes, 6, 2)),
            bin2hex(substr($bytes, 8, 2)),
            bin2hex(substr($bytes, 10, 6))
        );
    }
}
