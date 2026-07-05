<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Chat moves from one-room-per-division to a conversations model: division
 * rooms (type=division), custom cross-division group chats (type=custom),
 * and event-linked chats (custom with event_id). Messages and reads re-key
 * from division_id to conversation_id; division_id stays as a nullable
 * legacy column so the change is reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->string('type', 16)->index();          // division | custom
            $table->foreignId('division_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name')->nullable();            // custom chats; division rooms derive it
            $table->string('photo_path')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('conversation_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['conversation_id', 'user_id']);
        });

        // A division room for every existing division, carrying its photo.
        foreach (DB::table('divisions')->get(['id', 'chat_photo_path']) as $division) {
            DB::table('conversations')->insert([
                'type' => 'division',
                'division_id' => $division->id,
                'photo_path' => $division->chat_photo_path,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('chat_messages', function (Blueprint $table) {
            $table->foreignId('conversation_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('division_id')->nullable()->change();
            $table->index(['conversation_id', 'id']);
        });

        Schema::table('chat_reads', function (Blueprint $table) {
            $table->foreignId('conversation_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('division_id')->nullable()->change();
            $table->unique(['conversation_id', 'user_id']);
        });

        // Correlated subquery form works on both MySQL and SQLite.
        foreach (['chat_messages', 'chat_reads'] as $table) {
            DB::statement(
                "UPDATE {$table} SET conversation_id = ("
                .'SELECT c.id FROM conversations c '
                ."WHERE c.division_id = {$table}.division_id AND c.type = 'division'"
                .') WHERE division_id IS NOT NULL'
            );
        }
    }

    public function down(): void
    {
        Schema::table('chat_reads', function (Blueprint $table) {
            $table->dropUnique(['conversation_id', 'user_id']);
            $table->dropConstrainedForeignId('conversation_id');
        });

        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropIndex(['conversation_id', 'id']);
            $table->dropConstrainedForeignId('conversation_id');
        });

        Schema::dropIfExists('conversation_user');
        Schema::dropIfExists('conversations');
    }
};
