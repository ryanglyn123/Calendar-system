<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property Carbon $date
 * @property bool $recurs_annually
 * @property string $type
 * @property bool $blocks_bookings
 */
#[Fillable(['name', 'date', 'recurs_annually', 'type', 'blocks_bookings'])]
class Holiday extends Model
{
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'recurs_annually' => 'boolean',
            'blocks_bookings' => 'boolean',
        ];
    }

    public static function blockingOn(CarbonInterface $day): bool
    {
        return static::query()
            ->where('blocks_bookings', true)
            ->where(fn ($query) => $query
                ->whereDate('date', $day->toDateString())
                ->orWhere(fn ($q) => $q
                    ->where('recurs_annually', true)
                    ->whereMonth('date', $day->month)
                    ->whereDay('date', $day->day)))
            ->exists();
    }
}
