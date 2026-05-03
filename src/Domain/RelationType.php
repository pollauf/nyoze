<?php

namespace Nyoze\Domain;

enum RelationType: string
{
    case HasMany   = 'hasMany';
    case HasOne    = 'hasOne';
    case BelongsTo = 'belongsTo';
}
