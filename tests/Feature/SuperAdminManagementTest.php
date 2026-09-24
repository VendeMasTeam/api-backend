<?php

namespace Tests\Feature;

use App\Models\AdminAlertRule;
use App\Models\Invoice;
use App\Models\OpportunityStage;
use App\Models\Permission;
use App\Models\Plan;
use App\Models\Role;
use App\Models\SystemLog;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantRoleProvisioner;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SuperAdminManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_can_create_plan_with_frontend_feature_payload(): void
    {
        $this->authenticateSuperadmin(['plans.manage']);

        $response = $this->postJson('/api/plans', [
            'name' => 'Plan Pro',
            'tier' => 'PRO',
            'price' => 49,
            'is_active' => true,
            'max_users' => 5,
            'storage_gb' => 10,
            'api_calls_month' => 10000,
            'modules' => ['Ventas', 'Inventario', 'RH / Comisiones'],
            'custom_domain' => true,
            'sso_saml' => false,
            'advanced_reports' => true,
            'support' => 'Email + Chat',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'ACTIVO')
            ->assertJsonPath('data.features.storage_gb', 10)
            ->assertJsonPath('data.features.api_calls_month', 10000)
            ->assertJsonPath('data.features.support', 'Email + Chat');
    }

    public function test_superadmin_can_list_plan_modules_catalog(): void
    {
        $this->authenticateSuperadmin(['plans.manage']);

        $this->getJson('/api/admin/plan-modules')
            ->assertOk()
            ->assertJsonPath('data.0.key', 'ventas')
            ->assertJsonPath('data.0.label', 'Ventas')
            ->assertJsonFragment(['key' => 'crm', 'label' => 'CRM'])
            ->assertJsonFragment(['key' => 'proyectos', 'label' => 'Proyectos'])
            ->assertJsonFragment(['key' => 'rh', 'label' => 'Incentivos y Comisiones'])
            ->assertJsonFragment(['key' => 'partners', 'label' => 'Canales & Partners'])
            ->assertJsonFragment(['key' => 'inteligencia-competitiva', 'label' => 'Inteligencia Competitiva'])
            ->assertJsonFragment(['key' => 'automatizacion', 'label' => 'Automatizacion'])
            ->assertJsonFragment(['key' => 'tareas', 'label' => 'Tareas'])
            ->assertJsonFragment(['key' => 'gastos', 'label' => 'Gastos'])
            ->assertJsonFragment(['key' => 'compras', 'label' => 'Compras'])
            ->assertJsonFragment(['key' => 'multi-currency', 'label' => 'Multi-currency'])
            ->assertJsonFragment(['key' => 'custom-fields', 'label' => 'Campos personalizados'])
            ->assertJsonFragment(['key' => 'api-publica', 'label' => 'API Publica']);
    }

    public function test_superadmin_can_create_platform_role_without_key(): void
    {
        $this->authenticateSuperadmin(['admin.tenants.manage']);

        $this->postJson('/api/admin/platform/roles', [
            'name' => 'Soporte Plataforma',
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Soporte Plataforma')
            ->assertJsonPath('data.key', 'soporte_plataforma');

        $this->postJson('/api/admin/platform/roles', [
            'name' => 'Soporte Plataforma',
        ])
            ->assertCreated()
            ->assertJsonPath('data.key', 'soporte_plataforma_2');
    }

    public function test_platform_roles_only_list_and_accept_platform_permissions(): void
    {
        $this->authenticateSuperadmin(['admin.tenants.manage']);

        $platformPermission = Permission::query()->create([
            'key' => 'admin.support.manage',
            'module' => 'admin',
            'action' => 'support.manage',
            'description' => 'Administrar soporte de plataforma',
            'scope' => Permission::SCOPE_PLATFORM,
        ]);
        $tenantPermission = Permission::query()->create([
            'key' => 'contacts.test-read',
            'module' => 'contacts',
            'action' => 'read',
            'description' => 'Permiso operativo de prueba',
            'scope' => Permission::SCOPE_TENANT,
        ]);

        $this->getJson('/api/admin/platform/permissions')
            ->assertOk()
            ->assertJsonFragment(['uid' => $platformPermission->uid, 'scope' => 'platform'])
            ->assertJsonMissing(['uid' => $tenantPermission->uid]);

        $this->postJson('/api/admin/platform/roles', [
            'name' => 'Soporte Restringido',
            'permission_uids' => [$tenantPermission->uid],
        ])->assertUnprocessable();

        $this->postJson('/api/admin/platform/roles', [
            'name' => 'Soporte Configurable',
            'permission_uids' => [$platformPermission->uid],
        ])
            ->assertCreated()
            ->assertJsonPath('data.permissions.0.uid', $platformPermission->uid)
            ->assertJsonPath('data.permissions.0.scope', 'platform');
    }

    public function test_superadmin_can_lock_and_unlock_platform_user(): void
    {
        $this->authenticateSuperadmin(['admin.tenants.purge']);

        $target = User::withoutGlobalScopes()->create([
            'name' => 'Platform Support',
            'email' => 'platform-support@example.test',
            'password' => bcrypt('secret123'),
            'tenant_id' => null,
            'is_platform_admin' => true,
        ]);
        $token = $target->createToken('api-token')->accessToken;

        $this->postJson('/api/admin/platform/users/'.$target->uid.'/lock')
            ->assertOk()
            ->assertJsonPath('message', 'Usuario de plataforma desactivado')
            ->assertJsonPath('data.status', 'INACTIVO');

        $this->assertNotNull($target->fresh()->locked_until);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->getKey()]);

        $this->postJson('/api/admin/platform/users/'.$target->uid.'/unlock')
            ->assertOk()
            ->assertJsonPath('message', 'Usuario de plataforma activado')
            ->assertJsonPath('data.status', 'ACTIVO');

        $this->assertNull($target->fresh()->locked_until);
    }

    public function test_superadmin_can_purge_platform_user_with_email_confirmation(): void
    {
        $this->authenticateSuperadmin(['admin.tenants.purge']);

        $target = User::withoutGlobalScopes()->create([
            'name' => 'Platform Support',
            'email' => 'platform-purge@example.test',
            'password' => bcrypt('secret123'),
            'tenant_id' => null,
            'is_platform_admin' => true,
        ]);
        $token = $target->createToken('api-token')->accessToken;

        $this->deleteJson('/api/admin/platform/users/'.$target->uid.'/purge', [
            'confirmation' => $target->email,
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Usuario de plataforma eliminado definitivamente')
            ->assertJsonPath('data.uid', $target->uid)
            ->assertJsonPath('data.email', $target->email);

        $this->assertDatabaseMissing('users', ['id' => $target->getKey()]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->getKey()]);
    }

    public function test_superadmin_cannot_purge_platform_user_without_exact_confirmation(): void
    {
        $this->authenticateSuperadmin(['admin.tenants.purge']);

        $target = User::withoutGlobalScopes()->create([
            'name' => 'Platform Support',
            'email' => 'platform-confirm@example.test',
            'password' => bcrypt('secret123'),
            'tenant_id' => null,
            'is_platform_admin' => true,
        ]);

        $this->deleteJson('/api/admin/platform/users/'.$target->uid.'/purge', [
            'confirmation' => 'wrong@example.test',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.confirmation.0', 'La confirmacion debe coincidir exactamente con el email del usuario');

        $this->assertDatabaseHas('users', ['id' => $target->getKey()]);
    }

    public function test_superadmin_cannot_lock_or_purge_own_platform_user(): void
    {
        $admin = $this->authenticateSuperadmin(['admin.tenants.purge']);

        $this->postJson('/api/admin/platform/users/'.$admin->uid.'/lock')
            ->assertUnprocessable()
            ->assertJsonPath('errors.user.0', 'No puedes desactivar tu propio usuario de plataforma');

        $this->deleteJson('/api/admin/platform/users/'.$admin->uid.'/purge', [
            'confirmation' => $admin->email,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.user.0', 'No puedes eliminar definitivamente tu propio usuario de plataforma');

        $this->assertDatabaseHas('users', ['id' => $admin->getKey()]);
    }

    public function test_plan_delete_deactivates_when_tenants_are_attached(): void
    {
        $this->authenticateSuperadmin(['plans.manage']);

        $plan = Plan::query()->create([
            'name' => 'Plan Starter',
            'price' => 49,
            'status' => 'ACTIVO',
        ]);

        Tenant::query()->create([
            'name' => 'Acme Corporation',
            'domain' => 'acme.test',
            'status' => 'ACTIVO',
            'plan_id' => $plan->getKey(),
            'is_active' => true,
        ]);

        $this->deleteJson('/api/plans/'.$plan->uid)
            ->assertOk()
            ->assertJsonPath('data.status', 'INACTIVO');

        $this->assertDatabaseHas('plans', [
            'id' => $plan->getKey(),
            'status' => 'INACTIVO',
        ]);
    }

    public function test_superadmin_can_export_billing_report(): void
    {
        $this->authenticateSuperadmin(['admin.billing.manage']);

        $tenant = $this->tenantWithPlan();

        Invoice::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->getKey(),
            'invoiceable_type' => Tenant::class,
            'invoiceable_id' => $tenant->getKey(),
            'invoice_number' => 'INV-001',
            'status' => 'overdue',
            'currency' => 'USD',
            'subtotal' => 100,
            'total' => 100,
            'outstanding_total' => 100,
            'issued_at' => '2026-03-01',
            'due_date' => '2026-03-15',
        ]);

        $this->getJson('/api/admin/billing/export?format=json')
            ->assertOk()
            ->assertJsonPath('data.summary.total_facturas', 1)
            ->assertJsonPath('data.summary.total_vencido', 100)
            ->assertJsonPath('data.rows.0.status', 'VENCIDA');
    }

    public function test_superadmin_can_read_billing_summary_cards(): void
    {
        $this->authenticateSuperadmin(['admin.billing.manage']);

        $tenant = $this->tenantWithPlan();

        Invoice::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->getKey(),
            'invoiceable_type' => Tenant::class,
            'invoiceable_id' => $tenant->getKey(),
            'invoice_number' => 'INV-PAID',
            'status' => 'paid',
            'currency' => 'USD',
            'subtotal' => 100,
            'total' => 100,
            'paid_total' => 100,
            'outstanding_total' => 0,
            'issued_at' => now()->toDateString(),
            'due_date' => now()->addDays(10)->toDateString(),
        ]);

        Invoice::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->getKey(),
            'invoiceable_type' => Tenant::class,
            'invoiceable_id' => $tenant->getKey(),
            'invoice_number' => 'INV-OVERDUE',
            'status' => 'overdue',
            'currency' => 'USD',
            'subtotal' => 80,
            'total' => 80,
            'outstanding_total' => 80,
            'issued_at' => now()->toDateString(),
            'due_date' => now()->subDays(5)->toDateString(),
        ]);

        $this->getJson('/api/admin/billing/summary')
            ->assertOk()
            ->assertJsonPath('data.cobrado_este_mes', 100)
            ->assertJsonPath('data.facturas_vencidas', 80)
            ->assertJsonPath('data.total_facturas', 2);
    }

    public function test_superadmin_tenant_index_uses_frontend_contract(): void
    {
        $this->authenticateSuperadmin(['admin.tenants.manage']);

        $tenant = $this->tenantWithPlan();
        $tenant->update([
            'api_calls_mes' => 123,
        ]);

        $this->getJson('/api/admin/tenants')
            ->assertOk()
            ->assertJsonPath('data.0.nombre', 'Acme Corporation')
            ->assertJsonPath('data.0.dominio', 'acme.test')
            ->assertJsonPath('data.0.plan_nombre', 'Plan Pro')
            ->assertJsonPath('data.0.api_calls_mes', 123)
            ->assertJsonPath('data.0.limite_api_calls', 10000);
    }

    public function test_superadmin_can_list_tenant_users_for_drawer(): void
    {
        $this->authenticateSuperadmin(['admin.tenants.manage']);

        $tenant = $this->tenantWithPlan();
        $role = Role::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->getKey(),
            'name' => 'Owner',
            'key' => 'owner',
            'is_system' => true,
        ]);
        $user = User::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->getKey(),
            'name' => 'Juan Perez',
            'email' => 'juan@acme.com',
            'password' => bcrypt('secret123'),
            'last_login_at' => '2025-03-20 08:00:00',
        ]);
        $user->roles()->attach($role->getKey());

        $this->getJson('/api/admin/tenants/'.$tenant->uid.'/users')
            ->assertOk()
            ->assertJsonPath('data.0.uid', $user->uid)
            ->assertJsonPath('data.0.name', 'Juan Perez')
            ->assertJsonPath('data.0.email', 'juan@acme.com')
            ->assertJsonPath('data.0.rol', 'owner')
            ->assertJsonPath('data.0.ultimo_acceso', Carbon::parse('2025-03-20 08:00:00')->toISOString())
            ->assertJsonPath('data.0.estado', 'Activo')
            ->assertJsonPath('meta.pagination.current_page', 1)
            ->assertJsonPath('meta.pagination.per_page', 25)
            ->assertJsonPath('meta.pagination.total', 1);
    }

    public function test_superadmin_can_filter_lock_and_unlock_tenant_users(): void
    {
        $this->authenticateSuperadmin(['admin.tenants.manage']);

        $tenant = $this->tenantWithPlan();
        $ownerRole = Role::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->getKey(),
            'name' => 'Owner',
            'key' => 'owner',
            'is_system' => true,
        ]);
        $managerRole = Role::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->getKey(),
            'name' => 'Manager',
            'key' => 'manager',
            'is_system' => true,
        ]);

        $owner = User::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->getKey(),
            'name' => 'Owner User',
            'email' => 'owner@acme.com',
            'password' => bcrypt('secret123'),
        ]);
        $owner->roles()->attach($ownerRole->getKey());

        $manager = User::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->getKey(),
            'name' => 'Manager User',
            'email' => 'manager@acme.com',
            'password' => bcrypt('secret123'),
        ]);
        $manager->roles()->attach($managerRole->getKey());

        $this->getJson('/api/admin/tenants/'.$tenant->uid.'/users?role=owner')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.uid', $owner->uid);

        $this->postJson('/api/admin/tenants/'.$tenant->uid.'/users/'.$owner->uid.'/lock')
            ->assertOk()
            ->assertJsonPath('data.estado', 'Inactivo');

        $this->postJson('/api/admin/tenants/'.$tenant->uid.'/users/'.$owner->uid.'/unlock')
            ->assertOk()
            ->assertJsonPath('data.estado', 'Activo');
    }

    public function test_superadmin_can_update_tenant_user_profile_role_and_status(): void
    {
        $this->authenticateSuperadmin(['admin.tenants.manage']);

        $this->permission('dashboard.read', 'dashboard');
        $tenant = $this->tenantWithPlan();
        app(TenantRoleProvisioner::class)->provision($tenant);

        $user = User::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->getKey(),
            'name' => 'Old Name',
            'email' => 'old-user@acme.com',
            'password' => bcrypt('secret123'),
        ]);
        $seller = Role::withoutGlobalScopes()
            ->where('tenant_id', $tenant->getKey())
            ->where('key', 'seller')
            ->firstOrFail();
        $user->roles()->attach($seller->getKey());

        $this->putJson('/api/admin/tenants/'.$tenant->uid.'/users/'.$user->uid, [
            'name' => 'New Name',
            'email' => 'new-user@acme.com',
            'role' => 'manager',
            'is_active' => false,
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.email', 'new-user@acme.com')
            ->assertJsonPath('data.rol', 'manager')
            ->assertJsonPath('data.estado', 'Inactivo');

        $this->assertNotNull($user->fresh()->locked_until);
    }

    public function test_superadmin_can_logically_delete_tenant_user(): void
    {
        $this->authenticateSuperadmin(['admin.tenants.manage']);

        $tenant = $this->tenantWithPlan();
        app(TenantRoleProvisioner::class)->provision($tenant);

        $owner = User::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->getKey(),
            'name' => 'Owner User',
            'email' => 'owner-delete-guard@acme.com',
            'password' => bcrypt('secret123'),
        ]);
        $seller = User::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->getKey(),
            'name' => 'Seller User',
            'email' => 'seller-delete@acme.com',
            'password' => bcrypt('secret123'),
        ]);

        $ownerRole = Role::withoutGlobalScopes()->where('tenant_id', $tenant->getKey())->where('key', 'owner')->firstOrFail();
        $sellerRole = Role::withoutGlobalScopes()->where('tenant_id', $tenant->getKey())->where('key', 'seller')->firstOrFail();
        $owner->roles()->attach($ownerRole->getKey());
        $seller->roles()->attach($sellerRole->getKey());

        $token = $seller->createToken('api-token')->accessToken;

        $this->deleteJson('/api/admin/tenants/'.$tenant->uid.'/users/'.$seller->uid)
            ->assertOk()
            ->assertJsonPath('data.estado', 'Inactivo');

        $this->assertNotNull($seller->fresh()->locked_until);
        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $token->getKey(),
        ]);
    }

    public function test_superadmin_cannot_delete_last_active_owner_from_tenant(): void
    {
        $this->authenticateSuperadmin(['admin.tenants.manage']);

        $tenant = $this->tenantWithPlan();
        app(TenantRoleProvisioner::class)->provision($tenant);

        $owner = User::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->getKey(),
            'name' => 'Only Owner',
            'email' => 'only-owner@acme.com',
            'password' => bcrypt('secret123'),
        ]);
        $ownerRole = Role::withoutGlobalScopes()->where('tenant_id', $tenant->getKey())->where('key', 'owner')->firstOrFail();
        $owner->roles()->attach($ownerRole->getKey());

        $this->deleteJson('/api/admin/tenants/'.$tenant->uid.'/users/'.$owner->uid)
            ->assertUnprocessable()
            ->assertJsonPath('errors.user.0', 'No puedes eliminar el ultimo owner activo del tenant');

        $this->assertNull($owner->fresh()->locked_until);
    }

    public function test_superadmin_can_logically_delete_tenant_without_dropping_schema(): void
    {
        $this->authenticateSuperadmin(['admin.tenants.manage']);

        $tenant = $this->tenantWithPlan();
        $tenant->forceFill([
            'schema_name' => 'tenant_acme_corporation',
            'schema_migrated_at' => now(),
        ])->save();

        $user = User::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->getKey(),
            'name' => 'Tenant User',
            'email' => 'tenant-delete-user@acme.com',
            'password' => bcrypt('secret123'),
        ]);
        $token = $user->createToken('api-token')->accessToken;

        $this->deleteJson('/api/admin/tenants/'.$tenant->uid)
            ->assertOk()
            ->assertJsonPath('data.estado', 'ARCHIVADO')
            ->assertJsonPath('data.schema_name', 'tenant_acme_corporation');

        $tenant->refresh();
        $this->assertFalse($tenant->is_active);
        $this->assertSame('ARCHIVADO', $tenant->status);
        $this->assertNotNull($user->fresh()->locked_until);
        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $token->getKey(),
        ]);
    }

    public function test_superadmin_can_purge_tenant_with_confirmation(): void
    {
        $this->authenticateSuperadmin(['admin.tenants.purge']);

        $tenant = $this->tenantWithPlan();
        app(TenantRoleProvisioner::class)->provision($tenant);

        $user = User::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->getKey(),
            'name' => 'Tenant User',
            'email' => 'tenant-purge-user@acme.com',
            'password' => bcrypt('secret123'),
        ]);

        $role = Role::withoutGlobalScopes()
            ->where('tenant_id', $tenant->getKey())
            ->where('key', 'owner')
            ->firstOrFail();

        $user->roles()->attach($role->getKey());
        $token = $user->createToken('api-token')->accessToken;

        $this->deleteJson('/api/admin/tenants/'.$tenant->uid.'/purge', [
            'confirmation' => $tenant->name,
        ])
            ->assertOk()
            ->assertJsonPath('data.uid', $tenant->uid)
            ->assertJsonPath('data.schema_deleted', true);

        $this->assertDatabaseMissing('tenants', ['id' => $tenant->getKey()]);
        $this->assertDatabaseMissing('users', ['id' => $user->getKey()]);
        $this->assertDatabaseMissing('roles', ['id' => $role->getKey()]);
        $this->assertDatabaseMissing('role_user', ['user_id' => $user->getKey()]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->getKey()]);
    }

    public function test_superadmin_cannot_purge_tenant_without_exact_confirmation(): void
    {
        $this->authenticateSuperadmin(['admin.tenants.purge']);

        $tenant = $this->tenantWithPlan();

        $this->deleteJson('/api/admin/tenants/'.$tenant->uid.'/purge', [
            'confirmation' => 'Acme',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.confirmation.0', 'La confirmacion debe coincidir exactamente con el nombre del tenant');

        $this->assertDatabaseHas('tenants', ['id' => $tenant->getKey()]);
    }

    public function test_superadmin_can_purge_tenant_user_with_confirmation(): void
    {
        $this->authenticateSuperadmin(['admin.tenants.purge']);

        $tenant = $this->tenantWithPlan();
        app(TenantRoleProvisioner::class)->provision($tenant);

        $owner = User::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->getKey(),
            'name' => 'Owner User',
            'email' => 'owner-purge-guard@acme.com',
            'password' => bcrypt('secret123'),
        ]);
        $seller = User::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->getKey(),
            'name' => 'Seller User',
            'email' => 'seller-purge@acme.com',
            'password' => bcrypt('secret123'),
        ]);

        $ownerRole = Role::withoutGlobalScopes()->where('tenant_id', $tenant->getKey())->where('key', 'owner')->firstOrFail();
        $sellerRole = Role::withoutGlobalScopes()->where('tenant_id', $tenant->getKey())->where('key', 'seller')->firstOrFail();
        $owner->roles()->attach($ownerRole->getKey());
        $seller->roles()->attach($sellerRole->getKey());

        $token = $seller->createToken('api-token')->accessToken;

        $this->deleteJson('/api/admin/tenants/'.$tenant->uid.'/users/'.$seller->uid.'/purge', [
            'confirmation' => $seller->email,
        ])
            ->assertOk()
            ->assertJsonPath('data.uid', $seller->uid)
            ->assertJsonPath('data.email', $seller->email);

        $this->assertDatabaseMissing('users', ['id' => $seller->getKey()]);
        $this->assertDatabaseMissing('role_user', ['user_id' => $seller->getKey()]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->getKey()]);
        $this->assertDatabaseHas('users', ['id' => $owner->getKey()]);
    }

    public function test_superadmin_cannot_purge_last_active_owner_from_tenant(): void
    {
        $this->authenticateSuperadmin(['admin.tenants.purge']);

        $tenant = $this->tenantWithPlan();
        app(TenantRoleProvisioner::class)->provision($tenant);

        $owner = User::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->getKey(),
            'name' => 'Only Owner',
            'email' => 'only-owner-purge@acme.com',
            'password' => bcrypt('secret123'),
        ]);

        $ownerRole = Role::withoutGlobalScopes()->where('tenant_id', $tenant->getKey())->where('key', 'owner')->firstOrFail();
        $owner->roles()->attach($ownerRole->getKey());

        $this->deleteJson('/api/admin/tenants/'.$tenant->uid.'/users/'.$owner->uid.'/purge', [
            'confirmation' => $owner->email,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.user.0', 'No puedes eliminar definitivamente el ultimo owner activo del tenant');

        $this->assertDatabaseHas('users', ['id' => $owner->getKey()]);
    }

    public function test_superadmin_can_archive_restore_and_read_tenant_expires_at(): void
    {
        $this->authenticateSuperadmin(['admin.tenants.manage']);

        $tenant = $this->tenantWithPlan();
        $tenant->update([
            'status' => 'TRIAL',
            'expires_at' => now()->addDays(14),
        ]);

        $this->getJson('/api/admin/tenants/'.$tenant->uid)
            ->assertOk()
            ->assertJsonPath('data.estado', 'TRIAL')
            ->assertJsonStructure(['data' => ['expires_at']]);

        $this->postJson('/api/admin/tenants/'.$tenant->uid.'/archive')
            ->assertOk()
            ->assertJsonPath('data.estado', 'ARCHIVADO');

        $this->postJson('/api/admin/tenants/'.$tenant->uid.'/restore')
            ->assertOk()
            ->assertJsonPath('data.estado', 'ACTIVO');
    }

    public function test_superadmin_billing_accepts_search_and_plan_filters(): void
    {
        $this->authenticateSuperadmin(['admin.billing.manage']);

        $tenant = $this->tenantWithPlan();
        $otherPlan = Plan::query()->create([
            'name' => 'Plan Starter',
            'price' => 49,
            'status' => 'ACTIVO',
        ]);
        $otherTenant = Tenant::query()->create([
            'name' => 'Other Corp',
            'domain' => 'other.test',
            'status' => 'ACTIVO',
            'plan_id' => $otherPlan->getKey(),
            'is_active' => true,
        ]);

        foreach ([$tenant, $otherTenant] as $index => $invoiceTenant) {
            Invoice::withoutGlobalScopes()->create([
                'tenant_id' => $invoiceTenant->getKey(),
                'invoiceable_type' => Tenant::class,
                'invoiceable_id' => $invoiceTenant->getKey(),
                'invoice_number' => 'INV-FILTER-'.$index,
                'status' => 'issued',
                'currency' => 'USD',
                'subtotal' => 100,
                'total' => 100,
                'outstanding_total' => 100,
                'issued_at' => now()->toDateString(),
                'due_date' => now()->addDays(10)->toDateString(),
            ]);
        }

        $this->getJson('/api/admin/billing?search=Acme&plan_uid='.$tenant->plan->uid)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.tenant_uid', $tenant->uid);
    }

    public function test_superadmin_can_delete_alert_and_filter_permissions_by_plan(): void
    {
        $this->authenticateSuperadmin(['admin.alerts.manage', 'admin.tenants.manage']);

        $alert = AdminAlertRule::query()->create([
            'name' => 'Errores altos',
            'condition_text' => 'errores > 10 en 24h',
            'channels' => ['EMAIL'],
            'is_active' => true,
        ]);

        $this->deleteJson('/api/admin/telemetry/alerts/'.$alert->uid)
            ->assertOk();

        $this->assertDatabaseMissing('admin_alert_rules', [
            'uid' => $alert->uid,
        ]);

        Permission::query()->firstOrCreate([
            'key' => 'inventory.read',
        ], [
            'module' => 'inventory',
            'action' => 'read',
            'description' => 'Ver inventario',
        ]);
        Permission::query()->firstOrCreate([
            'key' => 'projects.read',
        ], [
            'module' => 'projects',
            'action' => 'read',
            'description' => 'Ver proyectos',
        ]);

        $tenant = $this->tenantWithPlan();
        $tenant->plan->update([
            'features' => [
                'modules' => ['Inventario'],
            ],
        ]);

        $response = $this->getJson('/api/admin/tenants/'.$tenant->uid.'/permissions?only_active_modules=true')
            ->assertOk();

        $keys = collect($response->json('data'))->pluck('key');
        $this->assertTrue($keys->contains('inventory.read'));
        $this->assertFalse($keys->contains('projects.read'));
    }

    public function test_superadmin_dashboard_accepts_period_filter(): void
    {
        $this->authenticateSuperadmin(['admin.dashboard.read']);

        $this->tenantWithPlan();

        $this->getJson('/api/admin/dashboard?period=30d')
            ->assertOk()
            ->assertJsonPath('data.period', '30d');
    }

    public function test_superadmin_can_paginate_tenant_users_for_drawer(): void
    {
        $this->authenticateSuperadmin(['admin.tenants.manage']);

        $tenant = $this->tenantWithPlan();

        foreach (['Ana Gomez', 'Juan Perez', 'Maria Ruiz'] as $index => $name) {
            User::withoutGlobalScopes()->create([
                'tenant_id' => $tenant->getKey(),
                'name' => $name,
                'email' => 'tenant-user-'.$index.'@acme.com',
                'password' => bcrypt('secret123'),
            ]);
        }

        $this->getJson('/api/admin/tenants/'.$tenant->uid.'/users?page=2&per_page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Maria Ruiz')
            ->assertJsonPath('meta.pagination.current_page', 2)
            ->assertJsonPath('meta.pagination.per_page', 2)
            ->assertJsonPath('meta.pagination.total', 3)
            ->assertJsonPath('meta.pagination.last_page', 2);
    }

    public function test_tenant_user_creation_succeeds_when_reset_email_fails(): void
    {
        $this->authenticateSuperadmin(['admin.tenants.manage']);

        $tenant = $this->tenantWithPlan();

        app()->instance('auth.password', new class
        {
            public function sendResetLink(array $credentials, ?\Closure $callback = null): void
            {
                throw new \RuntimeException('mail transport unavailable');
            }
        });

        $this->postJson('/api/admin/tenants/'.$tenant->uid.'/users', [
            'name' => 'Nuevo Admin',
            'email' => 'nuevo-admin@acme.com',
            'role' => 'owner',
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Nuevo Admin')
            ->assertJsonPath('data.email', 'nuevo-admin@acme.com')
            ->assertJsonPath('data.tenant_uid', $tenant->uid)
            ->assertJsonPath('data.reset_email_sent', false);

        $this->assertDatabaseHas('users', [
            'tenant_id' => $tenant->getKey(),
            'email' => 'nuevo-admin@acme.com',
        ]);
    }

    public function test_tenant_user_creation_sends_password_reset_notification(): void
    {
        app()->forgetInstance('auth.password');
        Notification::fake();
        $this->authenticateSuperadmin(['admin.tenants.manage']);

        $tenant = $this->tenantWithPlan();

        $this->postJson('/api/admin/tenants/'.$tenant->uid.'/users', [
            'name' => 'Nuevo Owner',
            'email' => 'nuevo-owner@acme.com',
            'role' => 'owner',
        ])
            ->assertCreated()
            ->assertJsonPath('data.reset_email_sent', true);

        $user = User::withoutGlobalScopes()
            ->where('email', 'nuevo-owner@acme.com')
            ->firstOrFail();

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_superadmin_tenant_creation_provisions_default_roles(): void
    {
        $this->authenticateSuperadmin(['admin.tenants.manage']);

        $this->permission('dashboard.read', 'dashboard');
        $this->permission('activities.read', 'activities');
        $this->permission('admin.dashboard.read', 'admin');
        $this->permission('plans.manage', 'plans');

        $plan = Plan::query()->create([
            'name' => 'Plan Empresa',
            'price' => 199,
            'status' => 'ACTIVO',
        ]);

        $response = $this->postJson('/api/admin/tenants', [
            'nombre' => 'TT corporation',
            'dominio' => 'ttcorpo.vende-mas.com.co',
            'pais' => 'Colombia',
            'email_contacto' => 'ttinfo@yopmail.com',
            'plan_uid' => $plan->uid,
            'estado' => 'ACTIVO',
        ]);

        $response->assertCreated();

        $tenant = Tenant::query()
            ->where('uid', $response->json('data.uid'))
            ->firstOrFail();

        foreach (['owner', 'manager', 'seller'] as $roleKey) {
            $this->assertDatabaseHas('roles', [
                'tenant_id' => $tenant->getKey(),
                'key' => $roleKey,
                'is_system' => true,
            ]);
        }

        $owner = Role::withoutGlobalScopes()
            ->where('tenant_id', $tenant->getKey())
            ->where('key', 'owner')
            ->firstOrFail();

        $ownerPermissions = $owner->permissions()->pluck('key')->all();

        $this->assertContains('dashboard.read', $ownerPermissions);
        $this->assertContains('activities.read', $ownerPermissions);
        $this->assertNotContains('admin.dashboard.read', $ownerPermissions);
        $this->assertNotContains('plans.manage', $ownerPermissions);
    }

    public function test_superadmin_tenant_creation_provisions_pipeline_base_stages(): void
    {
        $this->authenticateSuperadmin(['admin.tenants.manage']);

        $plan = Plan::query()->create([
            'name' => 'Plan Pipeline',
            'price' => 149,
            'status' => 'ACTIVO',
        ]);

        $response = $this->postJson('/api/admin/tenants', [
            'nombre' => 'Pipeline Corp',
            'dominio' => 'pipeline.vende-mas.com.co',
            'pais' => 'Colombia',
            'email_contacto' => 'pipeline@example.test',
            'plan_uid' => $plan->uid,
            'estado' => 'ACTIVO',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.schema_name', 'tenant_pipeline_corp');

        $tenant = Tenant::query()
            ->where('uid', $response->json('data.uid'))
            ->firstOrFail();

        $this->assertNotEmpty($tenant->schema_name);

        $stages = OpportunityStage::withoutGlobalScopes()
            ->where('tenant_id', $tenant->getKey())
            ->orderBy('position')
            ->pluck('name')
            ->all();

        $this->assertSame(['Leads', 'Contactado', 'Negociación', 'Cerrador'], $stages);
    }

    public function test_superadmin_cannot_create_tenant_with_repeated_name(): void
    {
        $this->authenticateSuperadmin(['admin.tenants.manage']);

        Tenant::query()->create([
            'name' => 'Acme SAS',
            'domain' => 'acme.vende-mas.com.co',
            'status' => 'ACTIVO',
            'is_active' => true,
        ]);

        $this->postJson('/api/admin/tenants', [
            'nombre' => 'acme sas',
            'dominio' => 'acme-2.vende-mas.com.co',
            'estado' => 'ACTIVO',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.nombre.0', 'Ya existe un tenant con este nombre');
    }

    public function test_tenant_user_creation_provisions_and_assigns_owner_role(): void
    {
        app()->forgetInstance('auth.password');
        Notification::fake();
        $this->authenticateSuperadmin(['admin.tenants.manage']);

        $this->permission('dashboard.read', 'dashboard');

        $tenant = $this->tenantWithPlan();

        Role::withoutGlobalScopes()
            ->where('tenant_id', $tenant->getKey())
            ->delete();

        $this->postJson('/api/admin/tenants/'.$tenant->uid.'/users', [
            'name' => 'Admin TT',
            'email' => 'ttadmin2026@yopmail.com',
            'role' => 'owner',
        ])
            ->assertCreated()
            ->assertJsonPath('data.roles.0', 'owner')
            ->assertJsonPath('data.reset_email_sent', true);

        $user = User::withoutGlobalScopes()
            ->where('email', 'ttadmin2026@yopmail.com')
            ->firstOrFail();

        $owner = Role::withoutGlobalScopes()
            ->where('tenant_id', $tenant->getKey())
            ->where('key', 'owner')
            ->firstOrFail();

        $this->assertDatabaseHas('role_user', [
            'role_id' => $owner->getKey(),
            'user_id' => $user->getKey(),
        ]);

        $this->assertContains('dashboard.read', $owner->permissions()->pluck('key')->all());
    }

    public function test_superadmin_can_read_any_tenant_user_access(): void
    {
        $this->authenticateSuperadmin(['users.manage']);

        $tenant = $this->tenantWithPlan();
        $user = User::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->getKey(),
            'name' => 'Tenant Admin',
            'email' => 'tenant-admin@example.test',
            'password' => bcrypt('secret123'),
        ]);

        $this->getJson('/api/users/'.$user->uid.'/access')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.uid', $user->uid);
    }

    public function test_superadmin_can_read_telemetry_summary(): void
    {
        $this->authenticateSuperadmin(['admin.telemetry.read']);

        $tenant = $this->tenantWithPlan();

        SystemLog::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->getKey(),
            'level' => 'critical',
            'message' => '503 Service Unavailable',
            'context' => ['latency_ms' => 240],
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(5),
        ]);

        SystemLog::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->getKey(),
            'level' => 'info',
            'message' => 'Request',
            'context' => ['latency_ms' => 120],
            'created_at' => now()->subMinutes(10),
            'updated_at' => now()->subMinutes(10),
        ]);

        $this->getJson('/api/admin/telemetry/summary')
            ->assertOk()
            ->assertJsonPath('data.errors_24h', 1)
            ->assertJsonPath('data.tenants_with_errors', 1)
            ->assertJsonPath('data.latency_p95_ms', 240)
            ->assertJsonPath('data.errors_by_tenant.0.tenant_nombre', 'Acme Corporation');
    }

    private function authenticateSuperadmin(array $permissionKeys): User
    {
        foreach ($permissionKeys as $key) {
            $this->permission($key, str_contains($key, '.') ? explode('.', $key)[0] : 'admin');
        }

        $admin = User::withoutGlobalScopes()->create([
            'name' => 'Super Admin',
            'email' => 'superadmin@example.test',
            'password' => bcrypt('secret123'),
            'tenant_id' => null,
            'is_platform_admin' => true,
            'two_factor_secret' => 'secret',
            'two_factor_confirmed_at' => now(),
        ]);

        $permissionIds = Permission::query()->whereIn('key', $permissionKeys)->pluck('id')->all();
        $admin->permissions()->sync($permissionIds);

        Sanctum::actingAs($admin, ['access:full', 'platform:admin']);

        return $admin;
    }

    private function permission(string $key, string $module): Permission
    {
        return Permission::query()->firstOrCreate(
            ['key' => $key],
            [
                'module' => $module,
                'action' => $key,
                'description' => $key,
                'scope' => str_starts_with($key, 'admin.') || $key === 'plans.manage'
                    ? Permission::SCOPE_PLATFORM
                    : Permission::SCOPE_TENANT,
            ]
        );
    }

    private function tenantWithPlan(): Tenant
    {
        $plan = Plan::query()->create([
            'name' => 'Plan Pro',
            'price' => 149,
            'status' => 'ACTIVO',
            'features' => [
                'api_calls_month' => 10000,
            ],
        ]);

        return Tenant::query()->create([
            'name' => 'Acme Corporation',
            'domain' => 'acme.test',
            'status' => 'ACTIVO',
            'plan_id' => $plan->getKey(),
            'mrr' => 149,
            'is_active' => true,
        ]);
    }
}
