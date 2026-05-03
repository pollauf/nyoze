<?php

namespace Nyoze\Data\Database;

enum IdStrategy: string
{
    case Snowflake     = 'snowflake';
    case AutoIncrement = 'auto_increment';
    case Uuid          = 'uuid';
    case Ulid          = 'ulid';
}
