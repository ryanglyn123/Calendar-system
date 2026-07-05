<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Candidate search filters on status and streams in name order
            // (LIMIT 20), so this composite index serves both without a scan.
            $table->index(['status', 'name'], 'users_status_name_index');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_status_name_index');
        });
    }
};
