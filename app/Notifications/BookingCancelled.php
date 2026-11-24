<?php

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Notifications\Notification;

class BookingCancelled extends Notification
{
    public function __construct(public Booking $booking) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $this->booking->loadMissing('facility');

        $facility = $this->booking->facility->name;
        $when     = $this->booking->start_at->format('d M Y, H:i');

        return [
            'type'       => 'booking.cancelled',
            'title'      => 'Booking cancelled',
            'message'    => "Your booking for {$facility} on {$when} was cancelled by the committee.",
            'booking_id' => $this->booking->id,
        ];
    }
}