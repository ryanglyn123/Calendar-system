<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sign-in moves from Google to FDCP SSO: the Google subject id goes,
     * the SSO subject id (fdcp-accounts `sub`) links each account instead.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['google_id']);
            $table->dropColumn('google_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('sso_sub', 36)->nullable()->unique()->after('remember_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['sso_sub']);
            $table->dropColumn('sso_sub');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('google_id')->nullable()->after('remember_token')->index();
        });
    }
};
