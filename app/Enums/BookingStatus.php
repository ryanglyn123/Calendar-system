<?php

namespace App\Enums;

enum BookingStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Pending => in_array($next, [self::Approved, self::Rejected, self::Cancelled], true),
            self::Approved => $next === self::Cancelled,
            self::Rejected, self::Cancelled => false,
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => '#F5CB81',
            self::Approved => '#86DBAE',
            self::Rejected => '#F7A79B',
            self::Cancelled => '#BFC6CE',
        };
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
