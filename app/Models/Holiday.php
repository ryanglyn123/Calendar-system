<?php

namespace App\Models;

use App\Settings\GeneralSettings;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property Carbon $date
 * @property bool $recurs_annually
 * @property string $type
 * @property bool $blocks_bookings
 * @property string $source manual | google
 * @property string|null $google_calendar_id
 * @property string|null $google_event_id
 * @property bool $overridden an admin edited an imported holiday; syncs leave it alone
 */
#[Fillable(['name', 'date', 'recurs_annually', 'type', 'blocks_bookings', 'source', 'google_calendar_id', 'google_event_id', 'overridden'])]
class Holiday extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'recurs_annually' => 'boolean',
            'blocks_bookings' => 'boolean',
            'overridden' => 'boolean',
        ];
    }

    public static function blockingOn(CarbonInterface $day): bool
    {
        return static::blockingFor($day) !== null;
    }

    /**
     * The first blocking holiday on any office day (the configured timezone) inside
     * [start, end): an overnight trip that runs into a holiday is blocked,
     * a booking that ends exactly at midnight before one is not.
     */
    public static function blockingWithin(CarbonInterface $start, CarbonInterface $end): ?self
    {
        $holidays = static::query()->where('blocks_bookings', true)->get();
        $office = app(GeneralSettings::class)->timezone;
        $day = $start->avoidMutation()->timezone($office)->startOfDay();
        $last = $end->avoidMutation()->timezone($office);

        for (; $day < $last; $day = $day->addDay()) {
            $hit = $holidays->first(fn (self $holiday) => $holiday->recurs_annually
                ? $holiday->date->month === $day->month && $holiday->date->day === $day->day
                : $holiday->date->toDateString() === $day->toDateString());

            if ($hit !== null) {
                return $hit;
            }
        }

        return null;
    }

    /** The holiday that blocks bookings on this day, if any. */
    public static function blockingFor(CarbonInterface $day): ?self
    {
        return static::query()
            ->where('blocks_bookings', true)
            ->where(fn ($query) => $query
                ->whereDate('date', $day->toDateString())
                ->orWhere(fn ($q) => $q
                    ->where('recurs_annually', true)
                    ->whereMonth('date', $day->month)
                    ->whereDay('date', $day->day)))
            ->first();
    }
}
