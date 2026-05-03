<?php

namespace Nyoze\Domain;

/**
 * Immutable definition of a field — produced by FieldBuilder.
 */
final readonly class FieldDefinition
{
    public function __construct(
        public string    $name,
        public FieldType $type,
        public bool      $required   = false,
        public bool      $unique     = false,
        public bool      $hidden     = false,
        public bool      $nullable   = false,
        public mixed     $default    = null,
        public bool      $hasDefault = false,
        public ?string   $label      = null,
        public ?string   $refEntity  = null,
        public ?string   $enumClass  = null,
        public ?int      $maxLength  = null,
        public ?int      $minLength  = null,
    ) {}

    public function resolveDefault(): mixed
    {
        if (!$this->hasDefault) {
            return null;
        }

        if ($this->default === '__NOW__') {
            return date('Y-m-d H:i:s');
        }

        return $this->default;
    }

    public function isReference(): bool
    {
        return $this->type === FieldType::Ref;
    }

    public function isId(): bool
    {
        return $this->type === FieldType::Id;
    }
}
