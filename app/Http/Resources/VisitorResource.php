<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Enums\UserRole;
use App\Enums\VisitorStatus;

class VisitorResource extends JsonResource
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
            'name' => $this->name,
            'vehicle_plate' => $this->vehicle_plate,
            'visit_date' => $this->visit_date->toDateString(),
            'status' => $this->status->value,
            'expired' => $this->status === VisitorStatus::Pending && $this->visit_date->isBefore(today()),
            'code' => $this->when($request->user()?->role === UserRole::Resident, $this->code),
            'unit' => $this->whenLoaded('unit', fn () => [
                'id' => $this->unit->id,
                'label' => $this->unit->label,
            ]),
            'checked_in_at' => $this->checked_in_at,
            'checked_out_at' => $this->checked_out_at,
        ];
    }
}
