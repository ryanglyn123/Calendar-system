<?php

use App\Models\Booking;
use App\Models\User;
use App\Notifications\BookingDecidedNotification;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Logs a failed notification job the way the database queue would. */
function failNotificationJob(User $to, Booking $booking): void
{
    $command = new SendQueuedNotifications(collect([$to]), new BookingDecidedNotification($booking), ['mail']);

    app('queue.failer')->log('database', 'default', json_encode([
        'uuid' => (string) Str::uuid(),
        'displayName' => BookingDecidedNotification::class,
        'job' => 'Illuminate\Queue\CallQueuedHandler@call',
        'data' => ['commandName' => SendQueuedNotifications::class, 'command' => serialize($command)],
    ]), new RuntimeException('mail server down'));
}

test('retrying failed jobs skips the ones whose booking was deleted', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $kept = Booking::factory()->create();
    $gone = Booking::factory()->create();
    failNotificationJob($admin, $kept);
    failNotificationJob($admin, $gone);
    $gone->forceDelete();

    $this->actingAs($admin)
        ->from(route('admin.health.index'))
        ->post(route('admin.health.retry'))
        ->assertRedirect(route('admin.health.index'));

    expect(DB::table('failed_jobs')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(1);
});

test('only settings managers can retry failed jobs', function () {
    $staff = User::factory()->create();
    $staff->assignRole('staff');

    $this->actingAs($staff)->post(route('admin.health.retry'))->assertForbidden();
});
