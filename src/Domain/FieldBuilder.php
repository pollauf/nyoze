<?php

namespace Nyoze\Domain;

class FieldBuilder
{
    private bool $isRequired  = false;
    private bool $isUnique    = false;
    private bool $isHidden    = false;
    private bool $isNullable  = false;
    private mixed $default    = null;
    private bool $hasDefault  = false;
    private ?string $label    = null;
    private ?string $refEntity = null;
    private ?string $enumClass = null;
    private ?int $maxLength   = null;
    private ?int $minLength   = null;

    public function __construct(
        private readonly string $name,
        private readonly FieldType $type,
    ) {}

    public function required(): self
    {
        $this->isRequired = true;
        return $this;
    }

    public function unique(): self
    {
        $this->isUnique = true;
        return $this;
    }

    public function hidden(): self
    {
        $this->isHidden = true;
        return $this;
    }

    public function nullable(): self
    {
        $this->isNullable = true;
        return $this;
    }

    public function default(mixed $value): self
    {
        $this->default    = $value;
        $this->hasDefault = true;
        return $this;
    }

    public function defaultNow(): self
    {
        $this->default    = '__NOW__';
        $this->hasDefault = true;
        return $this;
    }

    public function label(string $label): self
    {
        $this->label = $label;
        return $this;
    }

    public function references(string $entity): self
    {
        $this->refEntity = $entity;
        return $this;
    }

    public function enumClass(string $class): self
    {
        $this->enumClass = $class;
        return $this;
    }

    public function max(int $length): self
    {
        $this->maxLength = $length;
        return $this;
    }

    public function min(int $length): self
    {
        $this->minLength = $length;
        return $this;
    }

    public function build(): FieldDefinition
    {
        return new FieldDefinition(
            name:      $this->name,
            type:      $this->type,
            required:  $this->isRequired,
            unique:    $this->isUnique,
            hidden:    $this->isHidden,
            nullable:  $this->isNullable,
            default:   $this->default,
            hasDefault: $this->hasDefault,
            label:     $this->label,
            refEntity: $this->refEntity,
            enumClass: $this->enumClass,
            maxLength: $this->maxLength,
            minLength: $this->minLength,
        );
    }
}
