<?php
// app/Notifications/PaymentReviewed.php
namespace App\Notifications;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Notifications\Notification;

class PaymentReviewed extends Notification
{
    public function __construct(public Payment $payment) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $approved = $this->payment->status === PaymentStatus::Approved;

        return [
            'type'       => $approved ? 'payment.approved' : 'payment.rejected',
            'title'      => $approved ? 'Payment approved' : 'Payment rejected',
            'message'    => $approved
                ? "Your payment for invoice #{$this->payment->invoice_id} was approved."
                : "Your payment for invoice #{$this->payment->invoice_id} was rejected: {$this->payment->review_note}",
            'invoice_id' => $this->payment->invoice_id,
            'payment_id' => $this->payment->id,
        ];
    }
}