<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A chat room. Division rooms (type=division, membership derived from the
 * division tree) and custom group chats (type=custom, explicit participants,
 * optionally linked to an event).
 *
 * @property int $id
 * @property string $type
 * @property int|null $division_id
 * @property int|null $event_id
 * @property string|null $name
 * @property string|null $photo_path
 * @property int|null $created_by
 * @property Carbon $created_at
 */
#[Fillable(['type', 'division_id', 'event_id', 'name', 'photo_path', 'created_by'])]
class Conversation extends Model
{
    use SoftDeletes;

    public const TYPE_DIVISION = 'division';

    public const TYPE_CUSTOM = 'custom';

    public function isDivision(): bool
    {
        return $this->type === self::TYPE_DIVISION;
    }

    /** @return BelongsTo<Division, $this> */
    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Explicit members of a custom chat.
     *
     * @return BelongsToMany<User, $this>
     */
    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    /** @return HasMany<ChatMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }

    /**
     * Newest message, for the conversation-list preview.
     *
     * @return HasOne<ChatMessage, $this>
     */
    public function latestMessage(): HasOne
    {
        return $this->hasOne(ChatMessage::class)->withTrashed()->latestOfMany();
    }

    /**
     * Conversations the user may read: their own division room, the parent
     * division room if they are a unit member, and any custom chat they join.
     *
     * @param  Builder<Conversation>  $query
     */
    public function scopeAccessibleTo(Builder $query, User $user): void
    {
        $divisionIds = $user->chatDivisionIds();

        $query->where(function (Builder $q) use ($user, $divisionIds) {
            $q->where(fn (Builder $d) => $d
                ->where('type', self::TYPE_DIVISION)
                ->whereIn('division_id', $divisionIds))
                ->orWhere(fn (Builder $c) => $c
                    ->where('type', self::TYPE_CUSTOM)
                    ->whereHas('participants', fn (Builder $p) => $p->whereKey($user->id)));
        });
    }

    public function isAccessibleTo(User $user): bool
    {
        if ($this->isDivision()) {
            return in_array((int) $this->division_id, $user->chatDivisionIds(), true);
        }

        return $this->participants()->whereKey($user->id)->exists();
    }

    /**
     * User ids that belong to this room: participants for custom chats, the
     * division (plus its units when it is a parent) for division rooms.
     *
     * @return list<int>
     */
    public function memberUserIds(): array
    {
        if (! $this->isDivision()) {
            return array_values(array_map('intval', $this->participants()->pluck('users.id')->all()));
        }

        return array_values(array_map('intval', User::query()
            ->where('division_id', $this->division_id)
            ->orWhereHas('division', fn (Builder $d) => $d->where('parent_id', $this->division_id))
            ->pluck('id')
            ->all()));
    }
}
