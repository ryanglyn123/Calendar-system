<?php

namespace App\Models;

use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property int|null $division_id
 * @property string $name
 * @property string $email
 * @property UserStatus $status
 * @property Carbon|null $approved_at
 * @property int|null $approved_by
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property string|null $sso_sub
 * @property string $timezone
 * @property string|null $avatar_path
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['name', 'email', 'password', 'division_id', 'status', 'timezone', 'avatar_path'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token', 'sso_sub'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, SoftDeletes, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'status' => UserStatus::class,
            'approved_at' => 'datetime',
            'color_labels' => 'array',
        ];
    }

    /** @return BelongsTo<Division, $this> */
    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    /** @return HasOne<GoogleAccount, $this> */
    public function googleAccount(): HasOne
    {
        return $this->hasOne(GoogleAccount::class);
    }

    /**
     * The user's division plus its parent and units — the set whose division
     * calendars this user may view (writes stay own-division only).
     *
     * @return list<int>
     */
    public function relatedDivisionIds(): array
    {
        if ($this->division_id === null) {
            return [];
        }

        $ids = [(int) $this->division_id];

        if ($this->division?->parent_id !== null) {
            $ids[] = (int) $this->division->parent_id;
        }

        foreach (Division::query()->where('parent_id', $this->division_id)->pluck('id') as $childId) {
            $ids[] = (int) $childId;
        }

        return $ids;
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return HasMany<NotificationPreference, $this> */
    public function notificationPreferences(): HasMany
    {
        return $this->hasMany(NotificationPreference::class);
    }

    /**
     * Channels to deliver the given notification key through, honoring the
     * user's saved preferences. No saved row means every channel is on.
     *
     * @return list<string>
     */
    public function notificationChannelsFor(string $key): array
    {
        $preference = $this->notificationPreferences()
            ->where('notification_key', $key)
            ->first();

        $channels = [];

        if ($preference->mail_enabled ?? true) {
            $channels[] = 'mail';
        }

        if ($preference->database_enabled ?? true) {
            $channels[] = 'database';
        }

        return $channels;
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    public function isPending(): bool
    {
        return $this->status === UserStatus::Pending;
    }

    public function isSuspended(): bool
    {
        return $this->status === UserStatus::Suspended;
    }

    /**
     * @param  Builder<User>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', UserStatus::Active);
    }

    /**
     * Active users that effectively hold the given permission — via a role,
     * a direct grant, or the super_admin Gate::before bypass.
     *
     * @return Collection<int, User>
     */
    public static function havingPermission(string $permission): Collection
    {
        return User::query()
            ->active()
            ->where(function (Builder $query) use ($permission) {
                $query
                    ->whereHas('roles', fn ($q) => $q->where('name', 'super_admin'))
                    ->orWhereHas('roles.permissions', fn ($q) => $q->where('name', $permission))
                    ->orWhereHas('permissions', fn ($q) => $q->where('name', $permission));
            })
            ->get();
    }

    /**
     * @param  Builder<User>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->where('status', UserStatus::Pending);
    }
}
