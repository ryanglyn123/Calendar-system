<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('minutes_before');
            $table->string('channel', 10)->default('database');
            $table->timestamps();

            $table->unique(['event_id', 'user_id', 'minutes_before', 'channel'], 'event_reminders_unique');
        });

        Schema::create('reminder_dispatches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_reminder_id')->constrained()->cascadeOnDelete();
            $table->dateTime('occurrence_starts_at');
            $table->timestamp('sent_at');

            $table->unique(['event_reminder_id', 'occurrence_starts_at'], 'reminder_dispatches_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminder_dispatches');
        Schema::dropIfExists('event_reminders');
    }
};
