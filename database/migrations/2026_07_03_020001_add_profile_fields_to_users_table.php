<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('division_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->string('status', 20)->default('pending')->after('email')->index();
            $table->timestamp('approved_at')->nullable()->after('status');
            $table->foreignId('approved_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            $table->string('google_id')->nullable()->after('remember_token')->index();
            $table->string('timezone', 64)->default('Asia/Manila')->after('google_id');
            $table->string('avatar_path')->nullable()->after('timezone');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('division_id');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['status', 'approved_at', 'google_id', 'timezone', 'avatar_path', 'deleted_at']);
        });
    }
};
