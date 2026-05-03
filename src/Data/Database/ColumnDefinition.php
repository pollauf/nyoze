<?php

namespace Nyoze\Data\Database;

use Nyoze\Domain\FieldDefinition;
use Nyoze\Domain\FieldType;

final readonly class ColumnDefinition
{
    public function __construct(
        public string    $name,
        public FieldType $type,
        public bool      $required   = false,
        public bool      $unique     = false,
        public bool      $nullable   = false,
        public mixed     $default    = null,
        public bool      $hasDefault = false,
        public ?string   $refEntity  = null,
    ) {}

    /**
     * Create a ColumnDefinition from a FieldDefinition.
     */
    public static function fromField(FieldDefinition $field): self
    {
        return new self(
            name:       $field->name,
            type:       $field->type,
            required:   $field->required,
            unique:     $field->unique,
            nullable:   $field->nullable,
            default:    $field->default,
            hasDefault: $field->hasDefault,
            refEntity:  $field->refEntity,
        );
    }
}
