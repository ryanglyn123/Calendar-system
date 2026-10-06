<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    /**
     * The chat feature was removed. Drops its tables and uploaded photos on
     * databases that still have them; a no-op on fresh installs.
     */
    public function up(): void
    {
        foreach ([
            'chat_poll_votes',
            'chat_poll_options',
            'chat_polls',
            'chat_reactions',
            'chat_attachments',
            'chat_reads',
            'chat_messages',
            'conversation_user',
            'conversations',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        if (Schema::hasColumn('divisions', 'chat_photo_path')) {
            Schema::table('divisions', function (Blueprint $table) {
                $table->dropColumn('chat_photo_path');
            });
        }

        Storage::disk('local')->deleteDirectory('chat');
    }

    public function down(): void
    {
        // Chat data is gone for good; nothing to restore.
    }
};
