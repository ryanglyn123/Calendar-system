<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\Activitylog\Models\Activity;

/**
 * @mixin Activity
 */
class ActivityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $causer = $this->causer;

        return [
            'id' => $this->id,
            'description' => $this->description,
            'event' => $this->event,
            'causer' => $causer instanceof User ? $causer->name : null,
            'subject_type' => $this->subject_type !== null
                ? class_basename($this->subject_type)
                : null,
            'subject_id' => $this->subject_id,
            'changes' => $this->changes()->toArray(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
