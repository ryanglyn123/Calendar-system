<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Support\Carbon;

/**
 * Shared by every calendar page controller: the date span whose events are
 * inlined with the page so the grid paints full on first render.
 */
trait ResolvesInitialWindow
{
    /**
     * Previous through next month as full grid weeks, padded a couple of days
     * so client-computed window edges always fall inside the span even when
     * the browser timezone differs from the profile timezone.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function initialSpan(?string $timezone, int $weekStartsOn): array
    {
        $now = Carbon::now($timezone ?? config('app.timezone'));

        $start = $now->copy()->subMonthNoOverflow()->startOfMonth()
            ->startOfWeek($weekStartsOn)->subDays(2);
        $end = $now->copy()->addMonthNoOverflow()->endOfMonth()
            ->endOfWeek($weekStartsOn === Carbon::MONDAY ? Carbon::SUNDAY : Carbon::SATURDAY)
            ->addDays(3);

        return [$start->utc(), $end->utc()];
    }
}
