<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionSeeder extends Seeder
{
    /**
     * Seed the application's roles and permissions.
     */
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            'view dashboard',
            'manage machines',
            'manage parts',
            'manage rest',
            'manage patterns',
            'view andon',
            'view stock part all',
            'manage planning',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        // Superadmin bypasses every permission check via the Gate::before hook
        // in AppServiceProvider, so it doesn't need permissions synced to it.
        Role::firstOrCreate(['name' => 'superadmin']);

        $admin = Role::firstOrCreate(['name' => 'admin']);
        $admin->syncPermissions($permissions);

        $staff = Role::firstOrCreate(['name' => 'staff']);
        $staff->syncPermissions(['view dashboard', 'view andon', 'view stock part all']);
    }
}
