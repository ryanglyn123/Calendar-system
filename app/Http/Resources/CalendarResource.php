<?php

namespace App\Http\Resources;

use App\Enums\CalendarType;
use App\Models\Calendar;
use App\Models\CalendarUserPreference;
use App\Models\User;
use App\Services\Google\GoogleAccounts;
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
            // The calendar of a unit under (or being) the user's division:
            // members may rename/recolor/delete the unit from the panel.
            'unit_manageable' => $this->type === CalendarType::Division
                && $this->relationLoaded('division')
                && $this->division?->parent_id !== null
                && $this->is_default
                && ($this->division->parent_id === $user->division_id
                    || $this->division->id === $user->division_id
                    || $user->can('divisions.manage')),
            'google' => $this->type === CalendarType::Google ? $this->googleSummary() : null,
        ];
    }

    /**
     * Sync health of a Google mirror, for the panel's status line.
     *
     * @return array<string, mixed>|null
     */
    private function googleSummary(): ?array
    {
        $google = $this->googleCalendar;

        if ($google === null) {
            return null;
        }

        return [
            'id' => $google->id,
            'access_role' => $google->access_role,
            'is_primary' => $google->is_primary,
            'is_holiday' => $google->is_holiday,
            'last_synced_at' => $google->last_synced_at?->toIso8601String(),
            'error' => GoogleAccounts::errorMessage($google->sync_error),
        ];
    }
}
