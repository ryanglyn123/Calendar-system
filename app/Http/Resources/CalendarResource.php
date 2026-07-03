<?php

namespace App\Http\Resources;

use App\Models\Calendar;
use App\Models\CalendarUserPreference;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializes a calendar for the signed-in user: effective color and
 * visibility already have that user's preferences applied.
 *
 * @mixin Calendar
 */
class CalendarResource extends JsonResource
{
    private ?CalendarUserPreference $preference = null;

    private bool $canWriteEvents = false;

    private bool $canManage = false;

    public function forUser(User $user, ?CalendarUserPreference $preference, bool $canWriteEvents, bool $canManage): self
    {
        $this->preference = $preference;
        $this->canWriteEvents = $canWriteEvents;
        $this->canManage = $canManage;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $request->user();

        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'name' => $this->name,
            'description' => $this->description,
            'color' => $this->color,
            'effective_color' => $this->preference->color_override ?? $this->color,
            'icon' => $this->icon,
            'is_default' => $this->is_default,
            'is_active' => $this->is_active,
            'is_owner' => $this->owner_id === $user->id,
            'is_visible' => $this->preference->is_visible ?? $this->visibleByDefaultFor($user),
            'sort_order' => $this->preference->sort_order ?? $this->sort_order,
            'division' => DivisionResource::make($this->whenLoaded('division')),
            'can_write_events' => $this->canWriteEvents,
            'can_manage' => $this->canManage,
        ];
    }
}
