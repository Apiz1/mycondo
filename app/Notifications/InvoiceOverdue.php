<?php
// app/Notifications/InvoiceOverdue.php
namespace App\Notifications;

use App\Models\Invoice;
use Illuminate\Notifications\Notification;

class InvoiceOverdue extends Notification
{
    public function __construct(public Invoice $invoice) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'       => 'invoice.overdue',
            'title'      => 'Invoice overdue',
            'message'    => "Invoice #{$this->invoice->id} was due on "
                . $this->invoice->due_date->format('d M Y') . ' and is now overdue.',
            'invoice_id' => $this->invoice->id,
        ];
    }
}