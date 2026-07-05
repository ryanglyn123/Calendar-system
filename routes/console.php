<?php

use App\Jobs\PruneExpiredInvitations;
use App\Jobs\SendDueReminders;
use Illuminate\Foundation\DevCommands;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Pail needs the pcntl extension, which Windows PHP does not have; without
// this guard its crash takes the whole `composer dev` stack down with it.
// Logs are still written to storage/logs/laravel.log.
if (! function_exists('pcntl_fork')) {
    DevCommands::except('logs');
}

// The chat's websocket server rides along with `composer dev`.
DevCommands::artisan('reverb:start', 'reverb');

Schedule::job(new SendDueReminders)->everyMinute();
Schedule::job(new PruneExpiredInvitations)->daily();
