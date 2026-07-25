<?php

namespace App\Enums;

enum ContactMatchStatus: string
{
    case Matched = 'matched';
    case NotFound = 'not_found';
    case MultipleMatches = 'multiple_matches';
    case InvalidPhone = 'invalid_phone';
}
