<?php

namespace Tests\Feature;

use App\Models\DocumentType;
use App\Models\InventoryCategory;
use App\Models\InventoryProduct;
use App\Models\Opportunity;
use App\Models\OpportunityStage;
use App\Models\Permission;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use ZipArchive;

class MissingBackendItemsIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_auth_init_includes_tasks_expenses_and_purchases_modules(): void
    {
        $this->authenticateWithPermissions([
            'tasks.read',
            'expenses.read',
            'purchases.read',
        ]);

        $this->getJson('/api/auth/init')
            ->assertOk()
            ->assertJsonFragment(['key' => 'tasks', 'label' => 'Tareas', 'enabled' => true])
            ->assertJsonFragment(['key' => 'expenses', 'label' => 'Gastos', 'enabled' => true])
            ->assertJsonFragment(['key' => 'purchases', 'label' => 'Compras', 'enabled' => true]);
    }

    public function test_opportunity_board_accepts_real_pagination(): void
    {
        $user = $this->authenticateWithPermissions(['opportunities.read']);
        $stage = OpportunityStage::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Leads',
            'key' => 'leads-test',
            'position' => 1,
            'is_active' => true,
        ]);

        foreach (['Uno', 'Dos'] as $title) {
            Opportunity::query()->create([
                'tenant_id' => $user->tenant_id,
                'owner_user_id' => $user->getKey(),
                'stage_id' => $stage->getKey(),
                'title' => $title,
                'amount' => 100,
            ]);
        }

        $this->getJson('/api/opportunities/board?per_page=1&page=1')
            ->assertOk()
            ->assertJsonPath('data.pagination.per_page', 1)
            ->assertJsonPath('data.pagination.total', 2);
    }

    public function test_opportunity_board_can_be_reordered_inside_stage(): void
    {
        $user = $this->authenticateWithPermissions(['opportunities.read', 'opportunities.manage']);
        $stage = OpportunityStage::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Negociacion',
            'key' => 'negociacion-reorder',
            'position' => 1,
            'is_active' => true,
        ]);

        $first = Opportunity::query()->create([
            'tenant_id' => $user->tenant_id,
            'owner_user_id' => $user->getKey(),
            'stage_id' => $stage->getKey(),
            'title' => 'Primera',
            'amount' => 100,
            'kanban_position' => 1,
        ]);
        $second = Opportunity::query()->create([
            'tenant_id' => $user->tenant_id,
            'owner_user_id' => $user->getKey(),
            'stage_id' => $stage->getKey(),
            'title' => 'Segunda',
            'amount' => 200,
            'kanban_position' => 2,
        ]);

        $this->postJson('/api/opportunities/board/reorder', [
            'stage_uid' => $stage->uid,
            'ordered_opportunity_uids' => [$second->uid, $first->uid],
        ])
            ->assertOk()
            ->assertJsonPath('data.ordered_opportunity_uids.0', $second->uid)
            ->assertJsonPath('data.ordered_opportunity_uids.1', $first->uid);

        $this->getJson('/api/opportunities/board')
            ->assertOk()
            ->assertJsonPath('data.stages.0.items.0.uid', $second->uid)
            ->assertJsonPath('data.stages.0.items.0.kanban_position', 1)
            ->assertJsonPath('data.stages.0.items.1.uid', $first->uid)
            ->assertJsonPath('data.stages.0.items.1.kanban_position', 2);
    }

    public function test_opportunity_board_shows_recent_closed_and_history_lists_all(): void
    {
        $user = $this->authenticateWithPermissions(['opportunities.read']);
        $activeStage = OpportunityStage::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Leads',
            'key' => 'leads-history',
            'position' => 1,
            'is_active' => true,
        ]);
        $wonStage = OpportunityStage::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Cerrador',
            'key' => 'cerrador',
            'position' => 2,
            'is_won' => true,
            'is_active' => true,
        ]);

        $recentClosed = Opportunity::query()->create([
            'tenant_id' => $user->tenant_id,
            'owner_user_id' => $user->getKey(),
            'stage_id' => $wonStage->getKey(),
            'title' => 'Cerrada reciente',
            'amount' => 300,
            'won_at' => now()->subDays(2),
        ]);
        $oldClosed = Opportunity::query()->create([
            'tenant_id' => $user->tenant_id,
            'owner_user_id' => $user->getKey(),
            'stage_id' => $wonStage->getKey(),
            'title' => 'Cerrada antigua',
            'amount' => 400,
            'won_at' => now()->subDays(10),
        ]);
        Opportunity::query()->create([
            'tenant_id' => $user->tenant_id,
            'owner_user_id' => $user->getKey(),
            'stage_id' => $activeStage->getKey(),
            'title' => 'Activa',
            'amount' => 500,
        ]);

        $board = $this->getJson('/api/opportunities/board?closed_days=7')
            ->assertOk()
            ->json('data.stages');
        $boardUids = collect($board)->flatMap(fn (array $stage) => collect($stage['items'])->pluck('uid'));

        $this->assertTrue($boardUids->contains($recentClosed->uid));
        $this->assertFalse($boardUids->contains($oldClosed->uid));

        $this->getJson('/api/opportunities/history?status=closed&page=1&per_page=10')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 2)
            ->assertJsonFragment(['uid' => $oldClosed->uid])
            ->assertJsonFragment(['uid' => $recentClosed->uid]);
    }

    public function test_tasks_are_paginated_by_default(): void
    {
        $user = $this->authenticateWithPermissions(['tasks.read']);

        foreach (range(1, 30) as $index) {
            Task::query()->create([
                'tenant_id' => $user->tenant_id,
                'owner_user_id' => $user->getKey(),
                'title' => 'Tarea '.$index,
                'status' => 'pending',
            ]);
        }

        $this->getJson('/api/tasks')
            ->assertOk()
            ->assertJsonCount(25, 'data')
            ->assertJsonPath('meta.pagination.per_page', 25)
            ->assertJsonPath('meta.pagination.total', 30);
    }

    public function test_document_types_can_be_deleted(): void
    {
        $user = $this->authenticateWithPermissions(['documents.manage']);
        $documentType = DocumentType::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Contrato eliminable',
            'is_required' => false,
            'is_active' => true,
        ]);

        $this->deleteJson('/api/document-types/'.$documentType->uid)
            ->assertOk()
            ->assertJsonPath('message', 'Tipo de documento eliminado');

        $this->assertDatabaseMissing('document_types', ['id' => $documentType->getKey()]);
    }

    public function test_inventory_categories_can_be_filtered_by_search(): void
    {
        $this->authenticateWithPermissions(['inventory.read']);

        InventoryCategory::query()->create([
            'name' => 'Electronicos',
            'key' => 'electronicos',
        ]);

        InventoryCategory::query()->create([
            'name' => 'Papeleria',
            'key' => 'papeleria',
        ]);

        $this->getJson('/api/inventory/categories?search=elect')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Electronicos');
    }

    public function test_requested_export_endpoints_return_downloads(): void
    {
        $this->authenticateWithPermissions([
            'reports.read',
            'contacts.read',
            'inventory.read',
            'finance.read',
        ]);

        foreach ([
            '/api/reports/sales/export',
            '/api/reports/inventory/export',
            '/api/contacts/export',
            '/api/inventory/products/export',
            '/api/inventory/stock/export',
            '/api/sales/finance/invoices/export',
        ] as $endpoint) {
            $response = $this->postJson($endpoint, ['format' => 'csv']);

            $response->assertOk();
            $this->assertStringContainsString('attachment;', $response->headers->get('Content-Disposition'));
        }
    }

    public function test_inventory_products_excel_export_is_a_valid_xlsx_file(): void
    {
        $this->authenticateWithPermissions(['inventory.read']);

        $response = $this->postJson('/api/inventory/products/export', [
            'format' => 'excel',
            'filters' => [
                'search' => '',
            ],
        ]);

        $response->assertOk();
        $this->assertStringContainsString('.xlsx', $response->headers->get('Content-Disposition'));
        $this->assertSame('PK', substr($response->getContent(), 0, 2));

        $path = tempnam(storage_path('framework/testing'), 'products-export-');
        file_put_contents($path, $response->getContent());

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        $this->assertNotFalse($zip->locateName('[Content_Types].xml'));
        $this->assertNotFalse($zip->locateName('xl/workbook.xml'));
        $this->assertNotFalse($zip->locateName('xl/worksheets/sheet1.xml'));
        $styles = $zip->getFromName('xl/styles.xml');
        $this->assertIsString($styles);
        $this->assertStringContainsString('patternType="gray125"', $styles);
        $this->assertStringContainsString('fillId="2"', $styles);
        $zip->close();
        unlink($path);
    }

    public function test_inventory_products_pdf_export_uses_the_shared_pdf_template(): void
    {
        $user = $this->authenticateWithPermissions(['inventory.read']);

        InventoryProduct::query()->create([
            'tenant_id' => $user->tenant_id,
            'sku' => 'SKU-PDF-1',
            'name' => 'Producto PDF',
            'cost_price' => 1200,
            'reorder_point' => 5,
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/inventory/products/export', [
            'format' => 'pdf',
            'filters' => [
                'search' => null,
                'warehouse_uid' => '',
            ],
        ]);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-1.4', $response->getContent());
        $this->assertStringContainsString('/BaseFont /Helvetica-Bold', $response->getContent());
        $this->assertStringContainsString('Exportacion / Reporte', $response->getContent());
        $this->assertStringContainsString('SKU-PDF-1', $response->getContent());
        $this->assertStringContainsString('Producto PDF', $response->getContent());
        $this->assertStringNotContainsString('Search', $response->getContent());
        $this->assertStringNotContainsString('null', $response->getContent());
    }

    private function authenticateWithPermissions(array $permissionKeys): User
    {
        $tenant = Tenant::query()->create([
            'name' => 'Tenant Missing Items',
            'status' => 'active',
            'is_active' => true,
        ]);

        foreach ($permissionKeys as $key) {
            Permission::query()->firstOrCreate(
                ['key' => $key],
                [
                    'module' => str_contains($key, '.') ? explode('.', $key)[0] : 'missing',
                    'action' => $key,
                    'description' => $key,
                ]
            );
        }

        $user = User::query()->create([
            'tenant_id' => $tenant->getKey(),
            'name' => 'Missing Owner',
            'email' => 'missing-owner+'.uniqid().'@example.test',
            'password' => bcrypt('secret123'),
        ]);

        if ($permissionKeys !== []) {
            $permissionIds = Permission::query()->whereIn('key', $permissionKeys)->pluck('id')->all();
            $user->permissions()->sync($permissionIds);
        }

        Sanctum::actingAs($user, ['access:full', 'tenant:'.$tenant->uid]);

        return $user;
    }
}
