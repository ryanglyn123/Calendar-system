<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'timezone' => $this->timezone,
            'avatar_path' => $this->avatar_path,
            'division' => DivisionResource::make($this->whenLoaded('division')),
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->pluck('name')->all()),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'two_factor_enabled' => $this->hasEnabledTwoFactorAuthentication(),
        ];
    }
}
