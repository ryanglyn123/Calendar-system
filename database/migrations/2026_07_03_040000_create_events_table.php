<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calendar_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_type_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('location')->nullable();
            $table->string('meeting_url')->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->boolean('all_day')->default(false);
            $table->string('timezone', 64)->default('Asia/Manila');
            $table->string('status', 20)->default('confirmed');
            $table->char('color', 7)->nullable();
            $table->string('visibility', 10)->default('default');
            $table->text('rrule')->nullable();
            $table->dateTime('recurrence_until')->nullable();
            $table->foreignId('recurring_parent_id')->nullable()->constrained('events')->cascadeOnDelete();
            $table->dateTime('original_starts_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['calendar_id', 'starts_at', 'ends_at']);
            $table->index(['calendar_id', 'recurrence_until']);
            $table->unique(['recurring_parent_id', 'original_starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
