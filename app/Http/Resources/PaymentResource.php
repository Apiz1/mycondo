<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Enums\PaymentStatus;

class PaymentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
       return [
            'id' => $this->id,
            'invoice_id' => $this->invoice_id,
            'method' => $this->method->value,
            'amount' => $this->amount,
            'status' => $this->status->value,
            'note' => $this->note,
            'review_note' => $this->review_note,
            'submitted_at' => $this->created_at,
            'reviewed_at' => $this->verified_at,
            'payer' => $this->whenLoaded('payer', fn () => [
                'id' => $this->payer->id,
                'name' => $this->payer->name,
            ]),
            'invoice' => new InvoiceResource($this->whenLoaded('invoice')),
            'payment_url' => $this->when(
                $this->billplz_url
                    && $this->status === PaymentStatus::Pending
                    && $request->user()?->id === $this->paid_by,
                $this->billplz_url
            ),
        ];
    }
}
