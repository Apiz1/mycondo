<?php

// app/Enums/ComplaintCategory.php
namespace App\Enums;

enum ComplaintCategory: string
{
    case Maintenance = 'maintenance';
    case Noise = 'noise';
    case Security = 'security';
    case Cleanliness = 'cleanliness';
    case Parking = 'parking';
    case Other = 'other';
}