<?php

namespace App\Notifications\Concerns;

/**
 * Booking notices go out the moment the booking changes: the in-app copy
 * (the bell, the top-bar count, the toast) is written in the same request,
 * so it never waits on, or depends on, a queue worker. Mail stays queued.
 */
trait WritesInAppCopyNow
{
    /**
     * @return array<string, string>
     */
    public function viaConnections(): array
    {
        return ['database' => 'sync'];
    }
}
