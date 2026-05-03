<?php

namespace Nyoze\Domain\Exceptions;

use RuntimeException;

/**
 * Thrown when a recursive dispatch cycle is detected (A → B → A).
 */
class RecursiveDispatchException extends RuntimeException
{
    public function __construct(
        public readonly string $entity,
        public readonly string $action,
        public readonly array  $callStack,
        public readonly string $correlationId,
    ) {
        $chain = self::formatChain($callStack);
        parent::__construct(
            "Recursive dispatch detected: {$entity}.{$action} already in call stack. Chain: {$chain}"
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
