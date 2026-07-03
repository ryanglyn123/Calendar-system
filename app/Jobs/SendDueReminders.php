<?php

namespace App\Jobs;

use App\Models\Event;
use App\Notifications\EventReminderNotification;
use App\Services\Event\OccurrenceExpander;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Runs every minute from the scheduler. For every reminder attached to an
 * event with an occurrence coming up, dispatch the notification exactly once
 * per (reminder, occurrence) pair; the reminder_dispatches unique key makes
 * this restart-safe and recurring-safe.
 */
class SendDueReminders implements ShouldQueue
{
    use Queueable;

    /** How far ahead occurrences are materialized for reminder checks. */
    private const LOOKAHEAD_DAYS = 15;

    public function handle(OccurrenceExpander $expander): void
    {
        $now = now();
        $windowStart = $now->copy();
        $windowEnd = $now->copy()->addDays(self::LOOKAHEAD_DAYS);

        $events = Event::query()
            ->whereHas('reminders')
            ->windowRoots($windowStart, $windowEnd)
            ->with(['reminders.user', 'overrides'])
            ->get();

        foreach ($expander->expand($events->collect(), $windowStart, $windowEnd) as $occurrence) {
            // Overrides carry their own reminders only via the parent series.
            $reminderSource = $occurrence->event->isOverride()
                ? $events->firstWhere('id', $occurrence->event->recurring_parent_id)
                : $occurrence->event;

            foreach ($reminderSource->reminders ?? [] as $reminder) {
                $dueAt = $occurrence->startsAt->avoidMutation()->subMinutes($reminder->minutes_before);

                if ($dueAt > $now || $occurrence->startsAt <= $now) {
                    continue;
                }

                $claimed = DB::table('reminder_dispatches')->insertOrIgnore([
                    'event_reminder_id' => $reminder->id,
                    'occurrence_starts_at' => $occurrence->startsAt->avoidMutation()->utc(),
                    'sent_at' => $now,
                ]);

                if ($claimed === 0 || $reminder->user === null) {
                    continue;
                }

                $reminder->user->notify(new EventReminderNotification(
                    $occurrence->event,
                    $occurrence->startsAt,
                    $reminder->channel,
                ));
            }
        }
    }
}
