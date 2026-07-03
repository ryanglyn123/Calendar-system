<?php

namespace App\Support;

use Inertia\Inertia;

/**
 * Flash a toast for the next Inertia response. The starter kit's
 * flashToast listener renders `flash.toast` through vue-sonner.
 */
class Toast
{
    public static function success(string $message): void
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);
    }

    public static function error(string $message): void
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => $message]);
    }

    public static function info(string $message): void
    {
        Inertia::flash('toast', ['type' => 'info', 'message' => $message]);
    }

    public static function warning(string $message): void
    {
        Inertia::flash('toast', ['type' => 'warning', 'message' => $message]);
    }
}
