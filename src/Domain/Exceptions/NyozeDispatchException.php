<?php

namespace Nyoze\Domain\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Rich exception carrying full dispatch context for debugging and consistency reports.
 */
class NyozeDispatchException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string  $rootEntity,
        public readonly string  $rootAction,
        public readonly string  $failedEntity,
        public readonly string  $failedAction,
        public readonly string  $pipelineStage,
        public readonly array   $callStack,
        public readonly string  $correlationId,
        public readonly bool    $rolledBack,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Structured consistency report for logging / debugging.
     */
    public function toConsistencyReport(): array
    {
        return [
            'correlation_id' => $this->correlationId,
            'root'           => "{$this->rootEntity}.{$this->rootAction}",
            'failed'         => "{$this->failedEntity}.{$this->failedAction}",
            'stage'          => $this->pipelineStage,
            'rolled_back'    => $this->rolledBack,
            'call_chain'     => $this->formatChain(),
        ];
    }

    public function formatChain(): string
    {
        $parts = [];
        foreach ($this->callStack as $i => $frame) {
            $prefix = str_repeat('  ', $i);
            $marker = ($frame['entity'] === $this->failedEntity
                    && $frame['action'] === $this->failedAction)
                ? ' ❌' : '';
            $parts[] = "{$prefix}└── {$frame['entity']}.{$frame['action']}{$marker}";
        }
        return implode("\n", $parts);
    }
}
