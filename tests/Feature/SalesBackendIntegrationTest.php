<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Competitor;
use App\Models\CrmEntity;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\InventoryProduct;
use App\Models\InventoryReservation;
use App\Models\InventoryStock;
use App\Models\Invoice;
use App\Models\Opportunity;
use App\Models\OpportunityStage;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InvoicePdfMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SalesBackendIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_finance_dashboard_matches_sales_frontend_contract(): void
    {
        $user = $this->authenticateWithPermissions(['finance.read']);
        $account = $this->account($user);

        Invoice::query()->create([
            'tenant_id' => $user->tenant_id,
            'invoiceable_type' => Account::class,
            'invoiceable_id' => $account->getKey(),
            'invoice_number' => 'FAC-CURRENT-'.uniqid(),
            'status' => 'partial',
            'currency' => 'USD',
            'total' => 1000,
            'outstanding_total' => 400,
            'issued_at' => now()->toDateString(),
            'due_date' => now()->addDays(10)->toDateString(),
        ]);

        Invoice::query()->create([
            'tenant_id' => $user->tenant_id,
            'invoiceable_type' => Account::class,
            'invoiceable_id' => $account->getKey(),
            'invoice_number' => 'FAC-OVERDUE-'.uniqid(),
            'status' => 'overdue',
            'currency' => 'USD',
            'total' => 200,
            'outstanding_total' => 200,
            'issued_at' => now()->toDateString(),
            'due_date' => now()->subDays(10)->toDateString(),
        ]);

        Invoice::query()->create([
            'tenant_id' => $user->tenant_id,
            'invoiceable_type' => Account::class,
            'invoiceable_id' => $account->getKey(),
            'invoice_number' => 'FAC-PREV-'.uniqid(),
            'status' => 'paid',
            'currency' => 'USD',
            'total' => 600,
            'outstanding_total' => 0,
            'issued_at' => now()->subMonthNoOverflow()->toDateString(),
            'due_date' => now()->subMonthNoOverflow()->addDays(10)->toDateString(),
        ]);

        $this->getJson('/api/finance/dashboard')
            ->assertOk()
            ->assertJsonPath('data.stats.monthly_sales', 1200)
            ->assertJsonPath('data.stats.monthly_sales_growth_percent', 100)
            ->assertJsonPath('data.stats.pending_invoices_count', 1)
            ->assertJsonPath('data.stats.pending_invoices_amount', 400)
            ->assertJsonPath('data.stats.overdue_portfolio', 200)
            ->assertJsonPath('data.stats.overdue_clients_count', 1)
            ->assertJsonPath('data.stats.margin_target_percent', 45)
            ->assertJsonPath('data.recent_invoices.0.client_name', 'Cliente Ventas')
            ->assertJsonStructure(['data' => ['weekly_sales', 'recent_invoices']]);
    }

    public function test_credit_rules_and_exceptions_match_sales_contract(): void
    {
        $user = $this->authenticateWithPermissions(['finance.read', 'finance.manage']);
        $account = $this->account($user);

        $this->getJson('/api/finance/credit/rules')
            ->assertOk()
            ->assertJsonPath('data.max_days', 30)
            ->assertJsonPath('data.max_amount', 50000)
            ->assertJsonPath('data.auto_block', true);

        $this->putJson('/api/finance/credit/rules', [
            'max_days' => 45,
            'max_amount' => 125000,
            'auto_block' => false,
        ])
            ->assertOk()
            ->assertJsonPath('data.max_days', 45)
            ->assertJsonPath('data.max_amount', 125000)
            ->assertJsonPath('data.auto_block', false);

        $created = $this->postJson('/api/finance/credit/exceptions', [
            'client_uid' => $account->uid,
            'client_identifier' => 'RFC-123',
            'credit_limit' => 150000,
            'max_days' => 60,
            'is_active' => true,
        ]);

        $created->assertCreated()
            ->assertJsonPath('data.client_name', 'Cliente Ventas')
            ->assertJsonPath('data.client_identifier', 'RFC-123')
            ->assertJsonPath('data.credit_limit', 150000)
            ->assertJsonPath('data.max_days', 60)
            ->assertJsonPath('data.is_active', true);

        $uid = $created->json('data.uid');

        $this->putJson('/api/finance/credit/exceptions/'.$uid, [
            'credit_limit' => 175000,
            'max_days' => 75,
            'is_active' => false,
        ])
            ->assertOk()
            ->assertJsonPath('data.credit_limit', 175000)
            ->assertJsonPath('data.max_days', 75)
            ->assertJsonPath('data.is_active', false);

        $this->getJson('/api/finance/credit/exceptions')
            ->assertOk()
            ->assertJsonPath('data.0.uid', $uid);
    }

    public function test_currency_rates_and_conversion_match_sales_contract(): void
    {
        $user = $this->authenticateWithPermissions(['finance.read']);
        Currency::query()->create([
            'code' => 'COP',
            'name' => 'Peso colombiano',
            'symbol' => '$',
        ]);

        ExchangeRate::query()->create([
            'tenant_id' => $user->tenant_id,
            'from_currency' => 'USD',
            'to_currency' => 'COP',
            'rate' => 4000,
            'rate_date' => now()->toDateString(),
        ]);

        $this->getJson('/api/currency/rates')
            ->assertOk()
            ->assertJsonPath('data.0.code', 'COP')
            ->assertJsonPath('data.0.name', 'Peso colombiano')
            ->assertJsonPath('data.0.rate', 4000)
            ->assertJsonPath('data.0.status', 'active');

        $this->postJson('/api/currency/convert', [
            'from' => 'USD',
            'to' => 'COP',
            'amount' => 1000,
        ])
            ->assertOk()
            ->assertJsonPath('data.result', 4000000)
            ->assertJsonPath('data.rate', 4000)
            ->assertJsonPath('data.rate_date', now()->toDateString());
    }

    public function test_quotations_and_invoices_support_server_search_filters(): void
    {
        $user = $this->authenticateWithPermissions(['quotations.read', 'finance.read']);
        $account = $this->account($user);

        $matchingQuote = Quotation::query()->create([
            'tenant_id' => $user->tenant_id,
            'owner_user_id' => $user->getKey(),
            'quoteable_type' => Account::class,
            'quoteable_id' => $account->getKey(),
            'quote_number' => 'Q-SALES-001',
            'title' => 'Renovacion Enterprise',
            'status' => 'approved',
        ]);
        Quotation::query()->create([
            'tenant_id' => $user->tenant_id,
            'owner_user_id' => $user->getKey(),
            'quote_number' => 'Q-SALES-002',
            'title' => 'Borrador interno',
            'status' => 'draft',
        ]);

        Invoice::query()->create([
            'tenant_id' => $user->tenant_id,
            'quotation_id' => $matchingQuote->getKey(),
            'invoiceable_type' => Account::class,
            'invoiceable_id' => $account->getKey(),
            'invoice_number' => 'INV-ENTERPRISE-001',
            'status' => 'issued',
            'currency' => 'USD',
            'total' => 1000,
            'outstanding_total' => 1000,
        ]);
        Invoice::query()->create([
            'tenant_id' => $user->tenant_id,
            'invoiceable_type' => Account::class,
            'invoiceable_id' => $account->getKey(),
            'invoice_number' => 'INV-OTHER-001',
            'status' => 'paid',
            'currency' => 'USD',
            'total' => 500,
            'outstanding_total' => 0,
        ]);

        $this->getJson('/api/quotations?search=enterprise&status=approved')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.uid', $matchingQuote->uid);

        $this->getJson('/api/quotations?search='.$account->uid)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.uid', $matchingQuote->uid);

        $this->getJson('/api/finance/invoices?search='.$matchingQuote->uid)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.invoice_number', 'INV-ENTERPRISE-001');
    }

    public function test_invoices_can_be_filtered_by_opportunity_uid(): void
    {
        $user = $this->authenticateWithPermissions(['finance.read']);
        $stage = OpportunityStage::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Leads',
            'key' => 'leads-invoices',
            'position' => 1,
            'is_active' => true,
        ]);
        $opportunity = Opportunity::query()->create([
            'tenant_id' => $user->tenant_id,
            'owner_user_id' => $user->getKey(),
            'stage_id' => $stage->getKey(),
            'title' => 'Lead con varias facturas',
            'amount' => 1000,
        ]);
        $otherOpportunity = Opportunity::query()->create([
            'tenant_id' => $user->tenant_id,
            'owner_user_id' => $user->getKey(),
            'stage_id' => $stage->getKey(),
            'title' => 'Otro lead',
            'amount' => 1000,
        ]);

        $firstQuote = Quotation::query()->create([
            'tenant_id' => $user->tenant_id,
            'owner_user_id' => $user->getKey(),
            'quoteable_type' => Opportunity::class,
            'quoteable_id' => $opportunity->getKey(),
            'quote_number' => 'Q-OPP-INV-1',
            'title' => 'Cotizacion 1',
            'status' => 'invoiced',
        ]);
        $secondQuote = Quotation::query()->create([
            'tenant_id' => $user->tenant_id,
            'owner_user_id' => $user->getKey(),
            'quoteable_type' => Opportunity::class,
            'quoteable_id' => $opportunity->getKey(),
            'quote_number' => 'Q-OPP-INV-2',
            'title' => 'Cotizacion 2',
            'status' => 'invoiced',
        ]);
        $otherQuote = Quotation::query()->create([
            'tenant_id' => $user->tenant_id,
            'owner_user_id' => $user->getKey(),
            'quoteable_type' => Opportunity::class,
            'quoteable_id' => $otherOpportunity->getKey(),
            'quote_number' => 'Q-OPP-INV-3',
            'title' => 'Cotizacion otra',
            'status' => 'invoiced',
        ]);

        Invoice::query()->create([
            'tenant_id' => $user->tenant_id,
            'quotation_id' => $firstQuote->getKey(),
            'invoiceable_type' => Opportunity::class,
            'invoiceable_id' => $opportunity->getKey(),
            'invoice_number' => 'INV-OPP-001',
            'status' => 'issued',
            'currency' => 'COP',
            'total' => 100,
            'outstanding_total' => 100,
        ]);
        Invoice::query()->create([
            'tenant_id' => $user->tenant_id,
            'quotation_id' => $secondQuote->getKey(),
            'invoiceable_type' => Opportunity::class,
            'invoiceable_id' => $opportunity->getKey(),
            'invoice_number' => 'INV-OPP-002',
            'status' => 'issued',
            'currency' => 'COP',
            'total' => 200,
            'outstanding_total' => 200,
        ]);
        Invoice::query()->create([
            'tenant_id' => $user->tenant_id,
            'quotation_id' => $otherQuote->getKey(),
            'invoiceable_type' => Opportunity::class,
            'invoiceable_id' => $otherOpportunity->getKey(),
            'invoice_number' => 'INV-OPP-003',
            'status' => 'issued',
            'currency' => 'COP',
            'total' => 300,
            'outstanding_total' => 300,
        ]);

        $this->getJson('/api/finance/invoices?opportunity_uid='.$opportunity->uid)
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['invoice_number' => 'INV-OPP-001'])
            ->assertJsonFragment(['invoice_number' => 'INV-OPP-002'])
            ->assertJsonMissing(['invoice_number' => 'INV-OPP-003']);
    }

    public function test_invoice_number_is_generated_by_backend_when_converting_quotation(): void
    {
        $user = $this->authenticateWithPermissions(['finance.read', 'finance.manage']);
        $account = $this->account($user);
        $year = now()->format('Y');

        Invoice::query()->create([
            'tenant_id' => $user->tenant_id,
            'invoiceable_type' => Account::class,
            'invoiceable_id' => $account->getKey(),
            'invoice_number' => 'INV-'.$year.'-0041',
            'status' => 'issued',
            'currency' => 'ARS',
            'total' => 100,
            'outstanding_total' => 100,
        ]);

        $quotation = Quotation::query()->create([
            'tenant_id' => $user->tenant_id,
            'owner_user_id' => $user->getKey(),
            'quoteable_type' => Account::class,
            'quoteable_id' => $account->getKey(),
            'quote_number' => 'Q-INVOICE-'.uniqid(),
            'title' => 'Cotizacion a facturar',
            'status' => 'approved',
            'currency' => 'ARS',
        ]);
        $warehouse = Warehouse::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Bodega facturacion',
            'code' => 'INV-'.uniqid(),
        ]);
        $product = InventoryProduct::query()->create([
            'tenant_id' => $user->tenant_id,
            'sku' => 'PROD-INV-'.uniqid(),
            'name' => 'Producto facturable',
            'is_active' => true,
        ]);
        InventoryStock::query()->create([
            'tenant_id' => $user->tenant_id,
            'product_id' => $product->getKey(),
            'warehouse_id' => $warehouse->getKey(),
            'physical_stock' => 5,
            'reserved_stock' => 1,
        ]);
        $item = QuotationItem::query()->create([
            'tenant_id' => $user->tenant_id,
            'quotation_id' => $quotation->getKey(),
            'product_id' => $product->getKey(),
            'warehouse_id' => $warehouse->getKey(),
            'sku' => $product->sku,
            'description' => 'Producto facturable',
            'quantity' => 1,
            'reserved_quantity' => 1,
            'list_unit_price' => 200,
            'discount_percent' => 0,
            'discount_amount' => 0,
            'net_unit_price' => 200,
            'unit_price' => 200,
        ]);
        InventoryReservation::query()->create([
            'tenant_id' => $user->tenant_id,
            'product_id' => $product->getKey(),
            'warehouse_id' => $warehouse->getKey(),
            'reserved_by_user_id' => $user->getKey(),
            'source_type' => 'quotation_item',
            'source_uid' => $item->uid,
            'quantity' => 1,
            'status' => 'active',
        ]);

        $created = $this->postJson('/api/finance/invoices', [
            'quotation_uid' => $quotation->uid,
            'invoice_number' => 'INV-FRONTEND-SHOULD-BE-IGNORED',
            'currency' => 'ARS',
        ]);

        $created->assertCreated()
            ->assertJsonPath('data.invoice_number', 'INV-'.$year.'-0042')
            ->assertJsonPath('data.currency', 'ARS')
            ->assertJsonPath('data.total', '200.00')
            ->assertJsonPath('data.quotation_uid', $quotation->uid);

        $this->assertDatabaseHas('quotations', [
            'id' => $quotation->getKey(),
            'status' => 'invoiced',
        ]);

        $this->assertDatabaseMissing('invoices', [
            'tenant_id' => $user->tenant_id,
            'invoice_number' => 'INV-FRONTEND-SHOULD-BE-IGNORED',
        ]);
    }

    public function test_converted_quotation_cannot_be_marked_cancelled_by_legacy_frontend_flow(): void
    {
        $user = $this->authenticateWithPermissions(['quotations.update']);
        $account = $this->account($user);
        $quotation = Quotation::query()->create([
            'tenant_id' => $user->tenant_id,
            'owner_user_id' => $user->getKey(),
            'quoteable_type' => Account::class,
            'quoteable_id' => $account->getKey(),
            'quote_number' => 'Q-INVOICED-'.uniqid(),
            'title' => 'Cotizacion facturada',
            'status' => 'approved',
            'currency' => 'COP',
        ]);

        Invoice::query()->create([
            'tenant_id' => $user->tenant_id,
            'quotation_id' => $quotation->getKey(),
            'invoiceable_type' => Account::class,
            'invoiceable_id' => $account->getKey(),
            'invoice_number' => 'INV-LEGACY-'.uniqid(),
            'status' => 'issued',
            'currency' => 'COP',
            'total' => 100,
            'outstanding_total' => 100,
        ]);

        $this->putJson('/api/quotations/'.$quotation->uid, [
            'status' => 'cancelled',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'invoiced');
    }

    public function test_invoice_show_returns_normalized_entity_fields(): void
    {
        $user = $this->authenticateWithPermissions(['finance.read']);
        $stage = OpportunityStage::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Facturable',
            'key' => 'facturable-'.uniqid(),
            'position' => 1,
            'probability' => 50,
        ]);

        $opportunity = Opportunity::query()->create([
            'tenant_id' => $user->tenant_id,
            'owner_user_id' => $user->getKey(),
            'stage_id' => $stage->getKey(),
            'title' => 'Oportunidad facturada',
            'email' => 'lead-facturado@example.test',
            'amount' => 1000,
            'currency' => 'COP',
        ]);

        $invoice = Invoice::query()->create([
            'tenant_id' => $user->tenant_id,
            'invoiceable_type' => Opportunity::class,
            'invoiceable_id' => $opportunity->getKey(),
            'invoice_number' => 'INV-ENTITY-'.uniqid(),
            'status' => 'issued',
            'currency' => 'COP',
            'total' => 1000,
            'outstanding_total' => 1000,
        ]);

        $response = $this->getJson('/api/invoices/'.$invoice->uid)
            ->assertOk()
            ->assertJsonPath('data.entity_type', 'opportunity')
            ->assertJsonPath('data.entity_label', 'Oportunidad')
            ->assertJsonPath('data.entity_uid', $opportunity->uid)
            ->assertJsonPath('data.invoiceable_uid', $opportunity->uid)
            ->assertJsonPath('data.client_name', 'Oportunidad facturada')
            ->assertJsonPath('data.client_email', 'lead-facturado@example.test');

        $this->assertArrayNotHasKey('invoiceable_type', $response->json('data'));
    }

    public function test_invoice_can_be_sent_by_email_with_pdf_attachment(): void
    {
        Mail::fake();

        $user = $this->authenticateWithPermissions(['finance.read', 'finance.manage']);
        $account = Account::query()->create([
            'tenant_id' => $user->tenant_id,
            'owner_user_id' => $user->getKey(),
            'name' => 'Cliente Factura',
            'document' => 'INV-SEND-'.uniqid(),
            'email' => 'cliente-factura@example.test',
        ]);

        $invoice = Invoice::query()->create([
            'tenant_id' => $user->tenant_id,
            'invoiceable_type' => Account::class,
            'invoiceable_id' => $account->getKey(),
            'invoice_number' => 'INV-SEND-'.uniqid(),
            'status' => 'issued',
            'currency' => 'COP',
            'subtotal' => 1000,
            'discount_total' => 0,
            'total' => 1000,
            'paid_total' => 0,
            'outstanding_total' => 1000,
        ]);

        $this->postJson('/api/finance/invoices/'.$invoice->uid.'/send', [
            'subject' => 'Factura lista',
            'message' => 'Adjuntamos la factura.',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Factura enviada')
            ->assertJsonPath('data.recipient_email', 'cliente-factura@example.test')
            ->assertJsonPath('data.subject', 'Factura lista')
            ->assertJsonPath('data.filename', 'factura-'.$invoice->invoice_number.'.pdf');

        Mail::assertSent(InvoicePdfMail::class, function (InvoicePdfMail $mail) use ($invoice) {
            return $mail->invoice->is($invoice)
                && $mail->filename === 'factura-'.$invoice->invoice_number.'.pdf';
        });
    }

    public function test_quotation_create_auto_generates_quote_number(): void
    {
        $this->authenticateWithPermissions(['quotations.create']);
        $year = now()->format('Y');

        $first = $this->postJson('/api/quotations', [
            'title' => 'Cotizacion sin numero',
            'status' => 'draft',
        ]);

        $first->assertCreated()
            ->assertJsonPath('data.quote_number', 'COT-'.$year.'-001');

        $second = $this->postJson('/api/quotations', [
            'title' => 'Cotizacion sin numero 2',
            'status' => 'draft',
        ]);

        $second->assertCreated()
            ->assertJsonPath('data.quote_number', 'COT-'.$year.'-002');
    }

    public function test_quotation_resolves_latest_exchange_rate_when_frontend_omits_it(): void
    {
        $user = $this->authenticateWithPermissions(['quotations.create']);

        ExchangeRate::query()->create([
            'tenant_id' => $user->tenant_id,
            'from_currency' => 'USD',
            'to_currency' => 'COP',
            'rate' => 4100,
            'rate_date' => now()->subDay()->toDateString(),
        ]);
        ExchangeRate::query()->create([
            'tenant_id' => $user->tenant_id,
            'from_currency' => 'USD',
            'to_currency' => 'COP',
            'rate' => 4200,
            'rate_date' => now()->toDateString(),
        ]);

        $this->postJson('/api/quotations', [
            'title' => 'Cotizacion multimoneda',
            'currency' => 'USD',
            'local_currency' => 'COP',
        ])
            ->assertCreated()
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.local_currency', 'COP')
            ->assertJsonPath('data.exchange_rate', 4200);
    }

    public function test_quotation_creation_accepts_frontend_empty_uids_and_resolves_items_by_sku(): void
    {
        $user = $this->authenticateWithPermissions(['quotations.create']);
        $account = $this->account($user);
        $inventoryProduct = InventoryProduct::query()->create([
            'tenant_id' => $user->tenant_id,
            'sku' => 'CAM-26-001',
            'name' => 'Camisa',
            'cost_price' => 5000,
            'sale_price' => 20000,
            'discount_percent' => 5,
            'reorder_point' => 10,
            'is_active' => true,
        ]);
        $catalogProduct = Product::query()->create([
            'tenant_id' => $user->tenant_id,
            'inventory_product_id' => $inventoryProduct->getKey(),
            'name' => 'Camisa',
            'type' => 'product',
            'sku' => 'CAM-26-001',
            'status' => 'active',
            'default_price' => 20000,
            'default_discount_percent' => 5,
        ]);

        $response = $this->postJson('/api/quotations', [
            'uid' => '',
            'quote_number' => '',
            'title' => 'Venta de vehiculo',
            'status' => 'draft',
            'currency' => 'COP',
            'entity_type' => 'account',
            'entity_uid' => $account->uid,
            'valid_until' => now()->addDays(7)->toDateString(),
            'items' => [
                [
                    'uid' => '',
                    'description' => 'Camisa',
                    'sku' => 'CAM-26-001',
                    'quantity' => 1,
                    'list_unit_price' => 20000,
                    'discount_percent' => 5,
                ],
            ],
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.title', 'Venta de vehiculo')
            ->assertJsonPath('data.items.0.catalog_product_uid', $catalogProduct->uid)
            ->assertJsonPath('data.items.0.product_uid', $inventoryProduct->uid);

        $this->assertNotSame('', $response->json('data.quote_number'));
    }

    public function test_quotation_pdf_endpoint_returns_client_ready_pdf(): void
    {
        $user = $this->authenticateWithPermissions(['quotations.read']);
        $account = $this->account($user);
        $quotation = Quotation::query()->create([
            'tenant_id' => $user->tenant_id,
            'owner_user_id' => $user->getKey(),
            'quoteable_type' => Account::class,
            'quoteable_id' => $account->getKey(),
            'quote_number' => 'Q-PDF-'.uniqid(),
            'title' => 'Cotizacion PDF',
            'status' => 'sent',
            'currency' => 'COP',
            'valid_until' => now()->addDays(15)->toDateString(),
            'notes' => 'Condiciones comerciales vigentes.',
        ]);
        QuotationItem::query()->create([
            'tenant_id' => $user->tenant_id,
            'quotation_id' => $quotation->getKey(),
            'sku' => 'PDF-001',
            'description' => 'Servicio de implementacion',
            'quantity' => 2,
            'list_unit_price' => 1000,
            'discount_percent' => 10,
            'discount_amount' => 100,
            'net_unit_price' => 900,
            'unit_price' => 900,
        ]);

        $response = $this->get('/api/quotations/'.$quotation->uid.'/pdf');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-1.4', $response->getContent());
    }

    public function test_catalog_services_store_default_pricing_and_prefill_quotation_items(): void
    {
        $user = $this->authenticateWithPermissions([
            'products.read',
            'products.manage',
            'quotations.read',
            'quotations.update',
        ]);

        $service = $this->postJson('/api/products', [
            'name' => 'Implementacion CRM',
            'type' => 'service',
            'sku' => 'SERV-CRM-001',
            'description' => 'Servicio profesional',
            'default_price' => 1200,
            'default_discount_percent' => 10,
        ]);

        $serviceUid = $service
            ->assertCreated()
            ->assertJsonPath('data.default_price', 1200)
            ->assertJsonPath('data.default_discount_percent', 10)
            ->json('data.uid');

        $this->getJson('/api/products/'.$serviceUid)
            ->assertOk()
            ->assertJsonPath('data.default_price', 1200)
            ->assertJsonPath('data.default_discount_percent', 10);

        $quotation = Quotation::query()->create([
            'tenant_id' => $user->tenant_id,
            'owner_user_id' => $user->getKey(),
            'quote_number' => 'Q-SERVICE-'.uniqid(),
            'title' => 'Cotizacion servicio',
            'status' => 'draft',
            'currency' => 'COP',
        ]);

        $this->postJson('/api/quotations/'.$quotation->uid.'/items', [
            'catalog_product_uid' => $serviceUid,
            'quantity' => 2,
        ])
            ->assertCreated()
            ->assertJsonPath('data.description', 'Implementacion CRM')
            ->assertJsonPath('data.list_unit_price', 1200)
            ->assertJsonPath('data.discount_percent', 10)
            ->assertJsonPath('data.discount_amount', 120)
            ->assertJsonPath('data.net_unit_price', 1080)
            ->assertJsonPath('data.line_total', 2160);
    }

    public function test_catalog_product_delete_deactivates_without_breaking_quotation_history(): void
    {
        $user = $this->authenticateWithPermissions(['products.manage']);
        $catalogProduct = Product::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Servicio Historico',
            'type' => 'service',
            'sku' => 'SERV-HIST',
            'status' => 'active',
        ]);
        $quotation = Quotation::query()->create([
            'tenant_id' => $user->tenant_id,
            'owner_user_id' => $user->getKey(),
            'quote_number' => 'Q-HIST-'.uniqid(),
            'title' => 'Cotizacion historica',
            'status' => 'draft',
            'currency' => 'COP',
        ]);
        $item = QuotationItem::query()->create([
            'tenant_id' => $user->tenant_id,
            'quotation_id' => $quotation->getKey(),
            'catalog_product_id' => $catalogProduct->getKey(),
            'sku' => 'SERV-HIST',
            'description' => 'Servicio Historico',
            'quantity' => 1,
            'list_unit_price' => 100,
            'discount_percent' => 0,
            'discount_amount' => 0,
            'net_unit_price' => 100,
            'unit_price' => 100,
        ]);

        $this->deleteJson('/api/products/'.$catalogProduct->uid)
            ->assertOk()
            ->assertJsonPath('message', 'Producto desactivado')
            ->assertJsonPath('data.status', 'inactive');

        $this->assertDatabaseHas('products', [
            'id' => $catalogProduct->getKey(),
            'status' => 'inactive',
        ]);
        $this->assertDatabaseHas('quotation_items', [
            'id' => $item->getKey(),
            'catalog_product_id' => $catalogProduct->getKey(),
        ]);
    }

    public function test_catalog_products_support_search_by_name_and_sku(): void
    {
        $user = $this->authenticateWithPermissions(['products.read']);

        Product::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Camisa Ejecutiva',
            'type' => 'product',
            'sku' => 'CAM-26-001',
            'status' => 'active',
        ]);
        Product::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Consultoria CRM',
            'type' => 'service',
            'sku' => 'SERV-CRM',
            'status' => 'active',
        ]);

        $this->getJson('/api/products?search=camisa')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.sku', 'CAM-26-001');

        $this->getJson('/api/products?search=serv-crm')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Consultoria CRM');
    }

    public function test_service_items_do_not_require_stock_to_approve_or_invoice(): void
    {
        $user = $this->authenticateWithPermissions([
            'products.manage',
            'quotations.update',
            'finance.manage',
        ]);
        $account = $this->account($user);

        $serviceUid = $this->postJson('/api/products', [
            'name' => 'Consultoria comercial',
            'type' => 'service',
            'sku' => 'SERV-CONSULT',
            'default_price' => 900,
        ])
            ->assertCreated()
            ->json('data.uid');

        $quotation = Quotation::query()->create([
            'tenant_id' => $user->tenant_id,
            'owner_user_id' => $user->getKey(),
            'quoteable_type' => Account::class,
            'quoteable_id' => $account->getKey(),
            'quote_number' => 'Q-SERVICE-APPROVE-'.uniqid(),
            'title' => 'Cotizacion solo servicio',
            'status' => 'draft',
            'currency' => 'COP',
        ]);

        $this->postJson('/api/quotations/'.$quotation->uid.'/items', [
            'catalog_product_uid' => $serviceUid,
            'quantity' => 1,
        ])->assertCreated();

        $this->putJson('/api/quotations/'.$quotation->uid, [
            'status' => 'approved',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->postJson('/api/finance/invoices', [
            'quotation_uid' => $quotation->uid,
            'currency' => 'COP',
        ])
            ->assertCreated()
            ->assertJsonPath('data.quotation_uid', $quotation->uid)
            ->assertJsonPath('data.total', '900.00');
    }

    public function test_invoice_creation_auto_reserves_physical_products_and_ignores_services(): void
    {
        $user = $this->authenticateWithPermissions(['finance.manage']);
        $account = $this->account($user);
        $inventoryProduct = InventoryProduct::query()->create([
            'tenant_id' => $user->tenant_id,
            'sku' => 'INV-CAM-001',
            'name' => 'Camisa inventario',
            'cost_price' => 5000,
            'sale_price' => 20000,
            'is_active' => true,
        ]);
        $catalogProduct = Product::query()->create([
            'tenant_id' => $user->tenant_id,
            'inventory_product_id' => $inventoryProduct->getKey(),
            'name' => 'Camisa',
            'type' => 'product',
            'sku' => 'CAM-26-001',
            'status' => 'active',
        ]);
        $service = Product::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Consultoria',
            'type' => 'service',
            'sku' => 'SERV-CONSULT',
            'status' => 'active',
        ]);
        $warehouse = Warehouse::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Bodega Principal',
            'code' => 'MAIN',
        ]);
        InventoryStock::query()->create([
            'tenant_id' => $user->tenant_id,
            'product_id' => $inventoryProduct->getKey(),
            'warehouse_id' => $warehouse->getKey(),
            'physical_stock' => 10,
            'reserved_stock' => 0,
        ]);
        $quotation = Quotation::query()->create([
            'tenant_id' => $user->tenant_id,
            'owner_user_id' => $user->getKey(),
            'quoteable_type' => Account::class,
            'quoteable_id' => $account->getKey(),
            'quote_number' => 'Q-STOCK-DETAIL-'.uniqid(),
            'title' => 'Cotizacion mixta',
            'status' => 'approved',
            'currency' => 'COP',
        ]);

        QuotationItem::query()->create([
            'tenant_id' => $user->tenant_id,
            'quotation_id' => $quotation->getKey(),
            'product_id' => $inventoryProduct->getKey(),
            'catalog_product_id' => $catalogProduct->getKey(),
            'warehouse_id' => $warehouse->getKey(),
            'sku' => 'CAM-26-001',
            'description' => 'Camisa',
            'quantity' => 2,
            'list_unit_price' => 20000,
            'discount_percent' => 0,
            'discount_amount' => 0,
            'net_unit_price' => 20000,
            'unit_price' => 20000,
        ]);
        QuotationItem::query()->create([
            'tenant_id' => $user->tenant_id,
            'quotation_id' => $quotation->getKey(),
            'catalog_product_id' => $service->getKey(),
            'sku' => 'SERV-CONSULT',
            'description' => 'Consultoria',
            'quantity' => 1,
            'list_unit_price' => 900,
            'discount_percent' => 0,
            'discount_amount' => 0,
            'net_unit_price' => 900,
            'unit_price' => 900,
        ]);

        $response = $this->postJson('/api/finance/invoices', [
            'quotation_uid' => $quotation->uid,
            'currency' => 'COP',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.quotation_uid', $quotation->uid);

        $this->assertSame(0, (int) InventoryReservation::query()
            ->where('source_uid', $quotation->items()->where('sku', 'CAM-26-001')->value('uid'))
            ->where('status', 'active')
            ->sum('quantity'));
        $this->assertSame(2, (int) InventoryReservation::query()
            ->where('source_uid', $quotation->items()->where('sku', 'CAM-26-001')->value('uid'))
            ->where('status', 'consumed')
            ->sum('quantity'));
        $this->assertSame(0, InventoryReservation::query()
            ->where('source_uid', $quotation->items()->where('sku', 'SERV-CONSULT')->value('uid'))
            ->count());
    }

    public function test_invoice_stock_validation_reports_physical_products_when_stock_is_insufficient(): void
    {
        $user = $this->authenticateWithPermissions(['finance.manage']);
        $account = $this->account($user);
        $inventoryProduct = InventoryProduct::query()->create([
            'tenant_id' => $user->tenant_id,
            'sku' => 'INV-LOW-001',
            'name' => 'Producto bajo',
            'cost_price' => 5000,
            'sale_price' => 20000,
            'is_active' => true,
        ]);
        $catalogProduct = Product::query()->create([
            'tenant_id' => $user->tenant_id,
            'inventory_product_id' => $inventoryProduct->getKey(),
            'name' => 'Producto bajo catalogo',
            'type' => 'product',
            'sku' => 'LOW-001',
            'status' => 'active',
        ]);
        $warehouse = Warehouse::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Bodega Baja',
            'code' => 'LOW',
        ]);
        InventoryStock::query()->create([
            'tenant_id' => $user->tenant_id,
            'product_id' => $inventoryProduct->getKey(),
            'warehouse_id' => $warehouse->getKey(),
            'physical_stock' => 1,
            'reserved_stock' => 0,
        ]);
        $quotation = Quotation::query()->create([
            'tenant_id' => $user->tenant_id,
            'owner_user_id' => $user->getKey(),
            'quoteable_type' => Account::class,
            'quoteable_id' => $account->getKey(),
            'quote_number' => 'Q-STOCK-LOW-'.uniqid(),
            'title' => 'Cotizacion sin stock suficiente',
            'status' => 'approved',
            'currency' => 'COP',
        ]);

        QuotationItem::query()->create([
            'tenant_id' => $user->tenant_id,
            'quotation_id' => $quotation->getKey(),
            'catalog_product_id' => $catalogProduct->getKey(),
            'sku' => 'LOW-001',
            'description' => 'Producto bajo catalogo',
            'quantity' => 3,
            'list_unit_price' => 20000,
            'discount_percent' => 0,
            'discount_amount' => 0,
            'net_unit_price' => 20000,
            'unit_price' => 20000,
        ]);

        $response = $this->postJson('/api/finance/invoices', [
            'quotation_uid' => $quotation->uid,
            'currency' => 'COP',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonPath('errors.quotation_uid.0', 'No puedes facturar sin stock reservado suficiente para los productos fisicos');

        $messages = $response->json('errors.items');

        $this->assertCount(1, $messages);
        $this->assertStringContainsString('Producto bajo catalogo', $messages[0]);
        $this->assertStringContainsString('faltan 2', $messages[0]);
    }

    public function test_approving_quotation_resolves_catalog_product_and_allocates_stock(): void
    {
        $user = $this->authenticateWithPermissions([
            'quotations.update',
            'finance.manage',
        ]);
        $account = $this->account($user);
        $inventoryProduct = InventoryProduct::query()->create([
            'tenant_id' => $user->tenant_id,
            'sku' => 'INV-CRM-ALLOC',
            'name' => 'Licencia CRM',
            'cost_price' => 100,
            'sale_price' => 200,
            'is_active' => true,
        ]);
        $catalogProduct = Product::query()->create([
            'tenant_id' => $user->tenant_id,
            'inventory_product_id' => $inventoryProduct->getKey(),
            'name' => 'CRM Comercial',
            'type' => 'product',
            'sku' => 'CAT-CRM-ALLOC',
            'status' => 'active',
        ]);
        $firstWarehouse = Warehouse::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Bodega Norte',
            'code' => 'NORTE',
        ]);
        $secondWarehouse = Warehouse::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Bodega Sur',
            'code' => 'SUR',
        ]);

        InventoryStock::query()->create([
            'tenant_id' => $user->tenant_id,
            'product_id' => $inventoryProduct->getKey(),
            'warehouse_id' => $firstWarehouse->getKey(),
            'physical_stock' => 7,
            'reserved_stock' => 0,
        ]);
        InventoryStock::query()->create([
            'tenant_id' => $user->tenant_id,
            'product_id' => $inventoryProduct->getKey(),
            'warehouse_id' => $secondWarehouse->getKey(),
            'physical_stock' => 5,
            'reserved_stock' => 0,
        ]);

        $quotation = Quotation::query()->create([
            'tenant_id' => $user->tenant_id,
            'owner_user_id' => $user->getKey(),
            'quoteable_type' => Account::class,
            'quoteable_id' => $account->getKey(),
            'quote_number' => 'Q-ALLOC-'.uniqid(),
            'title' => 'Cotizacion asignacion automatica',
            'status' => 'draft',
            'currency' => 'COP',
        ]);
        $item = QuotationItem::query()->create([
            'tenant_id' => $user->tenant_id,
            'quotation_id' => $quotation->getKey(),
            'catalog_product_id' => $catalogProduct->getKey(),
            'sku' => 'CAT-CRM-ALLOC',
            'description' => 'CRM Comercial',
            'quantity' => 10,
            'list_unit_price' => 200,
            'discount_percent' => 0,
            'discount_amount' => 0,
            'net_unit_price' => 200,
            'unit_price' => 200,
            'unit_cost' => 100,
        ]);

        $this->putJson('/api/quotations/'.$quotation->uid, [
            'status' => 'approved',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $item->refresh();
        $this->assertSame($inventoryProduct->getKey(), $item->product_id);
        $this->assertSame($firstWarehouse->getKey(), $item->warehouse_id);
        $this->assertSame(10, (int) InventoryReservation::query()->where('source_uid', $item->uid)->where('status', 'active')->sum('quantity'));
        $this->assertSame([7, 3], InventoryReservation::query()->where('source_uid', $item->uid)->orderByDesc('quantity')->pluck('quantity')->all());

        $this->postJson('/api/finance/invoices', [
            'quotation_uid' => $quotation->uid,
            'currency' => 'COP',
        ])->assertCreated();
    }

    public function test_approving_quotation_hydrates_legacy_item_product_links_by_sku(): void
    {
        $user = $this->authenticateWithPermissions(['quotations.update']);
        $account = $this->account($user);
        $inventoryProduct = InventoryProduct::query()->create([
            'tenant_id' => $user->tenant_id,
            'sku' => 'CAM-26-001',
            'name' => 'Camisa',
            'cost_price' => 5000,
            'sale_price' => 20000,
            'discount_percent' => 5,
            'is_active' => true,
        ]);
        $catalogProduct = Product::query()->create([
            'tenant_id' => $user->tenant_id,
            'inventory_product_id' => $inventoryProduct->getKey(),
            'name' => 'Camisa',
            'type' => 'product',
            'sku' => 'CAM-26-001',
            'status' => 'active',
        ]);
        $warehouse = Warehouse::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Bodega Principal',
            'code' => 'MAIN',
        ]);
        InventoryStock::query()->create([
            'tenant_id' => $user->tenant_id,
            'product_id' => $inventoryProduct->getKey(),
            'warehouse_id' => $warehouse->getKey(),
            'physical_stock' => 106,
            'reserved_stock' => 0,
        ]);

        $quotation = Quotation::query()->create([
            'tenant_id' => $user->tenant_id,
            'owner_user_id' => $user->getKey(),
            'quoteable_type' => Account::class,
            'quoteable_id' => $account->getKey(),
            'quote_number' => 'Q-CAM-'.uniqid(),
            'title' => 'Cotizacion camisa',
            'status' => 'draft',
            'currency' => 'COP',
        ]);
        $item = QuotationItem::query()->create([
            'tenant_id' => $user->tenant_id,
            'quotation_id' => $quotation->getKey(),
            'sku' => 'CAM-26-001',
            'description' => 'Camisa',
            'quantity' => 1,
            'list_unit_price' => 20000,
            'discount_percent' => 5,
            'discount_amount' => 1000,
            'net_unit_price' => 19000,
            'unit_price' => 19000,
            'unit_cost' => 5000,
        ]);

        $this->putJson('/api/quotations/'.$quotation->uid, [
            'status' => 'approved',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $item->refresh();
        $this->assertSame($catalogProduct->getKey(), $item->catalog_product_id);
        $this->assertSame($inventoryProduct->getKey(), $item->product_id);
        $this->assertSame($warehouse->getKey(), $item->warehouse_id);
    }

    public function test_quotation_show_accepts_opportunity_uid_when_quote_belongs_to_opportunity(): void
    {
        $user = $this->authenticateWithPermissions(['quotations.read']);
        $stage = OpportunityStage::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Nuevo',
            'key' => 'new',
            'position' => 1,
        ]);
        $opportunity = Opportunity::query()->create([
            'tenant_id' => $user->tenant_id,
            'owner_user_id' => $user->getKey(),
            'stage_id' => $stage->getKey(),
            'title' => 'Oportunidad con cotizacion',
            'amount' => 12000,
        ]);
        $quotation = Quotation::query()->create([
            'tenant_id' => $user->tenant_id,
            'owner_user_id' => $user->getKey(),
            'quoteable_type' => Opportunity::class,
            'quoteable_id' => $opportunity->getKey(),
            'quote_number' => 'Q-OPP-'.uniqid(),
            'title' => 'Cotizacion desde oportunidad',
            'status' => 'draft',
        ]);

        $this->getJson('/api/quotations/'.$opportunity->uid)
            ->assertOk()
            ->assertJsonPath('data.uid', $quotation->uid)
            ->assertJsonPath('data.opportunity_uid', $opportunity->uid);
    }

    public function test_quotation_create_update_batch_items_and_opportunity_filter(): void
    {
        $user = $this->authenticateWithPermissions(['quotations.read', 'quotations.create', 'quotations.update']);
        $stage = OpportunityStage::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Nuevo',
            'key' => 'new',
            'position' => 1,
        ]);
        $opportunity = Opportunity::query()->create([
            'tenant_id' => $user->tenant_id,
            'owner_user_id' => $user->getKey(),
            'stage_id' => $stage->getKey(),
            'title' => 'Oportunidad quotation batch',
            'amount' => 2500,
        ]);

        $created = $this->postJson('/api/quotations', [
            'title' => 'Cotizacion batch',
            'status' => 'draft',
            'currency' => 'AFN',
            'entity_type' => 'opportunity',
            'entity_uid' => $opportunity->uid,
            'items' => [
                [
                    'description' => 'Chaqueta',
                    'sku' => 'SKU-100-L',
                    'quantity' => 1,
                    'list_unit_price' => 654.99,
                    'discount_percent' => 0,
                ],
                [
                    'description' => 'Pantalon',
                    'sku' => 'SKU-200-M',
                    'quantity' => 2,
                    'list_unit_price' => 50,
                    'discount_percent' => 10,
                ],
            ],
        ]);

        $created->assertCreated()
            ->assertJsonPath('data.title', 'Cotizacion batch')
            ->assertJsonPath('data.currency', 'AFN')
            ->assertJsonPath('data.quoteable_type', Opportunity::class)
            ->assertJsonPath('data.quoteable_uid', $opportunity->uid)
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.description', 'Chaqueta')
            ->assertJsonPath('data.items.0.sku', 'SKU-100-L')
            ->assertJsonPath('data.items.0.quantity', 1)
            ->assertJsonPath('data.items.0.list_unit_price', 654.99)
            ->assertJsonPath('data.items.0.discount_percent', 0)
            ->assertJsonPath('data.items.0.net_unit_price', 654.99)
            ->assertJsonPath('data.items.0.line_total', 654.99)
            ->assertJsonPath('data.items.0.discount_total', 0);

        $quotationUid = $created->json('data.uid');
        $firstItemUid = $created->json('data.items.0.uid');

        $this->getJson('/api/quotations?opportunity_uid='.$opportunity->uid)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.uid', $quotationUid)
            ->assertJsonPath('data.0.quoteable_uid', $opportunity->uid)
            ->assertJsonCount(2, 'data.0.items');

        $updated = $this->putJson('/api/quotations/'.$quotationUid, [
            'title' => 'Cotizacion actualizada',
            'status' => 'draft',
            'currency' => 'USD',
            'valid_until' => '2026-06-30',
            'notes' => 'Notas actualizadas',
            'items' => [
                [
                    'uid' => $firstItemUid,
                    'description' => 'Producto A',
                    'sku' => 'SKU-001',
                    'quantity' => 2,
                    'list_unit_price' => 100,
                    'discount_percent' => 10,
                ],
                [
                    'description' => 'Producto B',
                    'sku' => 'SKU-002',
                    'quantity' => 1,
                    'list_unit_price' => 75,
                    'discount_percent' => 0,
                ],
            ],
        ]);

        $updated->assertOk()
            ->assertJsonPath('data.title', 'Cotizacion actualizada')
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.notes', 'Notas actualizadas')
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.uid', $firstItemUid)
            ->assertJsonPath('data.items.0.description', 'Producto A')
            ->assertJsonPath('data.items.0.quantity', 2)
            ->assertJsonPath('data.items.0.list_unit_price', 100)
            ->assertJsonPath('data.items.0.discount_percent', 10)
            ->assertJsonPath('data.items.0.net_unit_price', 90)
            ->assertJsonPath('data.items.0.line_total', 180)
            ->assertJsonPath('data.items.0.discount_total', 20)
            ->assertJsonPath('data.items.1.description', 'Producto B');

        $this->assertSame('2026-06-30', substr($updated->json('data.valid_until'), 0, 10));

        $this->assertDatabaseMissing('quotation_items', [
            'uid' => $created->json('data.items.1.uid'),
        ]);
    }

    public function test_opportunity_create_show_and_update_support_email(): void
    {
        $user = $this->authenticateWithPermissions(['opportunities.read', 'opportunities.manage']);
        $stage = OpportunityStage::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Nuevo',
            'key' => 'new',
            'position' => 1,
        ]);

        $created = $this->postJson('/api/opportunities', [
            'title' => 'TechNova S.A.',
            'amount' => 15000000,
            'stage_uid' => $stage->uid,
            'expected_close_date' => '2026-06-30',
            'description' => 'Lead desde formulario',
            'currency' => 'COP',
            'email' => 'contacto@technova.com',
            'lead_origin' => 'linkedin',
        ]);

        $created->assertCreated()
            ->assertJsonPath('data.title', 'TechNova S.A.')
            ->assertJsonPath('data.email', 'contacto@technova.com')
            ->assertJsonPath('data.lead_origin', 'linkedin');

        $uid = $created->json('data.uid');

        $this->getJson('/api/opportunities/'.$uid)
            ->assertOk()
            ->assertJsonPath('data.uid', $uid)
            ->assertJsonPath('data.email', 'contacto@technova.com')
            ->assertJsonPath('data.lead_origin', 'linkedin');

        $this->putJson('/api/opportunities/'.$uid, [
            'title' => 'TechNova S.A. actualizada',
            'email' => 'nuevo@technova.com',
            'lead_origin' => 'email',
        ])
            ->assertOk()
            ->assertJsonPath('data.title', 'TechNova S.A. actualizada')
            ->assertJsonPath('data.email', 'nuevo@technova.com')
            ->assertJsonPath('data.lead_origin', 'email');

        $this->assertDatabaseHas('opportunities', [
            'uid' => $uid,
            'email' => 'nuevo@technova.com',
            'lead_origin' => 'email',
        ]);
    }

    public function test_opportunity_board_supports_origin_and_product_filters(): void
    {
        $user = $this->authenticateWithPermissions(['opportunities.read']);
        $stage = OpportunityStage::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Nuevo',
            'key' => 'new',
            'position' => 1,
        ]);
        $entity = CrmEntity::query()->create([
            'tenant_id' => $user->tenant_id,
            'owner_user_id' => $user->getKey(),
            'type' => 'B2B',
            'profile_data' => [
                'company_name' => 'Cuenta Pipeline',
                'lead_origin' => 'web',
                'product_uid' => 'prod-crm',
            ],
        ]);

        $lost = Opportunity::query()->create([
            'tenant_id' => $user->tenant_id,
            'owner_user_id' => $user->getKey(),
            'stage_id' => $stage->getKey(),
            'opportunityable_type' => CrmEntity::class,
            'opportunityable_id' => $entity->getKey(),
            'title' => 'CRM Enterprise',
            'lead_origin' => 'web',
            'amount' => 25000,
            'description' => 'Producto principal CRM',
        ]);
        Opportunity::query()->create([
            'tenant_id' => $user->tenant_id,
            'owner_user_id' => $user->getKey(),
            'stage_id' => $stage->getKey(),
            'title' => 'Servicio soporte',
            'amount' => 1000,
            'description' => 'Origen referido',
        ]);

        $this->getJson('/api/opportunities/board?origin=web&product=prod-crm')
            ->assertOk()
            ->assertJsonPath('data.stages.0.summary.count', 1)
            ->assertJsonPath('data.stages.0.items.0.title', 'CRM Enterprise');
    }

    public function test_opportunity_summary_uses_aggregated_totals(): void
    {
        $user = $this->authenticateWithPermissions(['opportunities.read']);
        $openStage = OpportunityStage::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Leads',
            'key' => 'leads-summary',
            'position' => 1,
        ]);
        $wonStage = OpportunityStage::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Cerrador',
            'key' => 'cerrador-summary',
            'position' => 2,
            'is_won' => true,
        ]);

        Opportunity::query()->create([
            'tenant_id' => $user->tenant_id,
            'owner_user_id' => $user->getKey(),
            'stage_id' => $openStage->getKey(),
            'title' => 'Abierta',
            'amount' => 1000,
        ]);
        Opportunity::query()->create([
            'tenant_id' => $user->tenant_id,
            'owner_user_id' => $user->getKey(),
            'stage_id' => $wonStage->getKey(),
            'title' => 'Ganada',
            'amount' => 2000,
            'won_at' => now(),
        ]);

        $this->getJson('/api/opportunities/summary')
            ->assertOk()
            ->assertJsonPath('data.totals.count', 2)
            ->assertJsonPath('data.totals.amount', 3000)
            ->assertJsonPath('data.totals.won_count', 1)
            ->assertJsonPath('data.by_stage.0.count', 1)
            ->assertJsonPath('data.by_stage.1.amount', 2000);
    }

    public function test_closing_stage_returns_won_and_lost_opportunities(): void
    {
        $user = $this->authenticateWithPermissions(['opportunities.read']);
        $suffix = uniqid();
        $leadStage = OpportunityStage::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Leads '.$suffix,
            'key' => 'leads-'.$suffix,
            'position' => 1,
            'probability_percent' => 10,
        ]);
        $closingStage = OpportunityStage::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Cerrador '.$suffix,
            'key' => 'cerrador',
            'position' => 4,
            'probability_percent' => 90,
            'is_won' => false,
        ]);

        $lost = Opportunity::query()->create([
            'tenant_id' => $user->tenant_id,
            'owner_user_id' => $user->getKey(),
            'stage_id' => $closingStage->getKey(),
            'title' => 'Abierta en cerrador '.$suffix,
            'amount' => 1000,
            'lost_at' => null,
            'won_at' => null,
        ]);
        $won = Opportunity::query()->create([
            'tenant_id' => $user->tenant_id,
            'owner_user_id' => $user->getKey(),
            'stage_id' => $closingStage->getKey(),
            'title' => 'Ganada en cerrador '.$suffix,
            'amount' => 2000,
            'won_at' => now(),
            'lost_at' => null,
        ]);
        $lost = Opportunity::query()->create([
            'tenant_id' => $user->tenant_id,
            'owner_user_id' => $user->getKey(),
            'stage_id' => $closingStage->getKey(),
            'title' => 'Perdida en cerrador '.$suffix,
            'amount' => 3000,
            'won_at' => null,
            'lost_at' => now(),
        ]);
        $wonInLead = Opportunity::query()->create([
            'tenant_id' => $user->tenant_id,
            'owner_user_id' => $user->getKey(),
            'stage_id' => $leadStage->getKey(),
            'title' => 'Ganada heredada en leads '.$suffix,
            'amount' => 4000,
            'won_at' => now(),
            'lost_at' => null,
        ]);

        $this->getJson('/api/opportunities/board')
            ->assertOk()
            ->assertJsonPath('data.stages.0.summary.count', 0)
            ->assertJsonPath('data.stages.1.summary.count', 3)
            ->assertJsonPath('data.stages.1.items.0.stage_uid', $closingStage->uid)
            ->assertJsonPath('data.stages.1.items.1.stage_uid', $closingStage->uid)
            ->assertJsonPath('data.stages.1.items.2.stage_uid', $closingStage->uid);

        $this->getJson('/api/opportunities?stage_uid='.$closingStage->uid)
            ->assertOk()
            ->assertJsonCount(3, 'data');

        $returnedUids = collect($this->getJson('/api/opportunities?stage_uid='.$closingStage->uid)->json('data'))
            ->pluck('uid')
            ->all();

        $this->assertContains($won->uid, $returnedUids);
        $this->assertContains($wonInLead->uid, $returnedUids);
        $this->assertContains($lost->uid, $returnedUids);
    }

    public function test_lost_opportunities_are_not_shown_in_active_pipeline_stages_without_lost_stage(): void
    {
        $user = $this->authenticateWithPermissions(['opportunities.read']);
        $stage = OpportunityStage::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Negociacion',
            'key' => 'negociacion',
            'position' => 1,
            'probability_percent' => 70,
        ]);
        $closingStage = OpportunityStage::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Cerrador',
            'key' => 'cerrador',
            'position' => 2,
            'probability_percent' => 90,
            'is_won' => true,
        ]);

        Opportunity::query()->create([
            'tenant_id' => $user->tenant_id,
            'owner_user_id' => $user->getKey(),
            'stage_id' => $stage->getKey(),
            'title' => 'Oportunidad perdida en etapa activa',
            'amount' => 15000000,
            'won_at' => null,
            'lost_at' => now(),
        ]);

        Opportunity::query()->create([
            'tenant_id' => $user->tenant_id,
            'owner_user_id' => $user->getKey(),
            'stage_id' => $stage->getKey(),
            'title' => 'Oportunidad abierta',
            'amount' => 2000,
            'won_at' => null,
            'lost_at' => null,
        ]);

        $this->getJson('/api/opportunities/board')
            ->assertOk()
            ->assertJsonPath('data.stages.0.summary.count', 1)
            ->assertJsonPath('data.stages.0.items.0.title', 'Oportunidad abierta')
            ->assertJsonPath('data.stages.1.stage.uid', $closingStage->uid)
            ->assertJsonPath('data.stages.1.summary.count', 1)
            ->assertJsonPath('data.stages.1.items.0.title', 'Oportunidad perdida en etapa activa')
            ->assertJsonPath('data.stages.1.items.0.stage_uid', $closingStage->uid);

        $this->getJson('/api/opportunities?stage_uid='.$stage->uid)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Oportunidad abierta');
    }

    public function test_opportunity_board_limits_closed_opportunities_by_configurable_window(): void
    {
        $user = $this->authenticateWithPermissions(['opportunities.read']);
        $activeStage = OpportunityStage::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Leads',
            'key' => 'leads-window-'.uniqid(),
            'position' => 1,
            'probability_percent' => 10,
        ]);
        $closingStage = OpportunityStage::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Cerrador',
            'key' => 'cerrador-window-'.uniqid(),
            'position' => 2,
            'probability_percent' => 90,
            'is_won' => true,
        ]);

        Opportunity::query()->create([
            'tenant_id' => $user->tenant_id,
            'owner_user_id' => $user->getKey(),
            'stage_id' => $activeStage->getKey(),
            'title' => 'Abierta visible',
            'amount' => 100,
        ]);
        Opportunity::query()->create([
            'tenant_id' => $user->tenant_id,
            'owner_user_id' => $user->getKey(),
            'stage_id' => $activeStage->getKey(),
            'title' => 'Ganada reciente',
            'amount' => 200,
            'won_at' => now()->subDays(2),
        ]);
        Opportunity::query()->create([
            'tenant_id' => $user->tenant_id,
            'owner_user_id' => $user->getKey(),
            'stage_id' => $activeStage->getKey(),
            'title' => 'Ganada antigua',
            'amount' => 300,
            'won_at' => now()->subDays(20),
        ]);

        $this->getJson('/api/opportunities/board')
            ->assertOk()
            ->assertJsonPath('data.stages.0.summary.count', 1)
            ->assertJsonPath('data.stages.1.summary.count', 1)
            ->assertJsonMissing(['title' => 'Ganada antigua']);

        $this->getJson('/api/opportunities/board?closed_days=30')
            ->assertOk()
            ->assertJsonPath('data.stages.1.summary.count', 2);

        $this->getJson('/api/opportunities/board?include_closed=false')
            ->assertOk()
            ->assertJsonPath('data.stages.0.summary.count', 1)
            ->assertJsonPath('data.stages.1.summary.count', 0);
    }

    public function test_opportunity_can_be_marked_won_without_dragging_to_stage(): void
    {
        $user = $this->authenticateWithPermissions(['opportunities.read', 'opportunities.manage', 'projects.manage', 'activities.create']);
        $account = $this->account($user);
        $stage = OpportunityStage::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Negociacion',
            'key' => 'negociacion-'.uniqid(),
            'position' => 1,
            'probability_percent' => 70,
        ]);
        $wonStage = OpportunityStage::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Ganado',
            'key' => 'ganado-'.uniqid(),
            'position' => 99,
            'probability_percent' => 100,
            'is_won' => true,
        ]);
        $opportunity = Opportunity::query()->create([
            'tenant_id' => $user->tenant_id,
            'owner_user_id' => $user->getKey(),
            'stage_id' => $stage->getKey(),
            'opportunityable_type' => Account::class,
            'opportunityable_id' => $account->getKey(),
            'title' => 'Cierre directo',
            'amount' => 3000,
            'currency' => 'COP',
        ]);

        $this->postJson('/api/opportunities/'.$opportunity->uid.'/won', [
            'comment' => 'Cliente aprobo por llamada.',
        ])
            ->assertOk()
            ->assertJsonPath('data.opportunity.uid', $opportunity->uid)
            ->assertJsonPath('data.opportunity.stage_uid', $wonStage->uid);

        $this->assertDatabaseHas('opportunities', [
            'id' => $opportunity->getKey(),
            'stage_id' => $wonStage->getKey(),
            'lost_at' => null,
        ]);
        $this->assertNotNull($opportunity->fresh()->won_at);
        $this->getJson('/api/opportunities')
            ->assertOk()
            ->assertJsonPath('data.0.uid', $opportunity->uid)
            ->assertJsonPath('data.0.stage_uid', $wonStage->uid);
        $this->assertNotNull($this->getJson('/api/opportunities')->json('data.0.won_at'));
        $this->assertDatabaseHas('projects', [
            'opportunity_id' => $opportunity->getKey(),
        ]);
        $this->assertDatabaseHas('activities', [
            'activityable_type' => Opportunity::class,
            'activityable_id' => $opportunity->getKey(),
            'type' => 'note',
            'title' => 'Oportunidad marcada como ganada',
        ]);
    }

    public function test_opportunity_can_be_marked_lost_with_competitive_reasons(): void
    {
        $user = $this->authenticateWithPermissions(['opportunities.read', 'opportunities.manage', 'competitive-intelligence.manage']);
        $stage = OpportunityStage::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Negociacion',
            'key' => 'negociacion-'.uniqid(),
            'position' => 1,
            'probability_percent' => 70,
        ]);
        $lostStage = OpportunityStage::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Perdido',
            'key' => 'perdido-'.uniqid(),
            'position' => 100,
            'probability_percent' => 0,
            'is_lost' => true,
        ]);
        $opportunity = Opportunity::query()->create([
            'tenant_id' => $user->tenant_id,
            'owner_user_id' => $user->getKey(),
            'stage_id' => $stage->getKey(),
            'title' => 'Perdida directa',
            'amount' => 5000,
            'currency' => 'COP',
        ]);
        $competitor = Competitor::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Competidor X',
            'key' => 'competidor-x-'.uniqid(),
            'is_active' => true,
        ]);

        $this->postJson('/api/opportunities/'.$opportunity->uid.'/lost', [
            'lost_reasons' => [
                [
                    'category' => 'Precio',
                    'competitor_uid' => $competitor->uid,
                    'detail' => 'Ofrecieron mejor precio.',
                ],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.opportunity.uid', $opportunity->uid)
            ->assertJsonPath('data.opportunity.stage_uid', $lostStage->uid)
            ->assertJsonPath('data.lost_reasons.0.competitor_uid', $competitor->uid)
            ->assertJsonPath('data.lost_reasons.0.lost_reason_category', 'Precio')
            ->assertJsonPath('data.lost_reasons.0.lost_reason_detail', 'Ofrecieron mejor precio.');

        $this->assertDatabaseHas('opportunities', [
            'id' => $opportunity->getKey(),
            'stage_id' => $lostStage->getKey(),
            'won_at' => null,
        ]);
        $this->assertNotNull($opportunity->fresh()->lost_at);
        $this->getJson('/api/opportunities')
            ->assertOk()
            ->assertJsonPath('data.0.uid', $opportunity->uid)
            ->assertJsonPath('data.0.stage_uid', $lostStage->uid);
        $this->assertNotNull($this->getJson('/api/opportunities')->json('data.0.lost_at'));
        $this->assertDatabaseHas('lost_reasons', [
            'opportunity_id' => $opportunity->getKey(),
            'competitor_id' => $competitor->getKey(),
            'reason_type' => 'price',
        ]);

        $this->postJson('/api/opportunities/'.$opportunity->uid.'/lost', [
            'lost_reasons' => [
                [
                    'category' => 'Precio',
                    'competitor_uid' => $competitor->uid,
                    'detail' => 'Intento duplicado.',
                ],
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.opportunity.0', 'La oportunidad ya tiene una perdida registrada');
    }

    public function test_opportunity_lost_flow_can_create_competitor_inline(): void
    {
        $user = $this->authenticateWithPermissions(['opportunities.read', 'opportunities.manage']);
        $stage = OpportunityStage::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Negociacion',
            'key' => 'negociacion-'.uniqid(),
            'position' => 1,
            'probability_percent' => 70,
        ]);
        OpportunityStage::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Perdido',
            'key' => 'perdido-'.uniqid(),
            'position' => 100,
            'probability_percent' => 0,
            'is_lost' => true,
        ]);
        $opportunity = Opportunity::query()->create([
            'tenant_id' => $user->tenant_id,
            'owner_user_id' => $user->getKey(),
            'stage_id' => $stage->getKey(),
            'title' => 'Perdida con competidor nuevo',
            'amount' => 7000,
            'currency' => 'COP',
        ]);

        $response = $this->postJson('/api/opportunities/'.$opportunity->uid.'/lost', [
            'lost_reasons' => [
                [
                    'reason_type' => 'price',
                    'competitor' => [
                        'name' => 'Competidor Nuevo',
                        'type' => 'direct',
                        'description' => 'Compite directo en precio.',
                    ],
                    'notes' => 'Perdimos por precio',
                ],
            ],
            'notes' => 'Comentario general de cierre',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.opportunity.uid', $opportunity->uid)
            ->assertJsonPath('data.lost_reasons.0.competitor_name', 'Competidor Nuevo')
            ->assertJsonPath('data.lost_reasons.0.reason_type', 'price')
            ->assertJsonPath('data.lost_reasons.0.details', 'Perdimos por precio');

        $competitor = Competitor::query()->where('name', 'Competidor Nuevo')->firstOrFail();

        $this->assertDatabaseHas('lost_reasons', [
            'opportunity_id' => $opportunity->getKey(),
            'competitor_id' => $competitor->getKey(),
            'reason_type' => 'price',
        ]);
        $this->assertDatabaseHas('activities', [
            'activityable_type' => Opportunity::class,
            'activityable_id' => $opportunity->getKey(),
            'type' => 'note',
            'title' => 'Oportunidad marcada como perdida',
        ]);
    }

    public function test_opportunity_tasks_shortcut_reuses_generic_tasks(): void
    {
        $user = $this->authenticateWithPermissions(['opportunities.read', 'tasks.read', 'tasks.create']);
        $stage = OpportunityStage::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Seguimiento',
            'key' => 'seguimiento-'.uniqid(),
            'position' => 1,
            'probability_percent' => 30,
        ]);
        $opportunity = Opportunity::query()->create([
            'tenant_id' => $user->tenant_id,
            'owner_user_id' => $user->getKey(),
            'stage_id' => $stage->getKey(),
            'title' => 'Con checklist',
            'amount' => 1000,
        ]);
        Task::query()->create([
            'tenant_id' => $user->tenant_id,
            'owner_user_id' => $user->getKey(),
            'title' => 'Otra tarea',
            'status' => 'pending',
        ]);

        $created = $this->postJson('/api/opportunities/'.$opportunity->uid.'/tasks', [
            'title' => 'Enviar propuesta',
            'priority' => 'high',
        ])
            ->assertCreated()
            ->assertJsonPath('data.taskable_uid', $opportunity->uid)
            ->assertJsonPath('data.taskable_type', 'opportunity')
            ->assertJsonMissingPath('data.taskable');

        $this->getJson('/api/opportunities/'.$opportunity->uid.'/tasks')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.uid', $created->json('data.uid'))
            ->assertJsonPath('data.0.taskable_uid', $opportunity->uid)
            ->assertJsonMissingPath('data.0.taskable');
    }

    public function test_opportunity_activities_shortcut_returns_serialized_payload(): void
    {
        $user = $this->authenticateWithPermissions(['opportunities.read', 'activities.read', 'activities.create', 'activities.update']);
        $stage = OpportunityStage::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Seguimiento',
            'key' => 'seguimiento-'.uniqid(),
            'position' => 1,
            'probability_percent' => 30,
        ]);
        $opportunity = Opportunity::query()->create([
            'tenant_id' => $user->tenant_id,
            'owner_user_id' => $user->getKey(),
            'stage_id' => $stage->getKey(),
            'title' => 'Con actividad',
            'amount' => 1000,
        ]);

        $created = $this->postJson('/api/opportunities/'.$opportunity->uid.'/activities', [
            'type' => 'call',
            'title' => 'Llamar decisor',
            'description' => 'Confirmar alcance',
            'scheduled_at' => '2026-06-06T00:00:00.000Z',
            'status' => 'pending',
        ]);

        $created
            ->assertCreated()
            ->assertJsonPath('data.title', 'Llamar decisor')
            ->assertJsonPath('data.activityable_uid', $opportunity->uid)
            ->assertJsonPath('data.activityable_type', Opportunity::class);

        $this->assertDatabaseHas('activities', [
            'activityable_type' => Opportunity::class,
            'activityable_id' => $opportunity->getKey(),
            'title' => 'Llamar decisor',
        ]);

        $this->putJson('/api/opportunities/'.$opportunity->uid.'/activities/'.$created->json('data.uid'), [
            'title' => 'Llamar decisor actualizado',
            'status' => 'in_progress',
        ])
            ->assertOk()
            ->assertJsonPath('data.uid', $created->json('data.uid'))
            ->assertJsonPath('data.title', 'Llamar decisor actualizado')
            ->assertJsonPath('data.activityable_uid', $opportunity->uid);
    }

    private function account(User $user): Account
    {
        return Account::query()->create([
            'tenant_id' => $user->tenant_id,
            'owner_user_id' => $user->getKey(),
            'name' => 'Cliente Ventas',
            'document' => 'SALES-'.uniqid(),
        ]);
    }

    private function authenticateWithPermissions(array $permissionKeys): User
    {
        $tenant = Tenant::query()->create([
            'name' => 'Tenant Sales',
            'status' => 'active',
            'is_active' => true,
        ]);

        foreach ($permissionKeys as $key) {
            Permission::query()->firstOrCreate(
                ['key' => $key],
                [
                    'module' => 'finance',
                    'action' => $key,
                    'description' => $key,
                ]
            );
        }

        $user = User::query()->create([
            'tenant_id' => $tenant->getKey(),
            'name' => 'Sales Owner',
            'email' => 'sales-owner+'.uniqid().'@example.test',
            'password' => bcrypt('secret123'),
        ]);

        $permissionIds = Permission::query()->whereIn('key', $permissionKeys)->pluck('id')->all();
        $user->permissions()->sync($permissionIds);

        Sanctum::actingAs($user, ['access:full', 'tenant:'.$tenant->uid]);

        return $user;
    }
}
