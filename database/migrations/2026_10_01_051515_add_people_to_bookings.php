<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The named people on a booking (attendees of a room, passengers of a
 * vehicle), in the order the requester listed them; attendee_count stays as
 * their number. Change requests can propose a new list the same way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->json('people')->nullable()->after('attendee_count');
        });

        Schema::table('booking_edit_requests', function (Blueprint $table) {
            $table->json('proposed_people')->nullable()->after('proposed_attendee_count');
        });
    }

    public function down(): void
    {
        Schema::table('booking_edit_requests', function (Blueprint $table) {
            $table->dropColumn('proposed_people');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('people');
        });
    }
};
