<?php

namespace Nyoze\Domain\Exceptions;

use RuntimeException;

/**
 * Thrown when action dispatch exceeds the maximum allowed nesting depth.
 */
class MaxDepthExceededException extends RuntimeException
{
    public function __construct(
        public readonly int    $maxDepth,
        public readonly array  $callStack,
        public readonly string $correlationId,
    ) {
        $chain = self::formatChain($callStack);
        parent::__construct(
            "Action dispatch exceeded max depth ({$maxDepth}). Call chain: {$chain}"
        );
    }

    private static function formatChain(array $stack): string
    {
        return implode(' → ', array_map(
            fn(array $f) => "{$f['entity']}.{$f['action']}",
            $stack,
        ));
    }
}
