<?php

namespace App\Http\Resources;

use App\Models\Invitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Invitation
 */
class InvitationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'division' => DivisionResource::make($this->whenLoaded('division')),
            'role' => $this->whenLoaded('role', fn () => $this->role?->name),
            'inviter' => $this->whenLoaded('inviter', fn () => $this->inviter->name),
            'expires_at' => $this->expires_at->toIso8601String(),
            'is_expired' => $this->isExpired(),
            'accepted_at' => $this->accepted_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
