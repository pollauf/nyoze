<?php

namespace Nyoze\Data\Database;

interface IdGeneratorInterface
{
    /**
     * Generate the next ID.
     */
    public function next(): int|string;
}
