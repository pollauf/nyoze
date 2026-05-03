<?php

namespace Nyoze\Domain;

final readonly class Relation
{
    public function __construct(
        public string       $name,
        public string       $entity,
        public RelationType $type,
        public string       $foreignKey,
        public ?string      $localKey = 'id',
    ) {}
}
