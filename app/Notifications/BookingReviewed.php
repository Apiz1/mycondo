<?php

namespace App\Notifications;

use App\Enums\BookingStatus;
use App\Models\Booking;
use Illuminate\Notifications\Notification;

class BookingReviewed extends Notification
{
    public function __construct(public Booking $booking) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $this->booking->loadMissing('facility');

        $approved = $this->booking->status === BookingStatus::Approved;
        $facility = $this->booking->facility->name;
        $when     = $this->booking->start_at->format('d M Y, H:i');

        return [
            'type'       => $approved ? 'booking.approved' : 'booking.rejected',
            'title'      => $approved ? 'Booking approved' : 'Booking rejected',
            'message'    => $approved
                ? "Your booking for {$facility} on {$when} was approved."
                : "Your booking for {$facility} on {$when} was rejected: {$this->booking->review_note}",
            'booking_id' => $this->booking->id,
        ];
    }
}