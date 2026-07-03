<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resource_id')->constrained()->restrictOnDelete();
            $table->foreignId('event_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->string('status', 15)->default('pending');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->text('purpose')->nullable();
            $table->unsignedSmallInteger('attendee_count')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // The conflict-detection index: one range scan answers overlaps.
            $table->index(['resource_id', 'status', 'starts_at', 'ends_at'], 'bookings_conflict_idx');
            $table->index(['requested_by', 'status']);
        });

        Schema::create('booking_edit_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->string('status', 15)->default('pending');
            $table->dateTime('proposed_starts_at')->nullable();
            $table->dateTime('proposed_ends_at')->nullable();
            $table->text('proposed_purpose')->nullable();
            $table->unsignedSmallInteger('proposed_attendee_count')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->index(['booking_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_edit_requests');
        Schema::dropIfExists('bookings');
    }
};
