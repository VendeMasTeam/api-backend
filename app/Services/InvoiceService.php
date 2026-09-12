<?php

namespace App\Services;

use App\Models\Account;
use App\Models\InventoryProduct;
use App\Models\InventoryReservation;
use App\Models\InventoryStock;
use App\Models\Invoice;
use App\Models\Opportunity;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Support\ApiIndex;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class InvoiceService
{
    public function __construct(
        private readonly InventoryService $inventoryService,
        private readonly CreditService $creditService,
        private readonly FinancialOperationsService $financialOperationsService,
        private readonly DocumentValidationService $documentValidationService,
        private readonly ExportService $exportService
    ) {}

    public function list(array $filters = [])
    {
        $validated = Validator::make($filters, [
            'entity_type' => 'nullable|string',
            'entity_uid' => 'nullable|uuid',
            'quotation_uid' => 'nullable|uuid',
            'opportunity_uid' => 'nullable|uuid',
            'status' => 'nullable|string|in:draft,issued,partial,paid,overdue',
            'search' => 'nullable|string|max:255',
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
            'paginate' => 'sometimes',
        ])->validate();

        $query = Invoice::query()
            ->with(['quotation:id,uid', 'invoiceable'])
            ->latest();

        if (! empty($validated['entity_type']) || ! empty($validated['entity_uid'])) {
            $entity = $this->resolveEntity($validated['entity_type'] ?? null, $validated['entity_uid'] ?? null);
            $query->where('invoiceable_type', get_class($entity))
                ->where('invoiceable_id', $entity->getKey());
        }

        if (! empty($validated['quotation_uid'])) {
            $query->where('quotation_id', $this->resolveQuotation($validated['quotation_uid'])->getKey());
        }

        if (! empty($validated['opportunity_uid'])) {
            $opportunity = Opportunity::query()->where('uid', $validated['opportunity_uid'])->first();

            $query->whereHas('quotation', function ($quotationQuery) use ($opportunity) {
                $quotationQuery
                    ->where('quoteable_type', Opportunity::class)
                    ->where('quoteable_id', $opportunity?->getKey() ?: 0);
            });
        }

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['search'])) {
            $search = '%'.mb_strtolower($validated['search']).'%';
            $query->where(function ($builder) use ($search) {
                $builder
                    ->whereRaw('LOWER(invoice_number) LIKE ?', [$search])
                    ->orWhereRaw('LOWER(CAST(uid AS TEXT)) LIKE ?', [$search])
                    ->orWhereHas('quotation', fn ($quotationQuery) => $quotationQuery->whereRaw('LOWER(CAST(uid AS TEXT)) LIKE ?', [$search]));
            });
        }

        $withoutPagination = filter_var($filters['paginate'] ?? true, FILTER_VALIDATE_BOOLEAN) === false;

        $result = $withoutPagination
            ? $query->limit(min(max((int) ($filters['per_page'] ?? 25), 1), 100))->get()
            : $query->paginate(
                ApiIndex::perPage($filters),
                ['*'],
                'invoices_page',
                ApiIndex::page($filters)
            );

        return $this->mapInvoiceIndexResult($result);
    }

    public function getByUid(string $uid): Invoice
    {
        return Invoice::query()
            ->with([
                'quotation.priceBook',
                'quotation.items.product',
                'quotation.items.catalogProduct',
                'quotation.items.warehouse',
                'quotation.quoteable',
                'invoiceable',
                'payments',
            ])
            ->where('uid', $uid)
            ->firstOrFail();
    }

    public function payload(Invoice $invoice): array
    {
        return $this->serializeInvoiceIndex($invoice) + [
            'payments' => $invoice->payments?->values() ?? [],
        ];
    }

    public function createFromQuotation(array $data): Invoice
    {
        $validated = Validator::make($data, [
            'quotation_uid' => 'required|uuid',
            'currency' => 'required|string|max:10',
            'exchange_rate' => 'sometimes|numeric|min:0.000001',
            'due_date' => 'nullable|date',
            'status' => 'sometimes|string|in:draft,issued',
        ])->validate();

        return DB::transaction(function () use ($validated) {
            $quotation = Quotation::query()
                ->with(['items.product', 'items.catalogProduct.inventoryProduct', 'items.warehouse', 'quoteable'])
                ->where('uid', $validated['quotation_uid'])
                ->firstOrFail();
            $entity = $quotation->quoteable;

            if (! $entity) {
                throw ValidationException::withMessages([
                    'quotation_uid' => ['La cotizacion no tiene cliente asociado'],
                ]);
            }

            $this->creditService->ensureCanOperate($entity);
            if ($entity instanceof Account) {
                $this->documentValidationService->ensureReadyForAccount($entity);
            }

            $this->reservePendingQuotationStock($quotation);
            $quotation->load(['items.product', 'items.catalogProduct.inventoryProduct', 'items.warehouse']);
            $this->ensureQuotationItemsAreReadyToInvoice($quotation);

            $invoiceNumber = $this->generateInvoiceNumber($quotation->tenant_id);

            $exchangeRate = (float) ($validated['exchange_rate'] ?? $quotation->exchange_rate ?? 1);
            $invoiceTotal = round((float) $quotation->total * $exchangeRate, 2);
            $invoiceSubtotal = round((float) $quotation->subtotal * $exchangeRate, 2);
            $invoiceDiscount = round((float) $quotation->discount_total * $exchangeRate, 2);

            $invoice = Invoice::query()->create([
                'quotation_id' => $quotation->getKey(),
                'invoiceable_type' => get_class($entity),
                'invoiceable_id' => $entity->getKey(),
                'invoice_number' => $invoiceNumber,
                'status' => $validated['status'] ?? 'issued',
                'quote_currency' => $quotation->currency,
                'exchange_rate' => $exchangeRate,
                'currency' => $validated['currency'],
                'subtotal' => $invoiceSubtotal,
                'discount_total' => $invoiceDiscount,
                'total' => $invoiceTotal,
                'paid_total' => 0,
                'outstanding_total' => $invoiceTotal,
                'issued_at' => now()->toDateString(),
                'due_date' => $validated['due_date'] ?? now()->addDays(30)->toDateString(),
                'meta' => [
                    'quotation_total' => (float) $quotation->total,
                ],
            ]);

            $quotation->update(['status' => 'invoiced']);

            foreach ($quotation->items as $item) {
                $reservations = InventoryReservation::query()
                    ->where('source_type', 'quotation_item')
                    ->where('source_uid', $item->uid)
                    ->where('status', 'active')
                    ->get();

                foreach ($reservations as $reservation) {
                    $this->inventoryService->consumeReservation($reservation->uid, [
                        'reference_type' => 'invoice',
                        'reference_uid' => $invoice->uid,
                        'comment' => 'Consumo por facturacion',
                    ]);
                }
            }

            $recordStatus = $invoice->due_date && now()->gt($invoice->due_date) ? 'overdue' : 'open';
            if ($invoice->status === 'draft') {
                $recordStatus = 'open';
            }

            $this->financialOperationsService->importRecord([
                'entity_type' => get_class($entity),
                'entity_uid' => $entity->uid,
                'quotation_uid' => $quotation->uid,
                'record_type' => 'invoice_open',
                'source_system' => 'internal_finance',
                'external_reference' => $invoice->invoice_number,
                'amount' => $invoice->total,
                'outstanding_amount' => $invoice->outstanding_total,
                'currency' => $invoice->currency,
                'issued_at' => $invoice->issued_at?->toDateString(),
                'due_at' => $invoice->due_date?->toDateString(),
                'status' => $recordStatus,
                'meta' => [
                    'invoice_uid' => $invoice->uid,
                ],
            ]);

            return $invoice->fresh(['quotation', 'invoiceable', 'payments']);
        });
    }

    public function export(array $payload)
    {
        $validated = Validator::make($payload, [
            'format' => 'required|string|in:excel,pdf,csv',
            'fields' => 'nullable|array',
            'fields.*' => 'string',
            'filters' => 'nullable|array',
            'filters.status' => 'nullable|string|in:draft,issued,partial,paid,overdue',
            'filters.date_from' => 'nullable|date_format:Y-m-d',
            'filters.date_to' => 'nullable|date_format:Y-m-d',
        ])->validate();

        $filters = $validated['filters'] ?? [];
        $rows = Invoice::query()
            ->with(['quotation', 'invoiceable', 'payments'])
            ->when(! empty($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->when(! empty($filters['date_from']), fn ($query) => $query->whereDate('issued_at', '>=', $filters['date_from']))
            ->when(! empty($filters['date_to']), fn ($query) => $query->whereDate('issued_at', '<=', $filters['date_to']))
            ->latest('issued_at')
            ->limit(50000)
            ->get()
            ->map(fn (Invoice $invoice) => [
                'uid' => $invoice->uid,
                'invoice_number' => $invoice->invoice_number,
                'customer' => $invoice->invoiceable?->display_name ?? $invoice->invoiceable?->name,
                'status' => $invoice->status,
                'currency' => $invoice->currency,
                'subtotal' => (float) $invoice->subtotal,
                'total' => (float) $invoice->total,
                'paid_total' => (float) $invoice->paid_total,
                'outstanding_total' => (float) $invoice->outstanding_total,
                'issued_at' => $invoice->issued_at?->toDateString(),
                'due_date' => $invoice->due_date?->toDateString(),
            ]);

        return $this->exportService->file('facturas', $rows, [
            'format' => $validated['format'],
            'fields' => $validated['fields'] ?? [],
            'filters' => $filters,
        ]);
    }

    public function syncOverdue(?int $tenantId = null): array
    {
        return DB::transaction(function () use ($tenantId) {
            $invoices = Invoice::query()
                ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
                ->whereIn('status', ['issued', 'partial'])
                ->whereDate('due_date', '<', now()->toDateString())
                ->get();

            $updated = 0;

            foreach ($invoices as $invoice) {
                $invoice->update(['status' => 'overdue']);

                $entity = $invoice->invoiceable;
                if ($entity) {
                    $this->financialOperationsService->importRecord([
                        'entity_type' => get_class($entity),
                        'entity_uid' => $entity->uid,
                        'quotation_uid' => $invoice->quotation?->uid,
                        'record_type' => 'invoice_open',
                        'source_system' => 'internal_finance',
                        'external_reference' => $invoice->invoice_number,
                        'amount' => $invoice->total,
                        'outstanding_amount' => $invoice->outstanding_total,
                        'currency' => $invoice->currency,
                        'issued_at' => $invoice->issued_at?->toDateString(),
                        'due_at' => $invoice->due_date?->toDateString(),
                        'status' => 'overdue',
                        'meta' => [
                            'invoice_uid' => $invoice->uid,
                        ],
                    ]);
                }

                $updated++;
            }

            return [
                'updated_invoices' => $updated,
            ];
        });
    }

    private function mapInvoiceIndexResult($result)
    {
        if (method_exists($result, 'through')) {
            return $result->through(fn (Invoice $invoice) => $this->serializeInvoiceIndex($invoice));
        }

        return collect($result)
            ->map(fn (Invoice $invoice) => $this->serializeInvoiceIndex($invoice))
            ->values();
    }

    private function serializeInvoiceIndex(Invoice $invoice): array
    {
        return [
            'uid' => $invoice->uid,
            'quotation_uid' => $invoice->quotation?->uid,
            'invoiceable_uid' => $invoice->invoiceable_uid,
            'entity_type' => $invoice->entity_type,
            'entity_label' => $invoice->entity_label,
            'entity_uid' => $invoice->entity_uid,
            'client_name' => $invoice->client_name,
            'client_email' => $invoice->client_email,
            'invoice_number' => $invoice->invoice_number,
            'status' => $invoice->status,
            'quote_currency' => $invoice->quote_currency,
            'exchange_rate' => (float) $invoice->exchange_rate,
            'currency' => $invoice->currency,
            'subtotal' => (float) $invoice->subtotal,
            'discount_total' => (float) $invoice->discount_total,
            'total' => (float) $invoice->total,
            'paid_total' => (float) $invoice->paid_total,
            'outstanding_total' => (float) $invoice->outstanding_total,
            'issued_at' => $invoice->issued_at,
            'due_date' => $invoice->due_date,
            'created_at' => $invoice->created_at,
            'updated_at' => $invoice->updated_at,
        ];
    }

    private function resolveEntity(?string $type, ?string $uid)
    {
        if (! $type || ! $uid) {
            throw ValidationException::withMessages([
                'entity_uid' => ['Debes enviar entity_type y entity_uid'],
            ]);
        }

        $entity = find_entity_by_uid($type, $uid);

        if (! $entity) {
            throw ValidationException::withMessages([
                'entity_uid' => ['La entidad no existe o no es visible'],
            ]);
        }

        return $entity;
    }

    private function resolveQuotation(string $uid): Quotation
    {
        return Quotation::query()->where('uid', $uid)->firstOrFail();
    }

    private function generateInvoiceNumber(int $tenantId): string
    {
        $this->acquireInvoiceNumberLock($tenantId);

        $prefix = 'INV-'.now()->format('Y').'-';
        $lastNumber = Invoice::query()
            ->where('tenant_id', $tenantId)
            ->where('invoice_number', 'like', $prefix.'%')
            ->lockForUpdate()
            ->orderByDesc('invoice_number')
            ->value('invoice_number');

        $nextSequence = 1;

        if ($lastNumber && preg_match('/^'.preg_quote($prefix, '/').'(\d+)$/', $lastNumber, $matches)) {
            $nextSequence = ((int) $matches[1]) + 1;
        }

        do {
            $invoiceNumber = $prefix.str_pad((string) $nextSequence, 4, '0', STR_PAD_LEFT);
            $nextSequence++;
        } while (
            Invoice::query()
                ->where('tenant_id', $tenantId)
                ->where('invoice_number', $invoiceNumber)
                ->exists()
        );

        return $invoiceNumber;
    }

    private function acquireInvoiceNumberLock(int $tenantId): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::select('select pg_advisory_xact_lock(hashtext(?))', ['invoice_number:'.$tenantId]);
    }

    private function itemRequiresStockReservation(QuotationItem $item): bool
    {
        $catalogType = strtolower((string) $item->catalogProduct?->type);

        if (in_array($catalogType, ['service', 'services', 'servicio'], true)) {
            return false;
        }

        return true;
    }

    private function reservePendingQuotationStock(Quotation $quotation): void
    {
        foreach ($quotation->items as $item) {
            $this->hydrateItemProductLinks($item);

            if ($item->quantity <= 0 || ! $this->itemRequiresStockReservation($item)) {
                continue;
            }

            $product = $this->resolveInventoryProductForReservation($item);

            if (! $product) {
                continue;
            }

            $pendingQuantity = max(0, (int) $item->quantity - (int) $item->reserved_quantity);

            if ($pendingQuantity === 0) {
                continue;
            }

            $this->reserveItemAcrossWarehouses($item, $product, $pendingQuantity);
        }
    }

    private function hydrateItemProductLinks(QuotationItem $item): void
    {
        $catalogProduct = $item->catalogProduct;
        $product = $item->product;

        if (! $catalogProduct && $item->sku) {
            $catalogProduct = Product::query()
                ->with('inventoryProduct')
                ->where('sku', $item->sku)
                ->first();

            if ($catalogProduct) {
                $item->setRelation('catalogProduct', $catalogProduct);
            }
        }

        if (! $product && $catalogProduct?->inventoryProduct) {
            $product = $catalogProduct->inventoryProduct;
            $item->setRelation('product', $product);
        }

        if (! $product && $item->sku) {
            $product = InventoryProduct::query()
                ->where('sku', $item->sku)
                ->first();

            if ($product) {
                $item->setRelation('product', $product);
            }
        }

        $payload = [];

        if (! $item->catalog_product_id && $catalogProduct) {
            $payload['catalog_product_id'] = $catalogProduct->getKey();
        }

        if (! $item->product_id && $product) {
            $payload['product_id'] = $product->getKey();
        }

        if ($payload !== []) {
            $item->forceFill($payload)->save();
        }
    }

    private function resolveInventoryProductForReservation(QuotationItem $item): ?InventoryProduct
    {
        $product = $item->product ?? $item->catalogProduct?->inventoryProduct;

        if (! $product) {
            return null;
        }

        if (! $item->product_id) {
            $item->forceFill(['product_id' => $product->getKey()])->save();
            $item->setRelation('product', $product);
        }

        return $product;
    }

    private function reserveItemAcrossWarehouses(QuotationItem $item, InventoryProduct $product, int $quantity): void
    {
        $stocks = InventoryStock::query()
            ->with('warehouse')
            ->where('product_id', $product->getKey())
            ->get()
            ->map(fn (InventoryStock $stock) => [
                'stock' => $stock,
                'available' => max(0, (int) $stock->physical_stock - (int) $stock->reserved_stock),
                'preferred' => $item->warehouse_id && $stock->warehouse_id === $item->warehouse_id,
            ])
            ->filter(fn (array $row) => $row['available'] > 0)
            ->sortBy([
                ['preferred', 'desc'],
                ['available', 'desc'],
            ])
            ->values();

        $available = (int) $stocks->sum('available');

        if ($available < $quantity) {
            throw ValidationException::withMessages([
                'quotation_uid' => ['No puedes facturar sin stock reservado suficiente para los productos fisicos'],
                'items' => [
                    $this->stockValidationMessage(
                        $item,
                        sprintf('requiere %s unidades, disponible %s y faltan %s', $quantity, $available, max(0, $quantity - $available))
                    ),
                ],
            ]);
        }

        $remaining = $quantity;
        $firstWarehouseId = null;

        foreach ($stocks as $row) {
            if ($remaining <= 0) {
                break;
            }

            /** @var InventoryStock $stock */
            $stock = $row['stock'];
            $reservedQuantity = min($remaining, $row['available']);

            $this->inventoryService->reserveStock([
                'product_uid' => $product->uid,
                'warehouse_uid' => $stock->warehouse?->uid,
                'quantity' => $reservedQuantity,
                'source_type' => 'quotation_item',
                'source_uid' => $item->uid,
                'comment' => 'Reserva automatica por facturacion',
            ]);

            $firstWarehouseId ??= $stock->warehouse_id;
            $remaining -= $reservedQuantity;
        }

        if (! $item->warehouse_id && $firstWarehouseId) {
            $item->forceFill(['warehouse_id' => $firstWarehouseId])->save();
        }
    }

    private function ensureQuotationItemsAreReadyToInvoice(Quotation $quotation): void
    {
        $missingSetup = [];
        $missingReservations = [];

        foreach ($quotation->items as $item) {
            if ($item->quantity <= 0 || ! $this->itemRequiresStockReservation($item)) {
                continue;
            }

            if (! $item->product_id || ! $item->warehouse_id) {
                $missingSetup[] = $this->stockValidationMessage($item, 'no tiene producto de inventario o bodega asociada');

                continue;
            }

            if ($item->reserved_quantity < $item->quantity) {
                $missingReservations[] = $this->stockValidationMessage(
                    $item,
                    sprintf(
                        'requiere %s unidades, tiene %s reservadas y faltan %s',
                        (int) $item->quantity,
                        (int) $item->reserved_quantity,
                        max(0, (int) $item->quantity - (int) $item->reserved_quantity)
                    )
                );
            }
        }

        if ($missingSetup !== []) {
            throw ValidationException::withMessages([
                'quotation_uid' => ['Hay items de producto sin configuracion de inventario para facturar'],
                'items' => $missingSetup,
            ]);
        }

        if ($missingReservations !== []) {
            throw ValidationException::withMessages([
                'quotation_uid' => ['No puedes facturar sin stock reservado suficiente para los productos fisicos'],
                'items' => $missingReservations,
            ]);
        }
    }

    private function stockValidationMessage(QuotationItem $item, string $reason): string
    {
        $name = $item->catalogProduct?->name
            ?? $item->product?->name
            ?? $item->description
            ?? 'Item sin nombre';
        $sku = $item->sku ? ' SKU '.$item->sku : '';
        $warehouse = $item->warehouse?->name ? ' en '.$item->warehouse->name : '';

        return trim($name.$sku.$warehouse.': '.$reason);
    }
}
