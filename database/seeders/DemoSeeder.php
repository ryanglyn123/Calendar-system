<?php

namespace Database\Seeders;

use App\Enums\BookingStatus;
use App\Enums\UserStatus;
use App\Models\Booking;
use App\Models\Division;
use App\Models\Event;
use App\Models\Resource;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Full local demo dataset: users for every role, sample events, and
 * bookings in every status. Never run in production.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = [
            ['Dina Reyes', 'division.admin@fdcp.gov.ph', 'division_admin', 'FED'],
            ['Marco Santos', 'staff@fdcp.gov.ph', 'staff', 'MIS'],
            ['Lea Cruz', 'room.approver@fdcp.gov.ph', 'board_room_approver', 'ADMIN'],
            ['Paolo Garcia', 'vehicle.approver@fdcp.gov.ph', 'vehicle_approver', 'ADMIN'],
        ];

        foreach ($accounts as [$name, $email, $role, $division]) {
            $user = User::query()->firstOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'password' => 'password',
                    'status' => UserStatus::Active,
                    'email_verified_at' => now(),
                    'approved_at' => now(),
                    'timezone' => 'Asia/Manila',
                    'division_id' => Division::query()->where('acronym', $division)->value('id'),
                ],
            );

            $user->syncRoles([$role]);
        }

        User::query()->firstOrCreate(
            ['email' => 'pending@fdcp.gov.ph'],
            [
                'name' => 'Awaiting Approval',
                'password' => 'password',
                'status' => UserStatus::Pending,
                'email_verified_at' => now(),
                'timezone' => 'Asia/Manila',
            ],
        );

        $this->call(DemoEventSeeder::class);

        $this->seedBookings();
    }

    private function seedBookings(): void
    {
        $staff = User::query()->where('email', 'staff@fdcp.gov.ph')->firstOrFail();
        $roomApprover = User::query()->where('email', 'room.approver@fdcp.gov.ph')->firstOrFail();
        $room = Resource::query()->where('name', 'Cinema Boardroom')->firstOrFail();
        $van = Resource::query()->where('name', 'Toyota Hiace (SKB 1234)')->firstOrFail();

        $monday = Carbon::now('Asia/Manila')->startOfWeek();

        $reviewed = ['reviewed_by' => $roomApprover->id, 'reviewed_at' => now()];
        $cancelled = [
            'cancelled_by' => $staff->id,
            'cancelled_at' => now(),
            'cancellation_reason' => 'Flight moved, trip no longer needed.',
        ];

        $bookings = [
            [$room, 'Budget review with Finance', $monday->copy()->addDays(3)->setTime(9, 0), $monday->copy()->addDays(3)->setTime(11, 0), BookingStatus::Pending, []],
            [$room, 'Vendor demo, archive scanners', $monday->copy()->addDays(4)->setTime(14, 0), $monday->copy()->addDays(4)->setTime(16, 0), BookingStatus::Approved, $reviewed],
            [$room, 'All hands rehearsal', $monday->copy()->addDays(4)->setTime(14, 30), $monday->copy()->addDays(4)->setTime(15, 30), BookingStatus::Rejected, [...$reviewed, 'rejection_reason' => 'Room already approved for the vendor demo in that slot.']],
            [$van, 'Site visit, Cinematheque Manila', $monday->copy()->addDays(5)->setTime(8, 0), $monday->copy()->addDays(5)->setTime(12, 0), BookingStatus::Approved, $reviewed],
            [$van, 'Airport pickup, festival jury', $monday->copy()->addDays(6)->setTime(6, 0), $monday->copy()->addDays(6)->setTime(9, 0), BookingStatus::Cancelled, $cancelled],
        ];

        foreach ($bookings as [$resource, $purpose, $start, $end, $status, $extra]) {
            $this->createBooking($staff, $resource, $purpose, $start, $end, $status, $extra);
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function createBooking(User $staff, Resource $resource, string $purpose, Carbon $start, Carbon $end, BookingStatus $status, array $extra): void
    {
        $exists = Booking::query()
            ->where('resource_id', $resource->id)
            ->where('purpose', $purpose)
            ->exists();

        if ($exists) {
            return;
        }

        $event = Event::query()->create([
            'calendar_id' => $resource->calendar_id,
            'title' => $purpose,
            'starts_at' => $start->copy()->utc(),
            'ends_at' => $end->copy()->utc(),
            'created_by' => $staff->id,
            'status' => $status === BookingStatus::Cancelled ? 'cancelled' : 'confirmed',
        ]);

        Booking::query()->create([
            'resource_id' => $resource->id,
            'event_id' => $event->id,
            'requested_by' => $staff->id,
            'status' => $status,
            'starts_at' => $start->copy()->utc(),
            'ends_at' => $end->copy()->utc(),
            'purpose' => $purpose,
            'attendee_count' => 8,
            ...$extra,
        ]);
    }
}
