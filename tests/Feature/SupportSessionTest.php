<?php

namespace Tests\Feature;

use App\Models\AdminRole;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SupportSession;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SupportSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_support_can_enter_tenant_in_readonly_mode(): void
    {
        $admin = $this->supportAdmin();
        $tenant = $this->tenantWithOwner();

        Sanctum::actingAs($admin, ['access:full', 'platform:admin']);

        $response = $this->postJson('/api/admin/tenants/' . $tenant->uid . '/support-login', [
            'reason' => 'Revision solicitada por cliente',
        ])
            ->assertCreated()
            ->assertJsonPath('data.session.active', true)
            ->assertJsonPath('data.session.tenant_uid', $tenant->uid)
            ->assertJsonPath('data.session.support_user_uid', $admin->uid)
            ->assertJsonPath('data.session.mode', 'support')
            ->assertJsonPath('data.session.readonly', true);

        $token = $response->json('data.token');
        $this->app['auth']->forgetGuards();

        $this->withToken($token)
            ->getJson('/api/auth/init')
            ->assertOk()
            ->assertJsonPath('data.tenant.uid', $tenant->uid)
            ->assertJsonPath('data.support_mode.active', true)
            ->assertJsonPath('data.support_mode.tenant_uid', $tenant->uid)
            ->assertJsonPath('data.support_mode.readonly', true);

        $this->withToken($token)
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.tenant_uid', $tenant->uid);

        $this->withToken($token)
            ->putJson('/api/me', ['name' => 'Cambio soporte'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Modo soporte solo permite lectura');
    }

    public function test_support_session_can_be_stopped_and_token_is_revoked(): void
    {
        $admin = $this->supportAdmin();
        $tenant = $this->tenantWithOwner();

        Sanctum::actingAs($admin, ['access:full', 'platform:admin']);

        $token = $this->postJson('/api/admin/tenants/' . $tenant->uid . '/support-login', [
            'reason' => 'Prueba de cierre de sesion soporte',
        ])->json('data.token');
        $this->app['auth']->forgetGuards();

        $this->withToken($token)
            ->postJson('/api/support-session/stop')
            ->assertOk()
            ->assertJsonPath('data.active', false)
            ->assertJsonPath('message', 'Sesion de soporte finalizada');

        $this->assertNotNull(SupportSession::query()->first()?->ended_at);
        $this->app['auth']->forgetGuards();

        $this->withToken($token)
            ->getJson('/api/auth/init')
            ->assertUnauthorized();
    }

    public function test_support_login_requires_support_permission(): void
    {
        $admin = User::withoutGlobalScopes()->create([
            'name' => 'Platform Admin',
            'email' => 'platform-no-support@example.test',
            'password' => bcrypt('secret123'),
            'tenant_id' => null,
            'is_platform_admin' => true,
        ]);

        $this->permission('admin.tenants.manage', 'admin');
        $admin->permissions()->sync(Permission::query()->where('key', 'admin.tenants.manage')->pluck('id'));

        Sanctum::actingAs($admin, ['access:full', 'platform:admin']);

        $this->postJson('/api/admin/tenants/' . $this->tenantWithOwner()->uid . '/support-login', [
            'reason' => 'Intento sin permiso especifico',
        ])->assertForbidden();
    }

    private function supportAdmin(): User
    {
        $admin = User::withoutGlobalScopes()->create([
            'name' => 'Soporte Plataforma',
            'email' => 'support@example.test',
            'password' => bcrypt('secret123'),
            'tenant_id' => null,
            'is_platform_admin' => true,
        ]);

        foreach (['admin.tenants.manage', 'admin.tenants.support'] as $key) {
            $this->permission($key, 'admin');
        }

        $role = AdminRole::query()->create([
            'name' => 'Soporte',
            'key' => 'support',
            'description' => 'Soporte',
            'is_system' => true,
        ]);

        $role->permissions()->sync(Permission::query()->whereIn('key', [
            'admin.tenants.manage',
            'admin.tenants.support',
        ])->pluck('id'));

        $admin->adminRoles()->sync([$role->getKey()]);

        return $admin;
    }

    private function tenantWithOwner(): Tenant
    {
        $tenant = Tenant::query()->create([
            'name' => 'Tenant Soporte',
            'domain' => 'tenant-soporte.test',
            'status' => 'ACTIVO',
            'is_active' => true,
        ]);

        $owner = User::withoutGlobalScopes()->create([
            'name' => 'Owner Tenant',
            'email' => 'owner-tenant@example.test',
            'password' => bcrypt('secret123'),
            'tenant_id' => $tenant->getKey(),
            'is_platform_admin' => false,
        ]);

        $role = Role::withoutGlobalScopes()->create([
            'name' => 'Owner',
            'key' => 'owner',
            'tenant_id' => $tenant->getKey(),
        ]);

        foreach (['dashboard.read', 'users.manage'] as $key) {
            $this->permission($key, explode('.', $key)[0]);
        }

        $role->permissions()->sync(Permission::query()->whereIn('key', [
            'dashboard.read',
            'users.manage',
        ])->pluck('id'));
        $owner->roles()->sync([$role->getKey()]);

        return $tenant;
    }

    private function permission(string $key, string $module): Permission
    {
        return Permission::query()->firstOrCreate(
            ['key' => $key],
            [
                'module' => $module,
                'action' => $key,
                'description' => $key,
            ]
        );
    }
}
