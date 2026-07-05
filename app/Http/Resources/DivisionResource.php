<?php

namespace App\Http\Resources;

use App\Models\Division;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Division
 */
class DivisionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'parent_id' => $this->parent_id,
            'parent' => $this->whenLoaded('parent', fn (): array => [
                'id' => $this->parent->id,
                'name' => $this->parent->name,
            ]),
            'name' => $this->name,
            'acronym' => $this->acronym,
            'description' => $this->description,
            'color' => $this->color,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
            'users_count' => $this->whenCounted('users'),
        ];
    }
}
