<?php

namespace Nyoze\Domain;

/**
 * Resource — transforms raw data into a clean output shape.
 * Hides internal fields, renames keys, formats values.
 */
abstract class Resource
{
    abstract protected function transform(array $data): array;

    public static function make(array $data): array
    {
        return (new static())->transform($data);
    }

    public static function collection(array $items): array
    {
        $resource = new static();
        return array_map(fn(array $item) => $resource->transform($item), $items);
    }
}
