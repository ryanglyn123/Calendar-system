<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    /**
     * Permission names use "domain.action". Policies check permissions,
     * never role names; super_admin bypasses via Gate::before.
     *
     * @var array<string, string>
     */
    public const PERMISSIONS = [
        'admin.access' => 'Access the admin panel',
        'users.manage' => 'Create, edit, suspend and delete users',
        'users.approve' => 'Approve or reject pending registrations',
        'divisions.manage' => 'Manage divisions',
        'roles.manage' => 'Manage roles and permissions',
        'calendars.manage' => 'Manage organization and division calendars',
        'events.create-org' => 'Create events on organization calendars',
        'event-types.manage' => 'Manage event types',
        'resource-types.manage' => 'Manage resource types and booking rules',
        'resources.manage' => 'Manage bookable resources',
        'bookings.approve' => 'Approve or reject bookings',
        'settings.manage' => 'Manage system settings',
        'announcements.manage' => 'Manage announcements',
        'holidays.manage' => 'Manage holidays',
        'working-hours.manage' => 'Manage working hours',
        'audit.view' => 'View the audit log',
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (array_keys(self::PERMISSIONS) as $name) {
            Permission::findOrCreate($name);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $superAdmin = Role::findOrCreate('super_admin');
        $superAdmin->update(['is_system' => true]);

        $divisionAdmin = Role::findOrCreate('division_admin');
        $divisionAdmin->update(['is_system' => true]);
        $divisionAdmin->syncPermissions([
            'admin.access',
            'users.approve',
            'events.create-org',
            'calendars.manage',
        ]);

        $staff = Role::findOrCreate('staff');
        $staff->update(['is_system' => true]);

        $boardRoomApprover = Role::findOrCreate('board_room_approver');
        $boardRoomApprover->update(['is_system' => false]);
        $boardRoomApprover->syncPermissions(['admin.access', 'bookings.approve']);

        $vehicleApprover = Role::findOrCreate('vehicle_approver');
        $vehicleApprover->update(['is_system' => false]);
        $vehicleApprover->syncPermissions(['admin.access', 'bookings.approve']);
    }
}
