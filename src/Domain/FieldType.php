<?php

namespace Nyoze\Domain;

enum FieldType: string
{
    case Id       = 'id';
    case String   = 'string';
    case Text     = 'text';
    case Integer  = 'integer';
    case BigInt   = 'bigint';
    case Decimal  = 'decimal';
    case Boolean  = 'boolean';
    case DateTime = 'datetime';
    case Date     = 'date';
    case Email    = 'email';
    case Password = 'password';
    case Money    = 'money';
    case Json     = 'json';
    case Enum     = 'enum';
    case Ref      = 'ref';
}
