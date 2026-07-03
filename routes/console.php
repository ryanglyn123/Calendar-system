<?php

use App\Jobs\PruneExpiredInvitations;
use App\Jobs\SendDueReminders;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new SendDueReminders)->everyMinute();
Schedule::job(new PruneExpiredInvitations)->daily();
