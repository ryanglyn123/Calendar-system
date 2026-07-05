<?php

use App\Models\User;

test('probe audit page props', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    activity()->causedBy($admin)->event('updated')->log('Changed a setting');

    $response = $this->actingAs($admin)->get(route('admin.audit.index'));
    $response->assertOk();

    $props = $response->inertiaProps();
    dump(json_encode($props['activities'], JSON_PRETTY_PRINT));
});
