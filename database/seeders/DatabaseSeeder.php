<?php

namespace Database\Seeders;

use App\Enums\UserStatus;
use App\Models\Division;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database with production-safe base data.
     */
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            DivisionSeeder::class,
        ]);

        $superAdmin = User::query()->firstOrCreate(
            ['email' => 'admin@fdcp.gov.ph'],
            [
                'name' => 'FDCP Super Admin',
                'password' => 'password',
                'status' => UserStatus::Active,
                'email_verified_at' => now(),
                'approved_at' => now(),
                'division_id' => Division::query()->where('acronym', 'MIS')->value('id'),
            ],
        );

        $superAdmin->syncRoles(['super_admin']);
    }
}
