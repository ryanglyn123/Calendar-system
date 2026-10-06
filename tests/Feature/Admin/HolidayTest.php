<?php

use App\Models\Holiday;
use App\Models\User;
use App\Settings\GeneralSettings;
use Inertia\Testing\AssertableInertia as Assert;

test('the holidays page lays out its year grid on the office week start', function () {
    $settings = app(GeneralSettings::class);
    $settings->week_starts_on = 0;
    $settings->save();

    Holiday::query()->create([
        'name' => 'Christmas Day',
        'date' => '2026-12-25',
        'recurs_annually' => true,
        'type' => 'regular',
        'blocks_bookings' => true,
    ]);

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $this->actingAs($admin)
        ->get(route('admin.holidays.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/holidays/Index')
            ->where('weekStartsOn', 0)
            ->where('holidays.0.date', '2026-12-25')
            ->where('holidays.0.recurs_annually', true));
});
