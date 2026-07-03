<?php

namespace App\Jobs;

use App\Models\Invitation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PruneExpiredInvitations implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        Invitation::query()
            ->whereNull('accepted_at')
            ->where('expires_at', '<', now()->subDays(30))
            ->delete();
    }
}
