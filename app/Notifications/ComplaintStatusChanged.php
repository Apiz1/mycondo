<?php

namespace App\Notifications;

use App\Models\Complaint;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class ComplaintStatusChanged extends Notification
{
    public function __construct(public Complaint $complaint) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $status = Str::headline($this->complaint->status->value);

        return [
            'type'         => 'complaint.status_changed',
            'title'        => 'Complaint updated',
            'message'      => "Your complaint \"{$this->complaint->title}\" is now: {$status}.",
            'complaint_id' => $this->complaint->id,
            'status'       => $this->complaint->status->value,
        ];
    }
}