<?php

namespace App\Models;
 use App\Enums\BookingStatus;

use Illuminate\Database\Eloquent\Model;

class Booking extends Model
{
    // Booking.php  (add: use App\Enums\BookingStatus;)
    protected $fillable = ['facility_id', 'user_id', 'start_at', 'end_at', 'status', 'purpose', 'decided_by', 'review_note'];

    protected function casts(): array
    {
        return [
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'status' => BookingStatus::class,
        ];
    }

    public function facility() { return $this->belongsTo(Facility::class); }
    public function user() { return $this->belongsTo(User::class); }
}
