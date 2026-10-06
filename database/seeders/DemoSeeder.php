<?php

namespace Database\Seeders;

use App\Enums\BookingStatus;
use App\Enums\EditRequestStatus;
use App\Enums\UserStatus;
use App\Models\Announcement;
use App\Models\Booking;
use App\Models\BookingEditRequest;
use App\Models\Division;
use App\Models\Event;
use App\Models\Invitation;
use App\Models\Resource;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Full local demo dataset: users for every role, sample events, and
 * bookings in every status. Never run in production.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = [
            ['Dina Reyes', 'division.admin@fdcp.gov.ph', 'division_admin', 'FESD'],
            ['Marco Santos', 'staff@fdcp.gov.ph', 'staff', 'ITU'],
            ['Lea Cruz', 'room.approver@fdcp.gov.ph', 'board_room_approver', 'AFD'],
            ['Paolo Garcia', 'vehicle.approver@fdcp.gov.ph', 'vehicle_approver', 'AFD'],
            ['Andrea Villanueva', 'andrea.villanueva@fdcp.gov.ph', 'staff', 'FESD'],
            ['Jomar Dizon', 'jomar.dizon@fdcp.gov.ph', 'staff', 'PFA'],
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

        User::query()->firstOrCreate(
            ['email' => 'carla.mendoza@fdcp.gov.ph'],
            [
                'name' => 'Carla Mendoza',
                'password' => 'password',
                'status' => UserStatus::Pending,
                'email_verified_at' => now(),
                'timezone' => 'Asia/Manila',
                'division_id' => Division::query()->where('acronym', 'COMMS')->value('id'),
            ],
        );

        $this->call(DemoEventSeeder::class);

        $this->seedBookings();
        $this->seedDesk();
    }

    private function seedBookings(): void
    {
        $staff = User::query()->where('email', 'staff@fdcp.gov.ph')->firstOrFail();
        $roomApprover = User::query()->where('email', 'room.approver@fdcp.gov.ph')->firstOrFail();
        $room = Resource::query()->where('name', '3rd Floor Board Room (Main)')->firstOrFail();
        $van = Resource::query()->where('name', 'Toyota Hiace Van 1')->firstOrFail();

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
     * Two weeks of room and vehicle traffic around today, so the admin desk
     * has a queue, a clash, a change request and busy days to show.
     */
    private function seedDesk(): void
    {
        $people = User::query()->whereIn('email', [
            'staff@fdcp.gov.ph', 'division.admin@fdcp.gov.ph',
            'andrea.villanueva@fdcp.gov.ph', 'jomar.dizon@fdcp.gov.ph',
        ])->get()->keyBy('email');
        $marco = $people['staff@fdcp.gov.ph'];
        $dina = $people['division.admin@fdcp.gov.ph'];
        $andrea = $people['andrea.villanueva@fdcp.gov.ph'];
        $jomar = $people['jomar.dizon@fdcp.gov.ph'];

        $roomApprover = User::query()->where('email', 'room.approver@fdcp.gov.ph')->firstOrFail();
        $vehicleApprover = User::query()->where('email', 'vehicle.approver@fdcp.gov.ph')->firstOrFail();

        $resource = fn (string $name) => Resource::query()->with('type')->where('name', $name)->firstOrFail();
        $main = $resource('3rd Floor Board Room (Main)');
        $first = $resource('1st Floor Board Room');
        $occeo = $resource('Occeo Board Room');
        $mezzanine = $resource('Mezzanine Board Room');
        $van1 = $resource('Toyota Hiace Van 1');
        $van2 = $resource('Toyota Hiace Van 2');
        $innova = $resource('Toyota Innova');
        $tamaraw = $resource('Toyota Tamaraw');
        $fortuner = $resource('Toyota Fortuner');

        $today = Carbon::now('Asia/Manila')->startOfDay();
        $at = fn (int $day, int $hour, int $minute = 0) => $today->copy()->addDays($day)->setTime($hour, $minute);

        $approved = BookingStatus::Approved;
        $pending = BookingStatus::Pending;

        // [requester, resource, purpose, start, end, status, attendees]
        $bookings = [
            [$dina, $main, 'Weekly management committee', $at(0, 9), $at(0, 10, 30), $approved, 12],
            [$andrea, $main, 'Cinema Rehabilitation Program briefing', $at(0, 13), $at(0, 15), $approved, 18],
            [$marco, $first, 'Film Industry Conference planning', $at(0, 10), $at(0, 12), $approved, 8],
            [$jomar, $first, 'Regional Cinematheque coordination call', $at(0, 14), $at(0, 16), $pending, 6],
            [$andrea, $occeo, 'Interview panel, film lab applicants', $at(0, 15), $at(0, 17), $approved, 5],
            [$jomar, $van1, 'Location scouting, Intramuros', $at(0, 7), $at(0, 12), $approved, 4],
            [$marco, $innova, 'Document delivery to DTI Makati', $at(0, 13, 30), $at(0, 16), $pending, 2],

            [$dina, $main, 'Screening committee, Sine Isko entries', $at(1, 9), $at(1, 12), $approved, 10],
            [$marco, $main, 'Budget alignment with DBM', $at(1, 10), $at(1, 11), $pending, 6],
            [$andrea, $mezzanine, 'Press briefing, Pista ng Pelikulang Pilipino', $at(1, 14), $at(1, 17), $pending, 30],
            [$dina, $fortuner, 'Airport pickup, festival delegates', $at(1, 6), $at(1, 10), $approved, 5],

            [$jomar, $occeo, 'Orientation for new archive hires', $at(2, 9), $at(2, 11), $pending, 14],
            [$jomar, $tamaraw, 'Equipment transfer to the film archive', $at(2, 8), $at(2, 17), $approved, 3],

            [$marco, $first, 'Archive digitization progress update', $at(3, 13), $at(3, 15), $approved, 9],
            [$andrea, $van1, 'Festival venue inspection, Quezon City', $at(3, 8), $at(3, 18), $approved, 6],

            [$dina, $main, 'Strategic planning workshop', $at(6, 9), $at(6, 17), $approved, 25],
            [$andrea, $first, 'Grants evaluation panel', $at(6, 9), $at(6, 12), $approved, 7],
            [$marco, $mezzanine, 'IT systems town hall', $at(6, 13), $at(6, 17), $approved, 40],
            [$jomar, $innova, 'Film reel pickup, Cinematheque Manila', $at(6, 8), $at(6, 12), $pending, 2],

            [$andrea, $occeo, 'Legal review of co-production treaty', $at(8, 10), $at(8, 12), $approved, 4],
            [$dina, $van2, 'Provincial screening tour, Batangas', $at(9, 7), $at(9, 15), $pending, 8],
            [$marco, $main, 'Quarterly performance review', $at(12, 14), $at(12, 16), $approved, 15],
        ];

        foreach ($bookings as [$requester, $room, $purpose, $start, $end, $status, $attendees]) {
            $reviewer = $room->type->slug === 'vehicle' ? $vehicleApprover : $roomApprover;

            $booking = $this->createBooking(
                $requester, $room, $purpose, $start, $end, $status,
                $status === $approved ? ['reviewed_by' => $reviewer->id, 'reviewed_at' => now()] : [],
                $attendees,
            );

            if ($booking === null) {
                continue;
            }

            activity()->causedBy($requester)->performedOn($booking)->event('requested')
                ->log("Requested {$room->name}");

            if ($status === $approved) {
                activity()->causedBy($reviewer)->performedOn($booking)->event('approved')
                    ->log("Approved booking #{$booking->id}");
            }
        }

        $moving = Booking::query()->where('purpose', 'Archive digitization progress update')->first();

        if ($moving !== null && ! $moving->editRequests()->exists()) {
            BookingEditRequest::query()->create([
                'booking_id' => $moving->id,
                'requested_by' => $marco->id,
                'status' => EditRequestStatus::Pending,
                'proposed_starts_at' => $at(3, 15)->utc(),
                'proposed_ends_at' => $at(3, 17)->utc(),
                'note' => 'Our speaker from the archive lands at 1 PM.',
            ]);
        }

        $admin = User::query()->where('email', 'admin@fdcp.gov.ph')->first();
        $staffRole = Role::query()->where('name', 'staff')->value('id');

        foreach ([['r.bautista@fdcp.gov.ph', 2], ['k.lim@fdcp.gov.ph', 6]] as [$email, $days]) {
            Invitation::query()->firstOrCreate(['email' => $email], [
                'token' => Str::random(64),
                'division_id' => Division::query()->where('acronym', 'FESD')->value('id'),
                'role_id' => $staffRole,
                'invited_by' => $admin?->id,
                'expires_at' => now()->addDays($days),
            ]);
        }

        Announcement::query()->firstOrCreate(['title' => 'Elevator maintenance on the 3rd floor'], [
            'body' => 'Use the east stairs to reach the main board room until maintenance ends.',
            'level' => 'warning',
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDays(3),
            'is_dismissible' => true,
            'is_active' => true,
            'created_by' => $admin?->id,
        ]);

        Announcement::query()->firstOrCreate(['title' => 'Year-end property inventory'], [
            'body' => 'Property custodians will visit each division to tag equipment.',
            'level' => 'info',
            'starts_at' => now()->addDays(10),
            'ends_at' => now()->addDays(15),
            'is_dismissible' => true,
            'is_active' => true,
            'created_by' => $admin?->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function createBooking(User $staff, Resource $resource, string $purpose, Carbon $start, Carbon $end, BookingStatus $status, array $extra, int $attendees = 8): ?Booking
    {
        $exists = Booking::query()
            ->where('resource_id', $resource->id)
            ->where('purpose', $purpose)
            ->exists();

        if ($exists) {
            return null;
        }

        $event = Event::query()->create([
            'calendar_id' => $resource->calendar_id,
            'title' => $purpose,
            'starts_at' => $start->copy()->utc(),
            'ends_at' => $end->copy()->utc(),
            'created_by' => $staff->id,
            'status' => $status === BookingStatus::Cancelled ? 'cancelled' : 'confirmed',
        ]);

        return Booking::query()->create([
            'resource_id' => $resource->id,
            'event_id' => $event->id,
            'requested_by' => $staff->id,
            'status' => $status,
            'starts_at' => $start->copy()->utc(),
            'ends_at' => $end->copy()->utc(),
            'purpose' => $purpose,
            'attendee_count' => $attendees,
            ...$extra,
        ]);
    }
}
