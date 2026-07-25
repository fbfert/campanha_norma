<?php

namespace App\Enums;

enum RetryBackoffType: string
{
    case Fixed = 'fixed';
    case Linear = 'linear';
    case Exponential = 'exponential';
}
