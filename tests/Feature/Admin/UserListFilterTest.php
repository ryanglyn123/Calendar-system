<?php

use App\Models\Division;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->admin = User::factory()->create(['name' => 'Zed Admin', 'created_at' => now()->subYear()]);
    $this->admin->assignRole('super_admin');
});

function listedUserNames(array $query = []): array
{
    $names = [];

    test()->actingAs(test()->admin)
        ->get(route('admin.users.index', $query))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$names) {
            $names = collect($page->toArray()['props']['users']['data'])->pluck('name')->all();
        });

    return $names;
}

test('the users list filters by role', function () {
    User::factory()->create(['name' => 'Dana Division Admin'])->assignRole('division_admin');
    User::factory()->create(['name' => 'Sam Staff'])->assignRole('staff');

    expect(listedUserNames(['role' => 'division_admin']))->toBe(['Dana Division Admin'])
        ->and(listedUserNames(['role' => 'staff']))->toBe(['Sam Staff']);

    $this->actingAs($this->admin)
        ->get(route('admin.users.index', ['role' => 'nope']))
        ->assertSessionHasErrors('role');
});

test('the users list sorts by name, newest and division', function () {
    $film = Division::factory()->create(['name' => 'Film Archive']);
    $accounting = Division::factory()->create(['name' => 'Accounting']);

    User::factory()->create(['name' => 'Bea', 'division_id' => $film->id, 'created_at' => now()->subDays(3)]);
    User::factory()->create(['name' => 'Cal', 'division_id' => $accounting->id, 'created_at' => now()->subDays(1)]);
    User::factory()->create(['name' => 'Abe', 'division_id' => $film->id, 'created_at' => now()->subDays(2)]);

    expect(listedUserNames())->toBe(['Abe', 'Bea', 'Cal', 'Zed Admin'])
        ->and(listedUserNames(['sort' => 'name']))->toBe(['Abe', 'Bea', 'Cal', 'Zed Admin'])
        ->and(listedUserNames(['sort' => 'newest']))->toBe(['Cal', 'Abe', 'Bea', 'Zed Admin'])
        ->and(listedUserNames(['sort' => 'division']))->toBe(['Cal', 'Abe', 'Bea', 'Zed Admin']);

    $this->actingAs($this->admin)
        ->get(route('admin.users.index', ['sort' => 'random']))
        ->assertSessionHasErrors('sort');
});

test('the users list echoes every filter back', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.users.index', ['search' => 'a', 'status' => 'active', 'role' => 'staff', 'sort' => 'newest']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters', ['search' => 'a', 'status' => 'active', 'role' => 'staff', 'sort' => 'newest']));
});
