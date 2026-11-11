<?php

namespace App\Enums;

enum StageStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Done = 'done';
    case Skipped = 'skipped';
}
