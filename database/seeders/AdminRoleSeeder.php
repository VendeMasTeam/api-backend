<?php

namespace Database\Seeders;

use App\Models\AdminRole;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class AdminRoleSeeder extends Seeder
{
    public function run(): void
    {
        $superAdminRole = AdminRole::query()->firstOrCreate(
            ['key' => 'superadmin'],
            [
                'uid' => (string) Str::uuid(),
                'name' => 'Superadmin',
                'description' => 'Acceso total a la plataforma',
                'is_system' => true,
            ]
        );

        if (empty($superAdminRole->uid)) {
            $superAdminRole->uid = (string) Str::uuid();
            $superAdminRole->save();
        }

        $superAdminRole->permissions()->sync(Permission::all()->pluck('id'));

        $supportRole = AdminRole::query()->firstOrCreate(
            ['key' => 'support'],
            [
                'uid' => (string) Str::uuid(),
                'name' => 'Soporte',
                'description' => 'Soporte técnico: gestión de tenants y telemetría',
                'is_system' => true,
            ]
        );

        if (empty($supportRole->uid)) {
            $supportRole->uid = (string) Str::uuid();
            $supportRole->save();
        }

        $supportPermissions = Permission::query()->whereIn('key', [
            'admin.dashboard.read',
            'admin.tenants.manage',
            'admin.tenants.support',
            'admin.telemetry.read',
            'admin.alerts.manage',
        ])->pluck('id');

        $supportRole->permissions()->sync($supportPermissions);

        // Asignar rol Superadmin al superadmin principal
        $mainAdmin = User::query()
            ->withoutGlobalScopes()
            ->where('email', config('app.super_admin_email', 'admin@vende-mas.com.co'))
            ->first();

        if ($mainAdmin && $mainAdmin->adminRoles()->doesntExist()) {
            $mainAdmin->adminRoles()->attach($superAdminRole->id);
        }
    }
}
