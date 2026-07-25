<?php

namespace App\Enums;

enum MessageSendAttemptStatus: string
{
    case Started = 'started';
    case Sent = 'sent';
    case Failed = 'failed';
    case Unknown = 'unknown';
    case Cancelled = 'cancelled';
}
