<?php

namespace App\Enums;

enum VisitorStatus: string
{
    case Pending = 'pending';
    case CheckedIn = 'checked_in';
    case CheckedOut = 'checked_out';
    case Cancelled = 'cancelled';
}