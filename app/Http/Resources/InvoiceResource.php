<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
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
            'period' => $this->period->format('Y-m'),
            'amount' => $this->amount,
            'due_date' => $this->due_date->toDateString(),
            'status' => $this->status->value,
            'unit' => new UnitResource($this->whenLoaded('unit')),
            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
        ];
    }
}
