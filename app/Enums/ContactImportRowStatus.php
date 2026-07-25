<?php

namespace App\Enums;

enum ContactImportRowStatus: string
{
    case Valid = 'valid';
    case Invalid = 'invalid';
    case Duplicate = 'duplicate';
    case Created = 'created';
    case Updated = 'updated';
    case Ignored = 'ignored';
}
