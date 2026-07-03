<?php

namespace App\Services\Booking;

use App\Enums\CalendarType;
use App\Models\Calendar;
use App\Models\Resource;
use App\Models\User;
use App\Services\Calendar\CalendarAccessService;
use Illuminate\Support\Facades\DB;

class ResourceService
{
    public function __construct(
        private readonly CalendarAccessService $access,
    ) {}

    /**
     * Every resource owns exactly one resource-type calendar; bookings show
     * up there as events.
     *
     * @param  array<string, mixed>  $attributes  validated, with a `color` for the calendar
     */
    public function create(array $attributes, User $createdBy): Resource
    {
        return DB::transaction(function () use ($attributes, $createdBy) {
            $calendar = Calendar::query()->create([
                'type' => CalendarType::Resource,
                'name' => $attributes['name'],
                'description' => $attributes['description'] ?? null,
                'color' => $attributes['color'] ?? '#0096E2',
                'is_default' => false,
                'is_active' => (bool) ($attributes['is_active'] ?? true),
                'created_by' => $createdBy->id,
            ]);

            $resource = Resource::query()->create([
                ...collect($attributes)->except('color')->all(),
                'calendar_id' => $calendar->id,
            ]);

            $this->access->forgetAll();

            return $resource;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Resource $resource, array $attributes): Resource
    {
        return DB::transaction(function () use ($resource, $attributes) {
            $resource->update(collect($attributes)->except('color')->all());

            $resource->calendar?->update([
                'name' => $resource->name,
                'description' => $resource->description,
                'color' => $attributes['color'] ?? $resource->calendar->color,
                'is_active' => $resource->is_active,
            ]);

            $this->access->forgetAll();

            return $resource;
        });
    }

    public function delete(Resource $resource): void
    {
        DB::transaction(function () use ($resource) {
            $resource->calendar?->update(['is_active' => false]);
            $resource->delete();

            $this->access->forgetAll();
        });
    }
}
