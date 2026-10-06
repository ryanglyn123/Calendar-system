<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One Google account per user. Tokens are encrypted at rest (model
        // casts) and never leave the server.
        Schema::create('google_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            // Google's stable account id ("sub"); one Google account can be
            // linked to only one app user.
            $table->string('google_sub')->unique();
            $table->string('email');
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->json('scopes');
            // active | reauth_required
            $table->string('status', 20)->default('active');
            $table->boolean('push_bookings')->default(true);
            // The "FDCP Bookings" secondary calendar this app created.
            $table->string('bookings_calendar_id')->nullable();
            $table->text('calendar_list_sync_token')->nullable();
            $table->string('channel_id', 64)->nullable()->unique();
            $table->string('channel_resource_id')->nullable();
            $table->string('channel_token', 64)->nullable();
            $table->timestamp('channel_expires_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });

        // Every calendar on the account's Google calendar list. Selected
        // ones get a mirror row in `calendars` (type google) that the app's
        // calendar views read like any other calendar.
        Schema::create('google_calendars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('google_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('calendar_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('google_id');
            $table->string('summary');
            $table->string('time_zone', 64)->nullable();
            // freeBusyReader | reader | writerWithoutPrivateAccess | writer | owner
            $table->string('access_role', 32);
            $table->string('background_color', 7)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_holiday')->default(false);
            // The app-created bookings calendar: watched for edits made in
            // Google, never mirrored (the bookings already show locally).
            $table->boolean('is_bookings')->default(false);
            $table->boolean('sync_enabled')->default(false);
            $table->text('sync_token')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            // idle | error
            $table->string('sync_state', 20)->default('idle');
            $table->string('sync_error', 40)->nullable();
            $table->timestamp('sync_error_at')->nullable();
            $table->string('channel_id', 64)->nullable()->unique();
            $table->string('channel_resource_id')->nullable();
            $table->string('channel_token', 64)->nullable();
            $table->timestamp('channel_expires_at')->nullable();
            $table->timestamps();

            $table->unique(['google_account_id', 'google_id']);
        });

        // Google-side identity of each mirrored event, so an update or
        // deletion finds its row without matching on titles.
        Schema::create('google_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('google_calendar_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('google_event_id');
            $table->string('recurring_event_id')->nullable();
            $table->string('etag')->nullable();
            // default | birthday | focusTime | fromGmail | outOfOffice | workingLocation
            $table->string('event_type', 32)->default('default');
            $table->boolean('busy')->default(true);
            $table->text('html_link')->nullable();
            // Display-only details: organizer, attendees, conference.
            $table->json('details')->nullable();
            $table->timestamps();

            $table->unique(['google_calendar_id', 'google_event_id']);
            $table->index(['google_calendar_id', 'recurring_event_id']);
        });

        // An approved booking copied into its requester's Google calendar.
        Schema::create('google_booking_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('google_account_id')->constrained()->cascadeOnDelete();
            $table->string('google_event_id');
            $table->string('etag')->nullable();
            $table->text('html_link')->nullable();
            // synced | edited_in_google | removed_in_google | failed
            $table->string('state', 24)->default('synced');
            $table->string('error', 40)->nullable();
            $table->timestamp('pushed_at')->nullable();
            $table->timestamps();

            $table->unique(['booking_id', 'google_account_id']);
            $table->index(['google_account_id', 'google_event_id']);
        });

        // Holidays imported from a Google public holiday calendar. An admin
        // edit marks the row overridden so later syncs leave it alone.
        Schema::table('holidays', function (Blueprint $table) {
            $table->string('source', 20)->default('manual')->after('blocks_bookings');
            $table->string('google_calendar_id')->nullable()->after('source');
            $table->string('google_event_id')->nullable()->after('google_calendar_id');
            $table->boolean('overridden')->default(false)->after('google_event_id');
            // Deleting an imported holiday soft-deletes it so the next import skips it.
            $table->softDeletes();

            $table->unique(['google_calendar_id', 'google_event_id']);
        });
    }

    public function down(): void
    {
        Schema::table('holidays', function (Blueprint $table) {
            $table->dropUnique(['google_calendar_id', 'google_event_id']);
            $table->dropSoftDeletes();
            $table->dropColumn(['source', 'google_calendar_id', 'google_event_id', 'overridden']);
        });

        Schema::dropIfExists('google_booking_events');
        Schema::dropIfExists('google_events');
        Schema::dropIfExists('google_calendars');
        Schema::dropIfExists('google_accounts');
    }
};
