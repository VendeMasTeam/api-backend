<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\InventoryCategory;
use App\Models\InventoryProduct;
use App\Models\InventoryStock;
use App\Models\Permission;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportsBackendIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_report_matches_frontend_contract(): void
    {
        $user = $this->authenticateWithPermissions(['reports.read']);
        $account = Account::query()->create([
            'tenant_id' => $user->tenant_id,
            'owner_user_id' => $user->getKey(),
            'name' => 'Distribuidora ABC',
            'document' => 'REP-' . uniqid(),
        ]);

        $quotation = Quotation::query()->create([
            'tenant_id' => $user->tenant_id,
            'owner_user_id' => $user->getKey(),
            'created_by_user_id' => $user->getKey(),
            'quoteable_type' => Account::class,
            'quoteable_id' => $account->getKey(),
            'quote_number' => 'REP-Q-' . uniqid(),
            'title' => 'Venta demo',
            'status' => 'approved',
            'currency' => 'USD',
        ]);

        QuotationItem::query()->create([
            'tenant_id' => $user->tenant_id,
            'quotation_id' => $quotation->getKey(),
            'description' => 'Producto Reporte',
            'quantity' => 2,
            'unit_price' => 100,
            'list_unit_price' => 100,
            'net_unit_price' => 100,
        ]);

        $this->getJson('/api/reports/sales?tab=status&period=Este mes')
            ->assertOk()
            ->assertJsonPath('data.kpis.Total Generadas', 1)
            ->assertJsonPath('data.kpis.Aprobadas', 1)
            ->assertJsonPath('data.chart_data.series.0', 1)
            ->assertJsonPath('data.chart_data.labels.0', 'Aprobadas')
            ->assertJsonPath('data.table_data.0.cliente', 'Distribuidora ABC')
            ->assertJsonPath('data.table_data.0.statusBadge.color', 'success')
            ->assertJsonPath('data.monthly_sales', 0);
    }

    public function test_inventory_risk_report_and_filters_match_frontend_contract(): void
    {
        $user = $this->authenticateWithPermissions(['reports.read']);
        $warehouse = Warehouse::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Bodega Principal',
            'code' => 'main',
            'is_active' => true,
        ]);
        $category = InventoryCategory::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Electronicos',
            'key' => 'electronics',
        ]);
        $product = InventoryProduct::query()->create([
            'tenant_id' => $user->tenant_id,
            'category_id' => $category->getKey(),
            'sku' => 'SKU-001',
            'name' => 'Producto Critico',
            'reorder_point' => 10,
            'is_active' => true,
        ]);

        InventoryStock::query()->create([
            'tenant_id' => $user->tenant_id,
            'product_id' => $product->getKey(),
            'warehouse_id' => $warehouse->getKey(),
            'physical_stock' => 3,
            'reserved_stock' => 0,
        ]);

        $this->getJson('/api/reports/inventory?tab=risk&period=Este mes&warehouse=' . $warehouse->uid . '&category=' . $category->uid)
            ->assertOk()
            ->assertJsonPath('data.kpis.Productos', 1)
            ->assertJsonPath('data.kpis.Stock bajo', 1)
            ->assertJsonPath('data.most_critical.sku', 'SKU-001')
            ->assertJsonPath('data.most_critical.available', 3)
            ->assertJsonPath('data.rupture_risk.critical_products_count', 1)
            ->assertJsonPath('data.table_data.0.producto', 'Producto Critico');

        $this->getJson('/api/reports/inventory?tab=warehouse&period=Este mes')
            ->assertOk()
            ->assertJsonPath('data.kpis.Productos', 1)
            ->assertJsonPath('data.chart_data.categories.0', 'Bodega Principal');

        $this->getJson('/api/reports/filters')
            ->assertOk()
            ->assertJsonPath('data.warehouses.0.value', $warehouse->uid)
            ->assertJsonPath('data.warehouses.0.label', 'Bodega Principal')
            ->assertJsonPath('data.categories.0.value', $category->uid)
            ->assertJsonPath('data.categories.0.label', 'Electronicos');
    }

    private function authenticateWithPermissions(array $permissionKeys): User
    {
        $tenant = Tenant::query()->create([
            'name' => 'Tenant Reports',
            'status' => 'active',
            'is_active' => true,
        ]);

        foreach ($permissionKeys as $key) {
            Permission::query()->firstOrCreate(
                ['key' => $key],
                [
                    'module' => 'reports',
                    'action' => $key,
                    'description' => $key,
                ]
            );
        }

        $user = User::query()->create([
            'tenant_id' => $tenant->getKey(),
            'name' => 'Reports Owner',
            'email' => 'reports-owner+' . uniqid() . '@example.test',
            'password' => bcrypt('secret123'),
        ]);

        $permissionIds = Permission::query()->whereIn('key', $permissionKeys)->pluck('id')->all();
        $user->permissions()->sync($permissionIds);

        Sanctum::actingAs($user, ['access:full', 'tenant:' . $tenant->uid]);

        return $user;
    }
}
