<?php

namespace App\Notifications;

use App\Models\Announcement;
use Illuminate\Notifications\Notification;

class AnnouncementPosted extends Notification
{
    public function __construct(public Announcement $announcement) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'            => 'announcement.posted',
            'title'           => 'New announcement',
            'message'         => $this->announcement->title,
            'announcement_id' => $this->announcement->id,
        ];
    }
}