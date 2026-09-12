<?php

namespace Tests\Feature;

use App\Models\InventoryMovement;
use App\Models\InventoryProduct;
use App\Models\InventoryStock;
use App\Models\Permission;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryBackendIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_master_and_warehouses_include_frontend_summaries(): void
    {
        $user = $this->authenticateWithPermissions(['inventory.read']);

        $activeWarehouse = Warehouse::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Bodega Central',
            'code' => 'BCN01',
            'is_active' => true,
        ]);
        Warehouse::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Bodega Inactiva',
            'code' => 'BIN01',
            'is_active' => false,
        ]);

        $normalProduct = InventoryProduct::query()->create([
            'tenant_id' => $user->tenant_id,
            'sku' => 'SKU-001',
            'name' => 'Camiseta Basica XL',
            'cost_price' => 1200,
            'reorder_point' => 10,
            'is_active' => true,
        ]);
        $outProduct = InventoryProduct::query()->create([
            'tenant_id' => $user->tenant_id,
            'sku' => 'SKU-002',
            'name' => 'Gorra',
            'cost_price' => 500,
            'reorder_point' => 5,
            'is_active' => false,
        ]);

        InventoryStock::query()->create([
            'tenant_id' => $user->tenant_id,
            'product_id' => $normalProduct->getKey(),
            'warehouse_id' => $activeWarehouse->getKey(),
            'physical_stock' => 50,
            'reserved_stock' => 5,
        ]);
        InventoryStock::query()->create([
            'tenant_id' => $user->tenant_id,
            'product_id' => $outProduct->getKey(),
            'warehouse_id' => $activeWarehouse->getKey(),
            'physical_stock' => 0,
            'reserved_stock' => 0,
        ]);

        $this->getJson('/api/inventory/master')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 2)
            ->assertJsonPath('data.summary.products', 2)
            ->assertJsonPath('data.summary.active_products', 1)
            ->assertJsonPath('data.summary.out_of_stock_count', 1)
            ->assertJsonPath('data.summary.total_physical_stock', 50)
            ->assertJsonPath('data.summary.total_reserved_stock', 5)
            ->assertJsonPath('data.summary.total_available_stock', 45)
            ->assertJsonPath('data.data.0.unit_cost', 1200)
            ->assertJsonPath('data.data.0.stocks.0.available_stock', 45);

        $this->getJson('/api/inventory/warehouses')
            ->assertOk()
            ->assertJsonPath('summary.total_warehouses', 2)
            ->assertJsonPath('summary.active_warehouses', 1)
            ->assertJsonPath('summary.total_physical_stock', 50)
            ->assertJsonPath('summary.total_available_stock', 45)
            ->assertJsonPath('summary.total_stock_value', 60000)
            ->assertJsonPath('data.0.summary.sku_count', 1)
            ->assertJsonPath('data.0.summary.total_physical', 50)
            ->assertJsonPath('data.0.summary.total_reserved', 5)
            ->assertJsonPath('data.0.summary.total_available', 45)
            ->assertJsonPath('data.0.summary.total_value', 60000);

        $this->getJson('/api/inventory/master?page=1&per_page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('meta.pagination.current_page', 1)
            ->assertJsonPath('meta.pagination.per_page', 1)
            ->assertJsonPath('meta.pagination.total', 2)
            ->assertJsonPath('data.summary.products', 2);
    }

    public function test_inventory_filters_search_and_active_status_for_frontend(): void
    {
        $user = $this->authenticateWithPermissions(['inventory.read']);

        $central = Warehouse::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Bodega Central',
            'code' => 'BCN01',
            'is_active' => true,
        ]);
        Warehouse::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Bodega Norte',
            'code' => 'NRT02',
            'is_active' => true,
        ]);

        $shirt = InventoryProduct::query()->create([
            'tenant_id' => $user->tenant_id,
            'sku' => 'SKU-CAM-001',
            'name' => 'Camiseta Basica XL',
            'is_active' => true,
        ]);
        $cap = InventoryProduct::query()->create([
            'tenant_id' => $user->tenant_id,
            'sku' => 'SKU-GOR-002',
            'name' => 'Gorra Promocional',
            'is_active' => false,
        ]);

        InventoryMovement::query()->create([
            'tenant_id' => $user->tenant_id,
            'product_id' => $cap->getKey(),
            'to_warehouse_id' => $central->getKey(),
            'performed_by_user_id' => $user->getKey(),
            'type' => 'adjustment_in',
            'quantity' => 10,
            'reference_uid' => 'REF-GORRA-10',
        ]);
        InventoryMovement::query()->create([
            'tenant_id' => $user->tenant_id,
            'product_id' => $shirt->getKey(),
            'to_warehouse_id' => $central->getKey(),
            'performed_by_user_id' => $user->getKey(),
            'type' => 'adjustment_in',
            'quantity' => 5,
            'reference_uid' => 'REF-CAMISETA-5',
        ]);

        $this->getJson('/api/inventory/master?search=gorra')
            ->assertOk()
            ->assertJsonPath('data.summary.products', 1)
            ->assertJsonPath('data.data.0.sku', 'SKU-GOR-002');

        $this->getJson('/api/inventory/master?is_active=false')
            ->assertOk()
            ->assertJsonPath('data.summary.products', 1)
            ->assertJsonPath('data.data.0.name', 'Gorra Promocional');

        $this->getJson('/api/inventory/warehouses?search=NRT')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'NRT02');

        $this->getJson('/api/inventory/movements?search=gorra')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference_uid', 'REF-GORRA-10');
    }

    public function test_warehouse_filter_has_stock_returns_only_stocked_warehouses(): void
    {
        $user = $this->authenticateWithPermissions(['inventory.read']);

        $stockedWarehouse = Warehouse::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Bodega Con Stock',
            'code' => 'STOCK',
            'is_active' => true,
        ]);
        Warehouse::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Bodega Sin Stock',
            'code' => 'EMPTY',
            'is_active' => true,
        ]);
        $zeroStockWarehouse = Warehouse::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Bodega Stock Cero',
            'code' => 'ZERO',
            'is_active' => true,
        ]);

        $product = InventoryProduct::query()->create([
            'tenant_id' => $user->tenant_id,
            'sku' => 'SKU-STOCK',
            'name' => 'Producto Stock',
            'is_active' => true,
        ]);

        InventoryStock::query()->create([
            'tenant_id' => $user->tenant_id,
            'product_id' => $product->getKey(),
            'warehouse_id' => $stockedWarehouse->getKey(),
            'physical_stock' => 12,
            'reserved_stock' => 0,
        ]);
        InventoryStock::query()->create([
            'tenant_id' => $user->tenant_id,
            'product_id' => $product->getKey(),
            'warehouse_id' => $zeroStockWarehouse->getKey(),
            'physical_stock' => 0,
            'reserved_stock' => 0,
        ]);

        $this->getJson('/api/inventory/warehouses?has_stock=true')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'STOCK');

        $this->getJson('/api/inventory/warehouses?has_stock=false')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonMissing(['code' => 'STOCK']);
    }

    public function test_inventory_categories_generate_key_from_name_when_missing(): void
    {
        $this->authenticateWithPermissions(['inventory.read', 'inventory.manage']);

        $this->postJson('/api/inventory/categories', [
            'name' => 'Materia Prima',
            'description' => 'Insumos base',
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Materia Prima')
            ->assertJsonMissingPath('data.key');

        $second = $this->postJson('/api/inventory/categories', [
            'name' => 'Materia Prima',
        ])
            ->assertCreated()
            ->assertJsonMissingPath('data.key')
            ->json('data');

        $this->putJson('/api/inventory/categories/'.$second['uid'], [
            'name' => 'Materia Prima Actualizada',
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Materia Prima Actualizada')
            ->assertJsonMissingPath('data.key');
    }

    public function test_warehouses_are_paginated_with_global_summary(): void
    {
        $user = $this->authenticateWithPermissions(['inventory.read']);

        $firstWarehouse = Warehouse::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Bodega A',
            'code' => 'A',
            'is_active' => true,
        ]);
        Warehouse::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Bodega B',
            'code' => 'B',
            'is_active' => true,
        ]);
        Warehouse::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Bodega C',
            'code' => 'C',
            'is_active' => false,
        ]);

        $product = InventoryProduct::query()->create([
            'tenant_id' => $user->tenant_id,
            'sku' => 'SKU-STOCK',
            'name' => 'Producto Stock',
            'cost_price' => 100,
            'is_active' => true,
        ]);

        InventoryStock::query()->create([
            'tenant_id' => $user->tenant_id,
            'product_id' => $product->getKey(),
            'warehouse_id' => $firstWarehouse->getKey(),
            'physical_stock' => 10,
            'reserved_stock' => 2,
        ]);

        $this->getJson('/api/inventory/warehouses?page=1&per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.pagination.total', 3)
            ->assertJsonPath('meta.pagination.per_page', 2)
            ->assertJsonPath('summary.total_warehouses', 3)
            ->assertJsonPath('summary.active_warehouses', 2)
            ->assertJsonPath('summary.total_physical_stock', 10)
            ->assertJsonPath('summary.total_available_stock', 8)
            ->assertJsonPath('summary.total_stock_value', 1000)
            ->assertJsonPath('summary.stock_physical_total', 10)
            ->assertJsonPath('summary.stock_available_total', 8)
            ->assertJsonPath('summary.stock_value_total', 1000);
    }

    public function test_warehouse_duplicate_code_returns_validation_error(): void
    {
        $user = $this->authenticateWithPermissions(['inventory.read', 'inventory.manage']);

        Warehouse::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Bodega Principal',
            'code' => 'BP-09',
            'is_active' => true,
        ]);

        $this->postJson('/api/inventory/warehouses', [
            'name' => 'Bodega Duplicada',
            'code' => 'BP-09',
            'is_active' => true,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.code.0', 'Ya existe una bodega con ese codigo');
    }

    public function test_products_are_paginated_by_default_for_inventory_listing(): void
    {
        $this->authenticateWithPermissions(['inventory.read']);

        foreach (range(1, 30) as $index) {
            InventoryProduct::query()->create([
                'sku' => 'SKU-PAG-'.$index,
                'name' => 'Producto Paginado '.$index,
                'is_active' => true,
            ]);
        }

        $this->getJson('/api/inventory/products')
            ->assertOk()
            ->assertJsonCount(25, 'data')
            ->assertJsonPath('meta.pagination.total', 30)
            ->assertJsonPath('meta.pagination.per_page', 25);

        $this->getJson('/api/inventory/products?page=2&per_page=10')
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.pagination.current_page', 2)
            ->assertJsonPath('meta.pagination.per_page', 10);
    }

    public function test_stock_entry_options_returns_products_and_warehouses_in_one_request(): void
    {
        $user = $this->authenticateWithPermissions(['inventory.read']);

        $warehouse = Warehouse::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Bodega Central',
            'code' => 'BCN01',
            'is_active' => true,
        ]);

        $product = InventoryProduct::query()->create([
            'tenant_id' => $user->tenant_id,
            'sku' => 'SKU-ENTRY',
            'name' => 'Producto Entrada',
            'cost_price' => 100,
            'sale_price' => 150,
            'is_active' => true,
        ]);

        InventoryStock::query()->create([
            'tenant_id' => $user->tenant_id,
            'product_id' => $product->getKey(),
            'warehouse_id' => $warehouse->getKey(),
            'physical_stock' => 5,
            'reserved_stock' => 1,
        ]);

        $this->getJson('/api/inventory/stock/entry-options?warehouse_uid='.$warehouse->uid)
            ->assertOk()
            ->assertJsonPath('data.warehouses.0.uid', $warehouse->uid)
            ->assertJsonPath('data.products.0.uid', $product->uid)
            ->assertJsonPath('data.products.0.stocks.0.available_stock', 4)
            ->assertJsonPath('data.summary.products', 1);
    }

    public function test_bulk_adjust_and_movements_summary_match_inventory_document(): void
    {
        $user = $this->authenticateWithPermissions(['inventory.read', 'inventory.manage']);

        $warehouse = Warehouse::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Bodega Central',
            'code' => 'BCN01',
            'is_active' => true,
        ]);
        $firstProduct = InventoryProduct::query()->create([
            'tenant_id' => $user->tenant_id,
            'sku' => 'SKU-001',
            'name' => 'Camiseta Basica XL',
        ]);
        $secondProduct = InventoryProduct::query()->create([
            'tenant_id' => $user->tenant_id,
            'sku' => 'SKU-002',
            'name' => 'Gorra',
        ]);

        $this->postJson('/api/inventory/stocks/adjust/bulk', [
            'warehouse_uid' => $warehouse->uid,
            'comment' => 'Entrada OC-0089',
            'items' => [
                ['product_uid' => $firstProduct->uid, 'quantity' => 50],
                ['product_uid' => $secondProduct->uid, 'quantity' => 20],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.summary.items', 2)
            ->assertJsonPath('data.summary.total_quantity', 70);

        $this->postJson('/api/inventory/stocks/adjust', [
            'product_uid' => $firstProduct->uid,
            'warehouse_uid' => $warehouse->uid,
            'operation' => 'out',
            'quantity' => 5,
        ])->assertOk();

        $oldMovement = InventoryMovement::query()->create([
            'tenant_id' => $user->tenant_id,
            'product_id' => $firstProduct->getKey(),
            'from_warehouse_id' => $warehouse->getKey(),
            'to_warehouse_id' => $warehouse->getKey(),
            'performed_by_user_id' => $user->getKey(),
            'type' => 'transfer',
            'quantity' => 1,
        ]);
        $oldMovement->forceFill([
            'created_at' => now()->subMonth(),
            'updated_at' => now()->subMonth(),
        ])->save();

        $this->getJson('/api/inventory/movements/summary')
            ->assertOk()
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.entries', 2)
            ->assertJsonPath('data.transfers', 0)
            ->assertJsonPath('data.adjustments', 1);
    }

    public function test_products_accept_quantity_and_unit_cost_names_from_frontend_contract(): void
    {
        $user = $this->authenticateWithPermissions(['inventory.read', 'inventory.manage']);
        $warehouse = Warehouse::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Bodega Central',
            'code' => 'BCN01',
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/inventory/products', [
            'sku' => 'SKU-003',
            'name' => 'Zapatos',
            'unit_cost' => 900,
            'warehouse_stocks' => [
                ['warehouse_uid' => $warehouse->uid, 'quantity' => 7],
            ],
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.unit_cost', 900);

        $this->assertDatabaseHas('inventory_products', [
            'tenant_id' => $user->tenant_id,
            'sku' => 'SKU-003',
            'cost_price' => 900,
        ]);
        $this->assertDatabaseHas('inventory_stocks', [
            'tenant_id' => $user->tenant_id,
            'warehouse_id' => $warehouse->getKey(),
            'physical_stock' => 7,
        ]);
    }

    public function test_products_accept_sale_price_and_discount_percent_for_quotations(): void
    {
        $this->authenticateWithPermissions(['inventory.read', 'inventory.manage']);

        $response = $this->postJson('/api/inventory/products', [
            'sku' => 'SKU-PRICE-001',
            'name' => 'Producto con precio venta',
            'unit_cost' => 500,
            'sale_price' => 750,
            'discount_percent' => 12.5,
        ]);

        $uid = $response
            ->assertCreated()
            ->assertJsonPath('data.unit_cost', 500)
            ->assertJsonPath('data.sale_price', 750)
            ->assertJsonPath('data.discount_percent', '12.50')
            ->json('data.uid');

        $this->putJson('/api/inventory/products/'.$uid, [
            'sale_price' => 800,
            'discount_percent' => 5,
        ])
            ->assertOk()
            ->assertJsonPath('data.sale_price', 800)
            ->assertJsonPath('data.discount_percent', '5.00');

        $this->getJson('/api/inventory/products?search=precio venta')
            ->assertOk()
            ->assertJsonPath('data.0.sale_price', 800)
            ->assertJsonPath('data.0.discount_percent', '5.00');

        $fallback = $this->postJson('/api/inventory/products', [
            'sku' => 'SKU-PRICE-002',
            'name' => 'Producto fallback',
            'cost_price' => 333,
        ]);

        $fallback->assertCreated()
            ->assertJsonPath('data.sale_price', 333)
            ->assertJsonPath('data.discount_percent', '0.00');
    }

    private function authenticateWithPermissions(array $permissionKeys): User
    {
        $tenant = Tenant::query()->create([
            'name' => 'Tenant Test',
            'status' => 'active',
            'is_active' => true,
        ]);

        foreach ($permissionKeys as $key) {
            Permission::query()->firstOrCreate(
                ['key' => $key],
                [
                    'module' => 'inventory',
                    'action' => 'manage',
                    'description' => $key,
                ]
            );
        }

        $user = User::query()->create([
            'tenant_id' => $tenant->getKey(),
            'name' => 'Tester',
            'email' => 'tester+' . uniqid() . '@example.test',
            'password' => bcrypt('secret123'),
        ]);

        $permissionIds = Permission::query()->whereIn('key', $permissionKeys)->pluck('id')->all();
        $user->permissions()->sync($permissionIds);

        Sanctum::actingAs($user, ['access:full', 'tenant:' . $tenant->uid]);

        return $user;
    }
}
