<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Committee = 'committee';
    case Resident = 'resident';
    case Security = 'security';
}