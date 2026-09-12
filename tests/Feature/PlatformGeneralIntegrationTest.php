<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Currency;
use App\Models\InventoryProduct;
use App\Models\Permission;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlatformGeneralIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_auth_init_returns_user_tenant_modules_and_localization(): void
    {
        $currency = Currency::query()->create([
            'code' => 'ARS',
            'name' => 'Peso argentino',
            'symbol' => '$',
        ]);
        $plan = Plan::query()->create([
            'name' => 'Pro',
            'tier' => 'pro',
            'price' => 99,
            'status' => 'active',
            'features' => [
                'inventory' => true,
            ],
        ]);
        $tenant = Tenant::query()->create([
            'name' => 'Empresa Demo',
            'status' => 'active',
            'is_active' => true,
            'plan_id' => $plan->getKey(),
            'currency_id' => $currency->getKey(),
        ]);
        $tenant->forceFill([
            'timezone' => 'America/Argentina/Buenos_Aires',
            'date_format' => 'd/m/Y',
        ])->save();

        $user = $this->tenantUser($tenant, [
            'dashboard.read',
            'inventory.read',
            'inventory.manage',
            'users.manage',
        ]);
        $user->forceFill(['timezone' => 'America/Bogota'])->save();
        $role = Role::query()->create([
            'tenant_id' => $tenant->getKey(),
            'name' => 'Admin',
            'key' => 'admin',
        ]);
        $user->roles()->attach($role->getKey());

        Sanctum::actingAs($user, ['access:full', 'tenant:' . $tenant->uid]);

        $this->getJson('/api/auth/init')
            ->assertOk()
            ->assertJsonPath('data.user.uid', $user->uid)
            ->assertJsonPath('data.user.role', 'admin')
            ->assertJsonPath('data.tenant.uid', $tenant->uid)
            ->assertJsonPath('data.tenant.plan', 'pro')
            ->assertJsonPath('data.localization.currency', 'ARS')
            ->assertJsonPath('data.localization.currency_symbol', '$')
            ->assertJsonPath('data.localization.locale', 'es-AR')
            ->assertJsonPath('data.localization.timezone', 'America/Argentina/Buenos_Aires')
            ->assertJsonPath('data.localization.date_format', 'DD/MM/YYYY')
            ->assertJsonPath('data.localization.language', 'es')
            ->assertJsonPath('data.modules.0.key', 'dashboard')
            ->assertJsonPath('data.modules.0.enabled', true)
            ->assertJsonPath('data.modules.1.key', 'inventory')
            ->assertJsonPath('data.modules.1.enabled', true)
            ->assertJsonPath('data.modules.1.permissions.0', 'read')
            ->assertJsonPath('data.modules.1.permissions.1', 'manage')
            ->assertJsonPath('data.modules.10.key', 'settings')
            ->assertJsonPath('data.modules.10.enabled', true)
            ->assertJsonPath('data.features.inventory', true)
            ->assertJsonPath('data.features.reports', false)
            ->assertJsonPath('data.features.multicurrency', false)
            ->assertJsonPath('data.features.custom_fields', false);
    }

    public function test_auth_init_separates_platform_admin_permissions_from_tenant_permissions(): void
    {
        foreach (['users.manage', 'admin.dashboard.read'] as $key) {
            Permission::query()->firstOrCreate(
                ['key' => $key],
                [
                    'module' => str_contains($key, '.') ? explode('.', $key)[0] : 'platform',
                    'action' => $key,
                    'description' => $key,
                ]
            );
        }

        $admin = User::withoutGlobalScopes()->create([
            'tenant_id' => null,
            'name' => 'Platform Admin',
            'email' => 'platform-admin+'.uniqid().'@example.test',
            'password' => bcrypt('secret123'),
            'is_platform_admin' => true,
        ]);
        $admin->permissions()->sync(Permission::query()->whereIn('key', ['users.manage', 'admin.dashboard.read'])->pluck('id')->all());

        Sanctum::actingAs($admin, ['access:full', 'platform:admin']);

        $response = $this->getJson('/api/auth/init')
            ->assertOk()
            ->assertJsonPath('data.user.is_platform_admin', true)
            ->assertJsonPath('data.modules.0.enabled', false)
            ->assertJsonPath('data.modules.0.items.0.enabled', false)
            ->assertJsonPath('data.modules.10.key', 'settings')
            ->assertJsonPath('data.modules.10.enabled', false)
            ->assertJsonPath('data.modules.10.permissions', []);

        $this->assertSame([], $response->json('data.permissions.effective'));
        $this->assertContains('users.manage', $response->json('data.admin_permissions'));
        $this->assertContains('admin.dashboard.read', $response->json('data.admin_permissions'));
    }

    public function test_auth_init_includes_sales_catalog_item(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Tenant Catalog',
            'status' => 'active',
            'is_active' => true,
        ]);
        $user = $this->tenantUser($tenant, [
            'products.read',
            'products.manage',
        ]);

        Sanctum::actingAs($user, ['access:full', 'tenant:' . $tenant->uid]);

        $response = $this->getJson('/api/auth/init')->assertOk();
        $sales = collect($response->json('data.modules'))->firstWhere('key', 'sales');
        $catalog = collect($sales['items'])->firstWhere('key', 'catalog');

        $this->assertSame('Catalogo Comercial', $catalog['label']);
        $this->assertTrue($catalog['enabled']);
        $this->assertSame(['read', 'manage'], $catalog['permissions']);
    }

    public function test_auth_init_and_permission_middleware_filter_modules_by_active_plan(): void
    {
        Permission::query()->firstOrCreate(
            ['key' => 'inventory.read'],
            ['module' => 'inventory', 'action' => 'read', 'description' => 'inventory.read']
        );
        Permission::query()->firstOrCreate(
            ['key' => 'opportunities.read'],
            ['module' => 'opportunities', 'action' => 'read', 'description' => 'opportunities.read']
        );

        $plan = Plan::query()->create([
            'name' => 'Inventory Only',
            'price' => 49,
            'status' => 'active',
            'features' => [
                'modules' => ['inventario'],
            ],
        ]);
        $tenant = Tenant::query()->create([
            'name' => 'Tenant Plan Filter',
            'status' => 'active',
            'is_active' => true,
            'plan_id' => $plan->getKey(),
        ]);
        $user = $this->tenantUser($tenant, ['inventory.read', 'opportunities.read']);

        Sanctum::actingAs($user, ['access:full', 'tenant:' . $tenant->uid]);

        $response = $this->getJson('/api/auth/init')->assertOk();
        $modules = collect($response->json('data.modules'));

        $this->assertTrue($modules->firstWhere('key', 'inventory')['enabled']);
        $this->assertFalse($modules->firstWhere('key', 'sales')['enabled']);
        $this->assertContains('inventory.read', $response->json('data.permissions.effective'));
        $this->assertNotContains('opportunities.read', $response->json('data.permissions.effective'));

        $this->getJson('/api/opportunities')
            ->assertForbidden()
            ->assertJsonPath('message', 'No autorizado por el plan activo');
    }

    public function test_empty_plan_modules_means_no_paid_modules_are_enabled(): void
    {
        Permission::query()->firstOrCreate(
            ['key' => 'inventory.read'],
            ['module' => 'inventory', 'action' => 'read', 'description' => 'inventory.read']
        );
        Permission::query()->firstOrCreate(
            ['key' => 'opportunities.read'],
            ['module' => 'opportunities', 'action' => 'read', 'description' => 'opportunities.read']
        );
        Permission::query()->firstOrCreate(
            ['key' => 'settings.manage'],
            ['module' => 'settings', 'action' => 'manage', 'description' => 'settings.manage']
        );

        $plan = Plan::query()->create([
            'name' => 'No Modules Plan',
            'price' => 0,
            'status' => 'active',
            'features' => [
                'modules' => [],
            ],
        ]);
        $tenant = Tenant::query()->create([
            'name' => 'Tenant No Modules',
            'status' => 'active',
            'is_active' => true,
            'plan_id' => $plan->getKey(),
        ]);
        $user = $this->tenantUser($tenant, ['inventory.read', 'opportunities.read', 'settings.manage']);

        Sanctum::actingAs($user, ['access:full', 'tenant:' . $tenant->uid]);

        $response = $this->getJson('/api/auth/init')->assertOk();
        $modules = collect($response->json('data.modules'));

        $this->assertFalse($modules->firstWhere('key', 'inventory')['enabled']);
        $this->assertFalse($modules->firstWhere('key', 'sales')['enabled']);
        $this->assertTrue($modules->firstWhere('key', 'settings')['enabled']);
        $this->assertNotContains('inventory.read', $response->json('data.permissions.effective'));
        $this->assertNotContains('opportunities.read', $response->json('data.permissions.effective'));
        $this->assertContains('settings.manage', $response->json('data.permissions.effective'));
    }

    public function test_me_features_resolves_authenticated_tenant_plan_flags(): void
    {
        $plan = Plan::query()->create([
            'name' => 'Feature Plan',
            'price' => 49,
            'status' => 'active',
            'features' => [
                'modules' => ['inventario'],
                'multicurrency' => true,
                'custom_fields' => true,
            ],
        ]);
        $tenant = Tenant::query()->create([
            'name' => 'Tenant Feature Flags',
            'status' => 'active',
            'is_active' => true,
            'plan_id' => $plan->getKey(),
        ]);
        $user = $this->tenantUser($tenant, []);

        Sanctum::actingAs($user, ['access:full', 'tenant:' . $tenant->uid]);

        $this->getJson('/api/me/features')
            ->assertOk()
            ->assertJsonPath('data.inventory', true)
            ->assertJsonPath('data.reports', false)
            ->assertJsonPath('data.multicurrency', true)
            ->assertJsonPath('data.custom_fields', true)
            ->assertJsonMissing(['plan_id' => $plan->getKey()]);
    }

    public function test_me_features_defaults_missing_plan_features_to_false(): void
    {
        $plan = Plan::query()->create([
            'name' => 'Empty Feature Plan',
            'price' => 0,
            'status' => 'active',
            'features' => [],
        ]);
        $tenant = Tenant::query()->create([
            'name' => 'Tenant Empty Features',
            'status' => 'active',
            'is_active' => true,
            'plan_id' => $plan->getKey(),
        ]);
        $user = $this->tenantUser($tenant, []);

        Sanctum::actingAs($user, ['access:full', 'tenant:' . $tenant->uid]);

        $this->getJson('/api/me/features')
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'message' => null,
                'data' => [
                    'inventory' => false,
                    'reports' => false,
                    'multicurrency' => false,
                    'custom_fields' => false,
                ],
                'meta' => null,
                'errors' => null,
            ]);
    }

    public function test_plan_feature_middleware_blocks_disabled_features_before_module_endpoints(): void
    {
        $plan = Plan::query()->create([
            'name' => 'Blocked Feature Plan',
            'price' => 0,
            'status' => 'active',
            'features' => [],
        ]);
        $tenant = Tenant::query()->create([
            'name' => 'Tenant Blocked Features',
            'status' => 'active',
            'is_active' => true,
            'plan_id' => $plan->getKey(),
        ]);
        $user = $this->tenantUser($tenant, [
            'inventory.read',
            'reports.read',
            'finance.read',
            'custom-fields.manage',
        ]);

        Sanctum::actingAs($user, ['access:full', 'tenant:' . $tenant->uid]);

        foreach ([
            '/api/inventory/master',
            '/api/reports/filters',
            '/api/currency/rates',
            '/api/custom-fields/modules',
        ] as $endpoint) {
            $this->getJson($endpoint)
                ->assertForbidden()
                ->assertExactJson([
                    'message' => 'Este módulo no está disponible en tu plan',
                ]);
        }
    }

    public function test_auth_init_disables_modules_and_items_when_plan_features_are_disabled(): void
    {
        $plan = Plan::query()->create([
            'name' => 'Menu Feature Plan',
            'price' => 0,
            'status' => 'active',
            'features' => [
                'inventory' => false,
                'reports' => false,
                'multicurrency' => false,
                'custom_fields' => false,
            ],
        ]);
        $tenant = Tenant::query()->create([
            'name' => 'Tenant Menu Features',
            'status' => 'active',
            'is_active' => true,
            'plan_id' => $plan->getKey(),
        ]);
        $user = $this->tenantUser($tenant, [
            'inventory.read',
            'reports.read',
            'finance.read',
            'finance.manage',
            'settings.manage',
            'custom-fields.manage',
        ]);

        Sanctum::actingAs($user, ['access:full', 'tenant:' . $tenant->uid]);

        $response = $this->getJson('/api/auth/init')->assertOk();
        $modules = collect($response->json('data.modules'));
        $salesItems = collect($modules->firstWhere('key', 'sales')['items']);
        $settingsItems = collect($modules->firstWhere('key', 'settings')['items']);

        $this->assertFalse($modules->firstWhere('key', 'inventory')['enabled']);
        $this->assertSame([], $modules->firstWhere('key', 'inventory')['permissions']);
        $this->assertFalse($modules->firstWhere('key', 'reports')['enabled']);
        $this->assertSame([], $modules->firstWhere('key', 'reports')['permissions']);
        $this->assertFalse($salesItems->firstWhere('key', 'multi-currency')['enabled']);
        $this->assertSame([], $salesItems->firstWhere('key', 'multi-currency')['permissions']);
        $this->assertFalse($settingsItems->firstWhere('key', 'custom-fields')['enabled']);
        $this->assertSame([], $settingsItems->firstWhere('key', 'custom-fields')['permissions']);
        $this->assertSame([
            'inventory' => false,
            'reports' => false,
            'multicurrency' => false,
            'custom_fields' => false,
        ], $response->json('data.features'));
    }

    public function test_plan_feature_middleware_allows_enabled_features(): void
    {
        $plan = Plan::query()->create([
            'name' => 'Enabled Feature Plan',
            'price' => 49,
            'status' => 'active',
            'features' => [
                'inventory' => true,
                'reports' => true,
                'multicurrency' => true,
                'custom_fields' => true,
            ],
        ]);
        $tenant = Tenant::query()->create([
            'name' => 'Tenant Enabled Features',
            'status' => 'active',
            'is_active' => true,
            'plan_id' => $plan->getKey(),
        ]);
        $user = $this->tenantUser($tenant, [
            'inventory.read',
            'reports.read',
            'finance.read',
            'custom-fields.manage',
        ]);

        Sanctum::actingAs($user, ['access:full', 'tenant:' . $tenant->uid]);

        foreach ([
            '/api/inventory/master',
            '/api/reports/filters',
            '/api/currency/rates',
            '/api/custom-fields/modules',
        ] as $endpoint) {
            $this->getJson($endpoint)->assertOk();
        }
    }

    public function test_localization_endpoint_returns_frontend_fields(): void
    {
        $currency = Currency::query()->create([
            'code' => 'COP',
            'name' => 'Peso colombiano',
            'symbol' => '$',
        ]);
        $tenant = Tenant::query()->create([
            'name' => 'Tenant Localization',
            'status' => 'active',
            'is_active' => true,
            'currency_id' => $currency->getKey(),
        ]);
        $tenant->forceFill(['timezone' => 'America/Bogota', 'date_format' => 'd/m/Y'])->save();
        $user = $this->tenantUser($tenant, ['settings.manage']);
        Sanctum::actingAs($user, ['access:full', 'tenant:' . $tenant->uid]);

        $this->getJson('/api/settings/localization')
            ->assertOk()
            ->assertJsonPath('data.currency', 'COP')
            ->assertJsonPath('data.currency_symbol', '$')
            ->assertJsonPath('data.locale', 'es-CO')
            ->assertJsonPath('data.language', 'es')
            ->assertJsonPath('data.date_format', 'DD/MM/YYYY');
    }

    public function test_tenant_dynamic_option_endpoints_are_available(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Tenant Options',
            'status' => 'active',
            'is_active' => true,
        ]);
        $user = $this->tenantUser($tenant, []);
        Sanctum::actingAs($user, ['access:full', 'tenant:' . $tenant->uid]);

        Account::query()->create([
            'tenant_id' => $tenant->getKey(),
            'owner_user_id' => $user->getKey(),
            'name' => 'Cuenta Salud',
            'document' => 'SALUD-1',
            'industry' => 'Salud',
        ]);
        Product::query()->create([
            'tenant_id' => $tenant->getKey(),
            'name' => 'CRM Enterprise',
            'type' => 'service',
            'sku' => 'CRM-ENT',
            'status' => 'active',
        ]);
        $inventoryProduct = InventoryProduct::query()->create([
            'tenant_id' => $tenant->getKey(),
            'name' => 'Licencia Base',
            'sku' => 'LIC-BASE',
            'is_active' => true,
        ]);
        $linkedInventoryProduct = InventoryProduct::query()->create([
            'tenant_id' => $tenant->getKey(),
            'name' => 'Servidor Base',
            'sku' => 'SRV-BASE',
            'is_active' => true,
        ]);
        Product::query()->create([
            'tenant_id' => $tenant->getKey(),
            'inventory_product_id' => $linkedInventoryProduct->getKey(),
            'name' => 'Servidor Base Catalogo',
            'type' => 'product',
            'sku' => 'SRV-BASE-CAT',
            'status' => 'active',
        ]);

        foreach ([
            '/api/tenant/payment-methods',
            '/api/tenant/lead-origins',
            '/api/tenant/institution-types',
            '/api/tenant/company-sizes',
            '/api/tenant/lost-reason-categories',
            '/api/tenant/activity-types',
            '/api/tenant/commission-plan-types',
        ] as $endpoint) {
            $this->getJson($endpoint)
                ->assertOk()
                ->assertJsonStructure(['data' => [['uid', 'name', 'key']]]);
        }

        $this->getJson('/api/tenant/industries')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Salud', 'key' => 'salud']);

        $response = $this->getJson('/api/tenant/opportunity-products')
            ->assertOk()
            ->assertJsonFragment(['name' => 'CRM Enterprise', 'key' => 'CRM-ENT'])
            ->assertJsonFragment([
                'name' => 'Licencia Base',
                'key' => 'LIC-BASE',
                'source' => 'inventory',
                'product_uid' => $inventoryProduct->uid,
                'catalog_product_uid' => null,
            ])
            ->assertJsonFragment([
                'name' => 'Servidor Base Catalogo',
                'key' => 'SRV-BASE-CAT',
                'source' => 'catalog',
                'product_uid' => $linkedInventoryProduct->uid,
            ]);

        $options = collect($response->json('data'));
        $this->assertFalse($options->contains(fn (array $option) => $option['key'] === 'SRV-BASE' && $option['source'] === 'inventory'));
    }

    public function test_rbac_roles_can_filter_permissions_by_active_plan_modules(): void
    {
        $inventoryPermission = Permission::query()->create([
            'key' => 'inventory.read',
            'module' => 'inventory',
            'action' => 'read',
            'description' => 'inventory.read',
        ]);
        $salesPermission = Permission::query()->create([
            'key' => 'opportunities.read',
            'module' => 'opportunities',
            'action' => 'read',
            'description' => 'opportunities.read',
        ]);
        Permission::query()->create([
            'key' => 'users.manage',
            'module' => 'users',
            'action' => 'manage',
            'description' => 'users.manage',
        ]);

        $plan = Plan::query()->create([
            'name' => 'Inventory Only',
            'price' => 49,
            'status' => 'active',
            'features' => [
                'modules' => ['inventario'],
            ],
        ]);
        $tenant = Tenant::query()->create([
            'name' => 'Tenant RBAC',
            'status' => 'active',
            'is_active' => true,
            'plan_id' => $plan->getKey(),
        ]);
        $role = Role::query()->create([
            'tenant_id' => $tenant->getKey(),
            'name' => 'Operador',
            'key' => 'operator',
        ]);
        $role->permissions()->sync([$inventoryPermission->getKey(), $salesPermission->getKey()]);

        $user = $this->tenantUser($tenant, ['users.manage']);
        Sanctum::actingAs($user, ['access:full', 'tenant:' . $tenant->uid]);

        $this->getJson('/api/rbac/roles?only_active_modules=true')
            ->assertOk()
            ->assertJsonPath('data.0.permissions.0.key', 'inventory.read')
            ->assertJsonMissing(['key' => 'opportunities.read']);
    }

    private function tenantUser(Tenant $tenant, array $permissionKeys): User
    {
        foreach ($permissionKeys as $key) {
            Permission::query()->firstOrCreate(
                ['key' => $key],
                [
                    'module' => str_contains($key, '.') ? explode('.', $key)[0] : 'platform',
                    'action' => $key,
                    'description' => $key,
                ]
            );
        }

        $user = User::query()->create([
            'tenant_id' => $tenant->getKey(),
            'name' => 'Platform Owner',
            'email' => 'platform-owner+' . uniqid() . '@example.test',
            'password' => bcrypt('secret123'),
        ]);

        if ($permissionKeys !== []) {
            $permissionIds = Permission::query()->whereIn('key', $permissionKeys)->pluck('id')->all();
            $user->permissions()->sync($permissionIds);
        }

        return $user;
    }
}
