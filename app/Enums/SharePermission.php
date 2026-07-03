<?php

namespace App\Enums;

enum SharePermission: string
{
    case Viewer = 'viewer';
    case Editor = 'editor';
    case Manager = 'manager';

    public function label(): string
    {
        return match ($this) {
            self::Viewer => 'Can view',
            self::Editor => 'Can edit events',
            self::Manager => 'Can manage calendar',
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::Viewer => 1,
            self::Editor => 2,
            self::Manager => 3,
        };
    }

    public function atLeast(self $other): bool
    {
        return $this->rank() >= $other->rank();
    }
}
