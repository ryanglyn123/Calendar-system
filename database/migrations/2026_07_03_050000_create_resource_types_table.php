<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_types', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->string('icon', 50)->nullable();
            $table->string('description')->nullable();
            $table->foreignId('approver_role_id')->constrained('roles')->restrictOnDelete();
            $table->boolean('requires_approval')->default(true);
            $table->unsignedInteger('min_lead_time_minutes')->default(0);
            $table->unsignedInteger('max_duration_minutes')->nullable();
            $table->unsignedSmallInteger('max_advance_days')->nullable();
            $table->unsignedSmallInteger('max_active_bookings_per_user')->nullable();
            $table->boolean('enforce_working_hours')->default(false);
            $table->boolean('block_holidays')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_types');
    }
};
