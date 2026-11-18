<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Facility extends Model
{
   // Facility.php
    protected $fillable = ['name', 'open_time', 'close_time', 'max_duration_minutes', 'needs_approval', 'is_active'];

    protected function casts(): array
    {
        return ['needs_approval' => 'boolean', 'is_active' => 'boolean'];
    }

    public function bookings() { return $this->hasMany(Booking::class); }
}
