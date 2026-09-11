<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class PlatformSuperadminSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('SUPERADMIN_EMAIL');

        if (!$email) {
            return;
        }

        $name = env('SUPERADMIN_NAME', 'Platform Superadmin');
        $password = env('SUPERADMIN_PASSWORD');

        if (!$password || strlen($password) < 12) {
            throw new \RuntimeException('SUPERADMIN_PASSWORD es obligatorio y debe tener al menos 12 caracteres.');
        }

        $user = User::withoutGlobalScopes()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make($password),
                'tenant_id' => null,
                'is_platform_admin' => true,
            ]
        );

        Permission::query()
            ->whereIn('key', [
                'plans.manage',
                'admin.dashboard.read',
                'admin.tenants.manage',
                'admin.tenants.purge',
                'admin.billing.manage',
                'admin.telemetry.read',
                'admin.alerts.manage',
                'users.manage',
            ])
            ->get()
            ->each(fn (Permission $permission) => $user->givePermissionTo($permission));
    }
}
