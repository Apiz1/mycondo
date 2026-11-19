<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ComplaintResource extends JsonResource
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
            'category' => $this->category->value,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status->value,
            'has_photo' => $this->photo_path !== null,
            'unit' => new UnitResource($this->whenLoaded('unit')),
            'reporter' => $this->whenLoaded('reporter', fn () => [
                'id' => $this->reporter->id,
                'name' => $this->reporter->name,
            ]),
            'comments' => ComplaintCommentResource::collection($this->whenLoaded('comments')),
            'resolved_at' => $this->resolved_at,
            'created_at' => $this->created_at,
        ];
    }
}
