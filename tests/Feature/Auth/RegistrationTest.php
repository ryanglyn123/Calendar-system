<?php

namespace Tests\Feature\Auth;

use App\Models\Division;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Features;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::registration());
    }

    public function test_registration_screen_can_be_rendered()
    {
        $response = $this->get(route('register'));

        $response->assertOk();
    }

    public function test_new_users_can_register()
    {
        $division = Division::factory()->create();

        $response = $this->post(route('register.store'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'division_id' => $division->id,
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));

        $this->assertSame(
            $division->id,
            User::query()->where('email', 'test@example.com')->firstOrFail()->division_id,
        );
    }

    public function test_registration_requires_a_division()
    {
        $this->post(route('register.store'), [
            'name' => 'No Division',
            'email' => 'nodivision@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasErrors('division_id');
    }

    public function test_inactive_divisions_cannot_be_chosen()
    {
        $division = Division::factory()->create(['is_active' => false]);

        $this->post(route('register.store'), [
            'name' => 'Inactive Division',
            'email' => 'inactive@example.com',
            'division_id' => $division->id,
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasErrors('division_id');
    }
}
