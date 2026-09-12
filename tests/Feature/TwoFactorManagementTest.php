<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TwoFactorManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_does_not_force_two_factor_setup_when_user_has_not_enabled_it(): void
    {
        $tenant = $this->tenant('Tenant 2FA Login');
        $user = $this->tenantUser($tenant, 'login-no-2fa@example.test');

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'secret123',
        ])
            ->assertOk()
            ->assertJsonPath('data.user.two_factor_enabled', false)
            ->assertJsonMissingPath('data.requires_two_factor_setup')
            ->assertJsonStructure(['data' => ['token', 'user']]);
    }

    public function test_legacy_login_alias_matches_api_login(): void
    {
        $tenant = $this->tenant('Tenant Legacy Login');
        $user = $this->tenantUser($tenant, 'legacy-login@example.test');

        $this->postJson('/login', [
            'email' => $user->email,
            'password' => 'secret123',
        ])
            ->assertOk()
            ->assertJsonPath('data.user.email', $user->email)
            ->assertJsonStructure(['data' => ['token', 'user']]);
    }

    public function test_login_requires_code_when_two_factor_is_enabled(): void
    {
        $tenant = $this->tenant('Tenant 2FA Enabled');
        $secret = app(TwoFactorService::class)->generateSecret();
        $user = $this->tenantUser($tenant, 'login-2fa@example.test', [
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => now(),
        ]);

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'secret123',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('two_factor_code');

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'secret123',
            'two_factor_code' => app(TwoFactorService::class)->currentCode($secret),
        ])
            ->assertOk()
            ->assertJsonPath('data.user.two_factor_enabled', true)
            ->assertJsonStructure(['data' => ['token', 'user']]);
    }

    public function test_user_can_disable_own_two_factor_with_password(): void
    {
        $tenant = $this->tenant('Tenant 2FA Disable');
        $user = $this->tenantUser($tenant, 'disable-own-2fa@example.test', [
            'two_factor_secret' => app(TwoFactorService::class)->generateSecret(),
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => ['hashed-code'],
        ]);

        Sanctum::actingAs($user, ['access:full', 'tenant:' . $tenant->uid]);

        $this->deleteJson('/api/2fa', [
            'password' => 'secret123',
        ])
            ->assertOk()
            ->assertJsonPath('data.user.two_factor_enabled', false);

        $this->assertDatabaseHas('users', [
            'id' => $user->getKey(),
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
        ]);
    }

    public function test_user_can_start_two_factor_setup_with_full_access_token(): void
    {
        $tenant = $this->tenant('Tenant 2FA Setup');
        $user = $this->tenantUser($tenant, 'setup-own-2fa@example.test');

        Sanctum::actingAs($user, ['access:full', 'tenant:' . $tenant->uid]);

        $this->getJson('/api/2fa/setup')
            ->assertOk()
            ->assertJsonPath('data.user.two_factor_enabled', false)
            ->assertJsonStructure(['data' => ['secret', 'otpauth_url', 'user']]);
    }

    public function test_tenant_admin_can_reset_two_factor_for_user_in_same_tenant(): void
    {
        $tenant = $this->tenant('Tenant 2FA Admin Reset');
        $admin = $this->tenantUser($tenant, 'tenant-admin-2fa@example.test');
        $this->grantPermissions($admin, ['users.manage']);

        $target = $this->tenantUser($tenant, 'target-2fa@example.test', [
            'two_factor_secret' => app(TwoFactorService::class)->generateSecret(),
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => ['hashed-code'],
        ]);

        Sanctum::actingAs($admin, ['access:full', 'tenant:' . $tenant->uid]);

        $this->postJson('/api/users/' . $target->uid . '/2fa/reset')
            ->assertOk()
            ->assertJsonPath('data.uid', $target->uid)
            ->assertJsonPath('data.two_factor_enabled', false);
    }

    public function test_tenant_admin_cannot_reset_two_factor_for_other_tenant_user(): void
    {
        $tenantA = $this->tenant('Tenant 2FA A');
        $tenantB = $this->tenant('Tenant 2FA B');
        $admin = $this->tenantUser($tenantA, 'tenant-a-admin@example.test');
        $this->grantPermissions($admin, ['users.manage']);
        $target = $this->tenantUser($tenantB, 'tenant-b-user@example.test', [
            'two_factor_secret' => app(TwoFactorService::class)->generateSecret(),
            'two_factor_confirmed_at' => now(),
        ]);

        Sanctum::actingAs($admin, ['access:full', 'tenant:' . $tenantA->uid]);

        $this->postJson('/api/users/' . $target->uid . '/2fa/reset')
            ->assertNotFound();
    }

    public function test_platform_admin_can_reset_two_factor_for_platform_user(): void
    {
        $admin = User::withoutGlobalScopes()->create([
            'name' => 'Platform Admin',
            'email' => 'platform-admin-2fa@example.test',
            'password' => bcrypt('secret123'),
            'tenant_id' => null,
            'is_platform_admin' => true,
        ]);
        $this->grantPermissions($admin, ['admin.tenants.manage']);

        $target = User::withoutGlobalScopes()->create([
            'name' => 'Platform Target',
            'email' => 'platform-target-2fa@example.test',
            'password' => bcrypt('secret123'),
            'tenant_id' => null,
            'is_platform_admin' => true,
            'two_factor_secret' => app(TwoFactorService::class)->generateSecret(),
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => ['hashed-code'],
        ]);

        Sanctum::actingAs($admin, ['access:full', 'platform:admin']);

        $this->postJson('/api/admin/platform/users/' . $target->uid . '/2fa/reset')
            ->assertOk()
            ->assertJsonPath('data.uid', $target->uid)
            ->assertJsonPath('data.two_factor_enabled', false);
    }

    public function test_platform_admin_can_reset_two_factor_for_tenant_user(): void
    {
        $admin = User::withoutGlobalScopes()->create([
            'name' => 'Platform Admin Tenant Users',
            'email' => 'platform-admin-tenants-2fa@example.test',
            'password' => bcrypt('secret123'),
            'tenant_id' => null,
            'is_platform_admin' => true,
        ]);
        $this->grantPermissions($admin, ['admin.tenants.manage']);

        $tenant = $this->tenant('Tenant Managed By Platform');
        $target = $this->tenantUser($tenant, 'tenant-user-platform-reset@example.test', [
            'two_factor_secret' => app(TwoFactorService::class)->generateSecret(),
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => ['hashed-code'],
        ]);

        Sanctum::actingAs($admin, ['access:full', 'platform:admin']);

        $this->postJson('/api/admin/tenants/' . $tenant->uid . '/users/' . $target->uid . '/2fa/reset')
            ->assertOk()
            ->assertJsonPath('data.uid', $target->uid)
            ->assertJsonPath('data.two_factor_enabled', false);
    }

    public function test_platform_admin_cannot_reset_two_factor_for_user_from_another_tenant_path(): void
    {
        $admin = User::withoutGlobalScopes()->create([
            'name' => 'Platform Admin Tenant Isolation',
            'email' => 'platform-admin-isolation-2fa@example.test',
            'password' => bcrypt('secret123'),
            'tenant_id' => null,
            'is_platform_admin' => true,
        ]);
        $this->grantPermissions($admin, ['admin.tenants.manage']);

        $tenantA = $this->tenant('Tenant Path A');
        $tenantB = $this->tenant('Tenant Path B');
        $target = $this->tenantUser($tenantB, 'tenant-b-platform-reset@example.test', [
            'two_factor_secret' => app(TwoFactorService::class)->generateSecret(),
            'two_factor_confirmed_at' => now(),
        ]);

        Sanctum::actingAs($admin, ['access:full', 'platform:admin']);

        $this->postJson('/api/admin/tenants/' . $tenantA->uid . '/users/' . $target->uid . '/2fa/reset')
            ->assertNotFound();
    }

    private function tenant(string $name): Tenant
    {
        return Tenant::query()->create([
            'name' => $name,
            'status' => 'ACTIVO',
            'is_active' => true,
        ]);
    }

    private function tenantUser(Tenant $tenant, string $email, array $attributes = []): User
    {
        return User::withoutGlobalScopes()->create(array_merge([
            'tenant_id' => $tenant->getKey(),
            'name' => 'Tenant User',
            'email' => $email,
            'password' => bcrypt('secret123'),
        ], $attributes));
    }

    private function grantPermissions(User $user, array $permissionKeys): void
    {
        foreach ($permissionKeys as $key) {
            Permission::query()->firstOrCreate(
                ['key' => $key],
                [
                    'module' => str_contains($key, '.') ? explode('.', $key)[0] : 'users',
                    'action' => $key,
                    'description' => $key,
                ]
            );
        }

        $user->permissions()->syncWithoutDetaching(
            Permission::query()->whereIn('key', $permissionKeys)->pluck('id')->all()
        );
    }
}
