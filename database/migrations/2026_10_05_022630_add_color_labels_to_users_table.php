<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A person's own names for event colors ("Tomato" = "Urgent"), which
     * colors their color menu shows, and any custom colors they added.
     * Null until they first edit it: the menu then shows Google's palette.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('color_labels')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('color_labels');
        });
    }
};
