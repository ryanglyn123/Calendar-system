<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_user_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('calendar_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_visible')->nullable();
            $table->char('color_override', 7)->nullable();
            $table->unsignedSmallInteger('sort_order')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'calendar_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_user_preferences');
    }
};
