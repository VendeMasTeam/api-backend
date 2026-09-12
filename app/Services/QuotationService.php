<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Contact;
use App\Models\CrmEntity;
use App\Models\InventoryProduct;
use App\Models\InventoryReservation;
use App\Models\InventoryStock;
use App\Models\Opportunity;
use App\Models\PriceBook;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\Warehouse;
use App\Support\ApiIndex;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class QuotationService
{
    public function __construct(
        private readonly InventoryService $inventoryService,
        private readonly PriceBookService $priceBookService,
        private readonly PricingService $pricingService,
        private readonly CreditService $creditService,
        private readonly DocumentValidationService $documentValidationService,
        private readonly ProductService $productService,
        private readonly DependencyService $dependencyService
    ) {}

    public function getAll(array $filters = [])
    {
        $validated = Validator::make($filters, [
            'search' => 'nullable|string|max:255',
            'status' => 'nullable|string|in:draft,sent,approved,rejected,cancelled,invoiced',
            'opportunity_uid' => 'nullable|uuid',
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
            'paginate' => 'sometimes',
        ])->validate();

        $query = Quotation::query()
            ->with(['priceBook', 'items.product', 'items.catalogProduct', 'items.warehouse'])
            ->latest();

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['opportunity_uid'])) {
            $opportunity = Opportunity::query()->where('uid', $validated['opportunity_uid'])->firstOrFail();
            $query->where('quoteable_type', Opportunity::class)
                ->where('quoteable_id', $opportunity->getKey());
        }

        if (! empty($validated['search'])) {
            $search = '%'.mb_strtolower($validated['search']).'%';
            $query->where(function ($builder) use ($search) {
                $builder
                    ->whereRaw('LOWER(quote_number) LIKE ?', [$search])
                    ->orWhereRaw('LOWER(title) LIKE ?', [$search])
                    ->orWhereHasMorph('quoteable', [Account::class, Contact::class, CrmEntity::class], function ($entityQuery) use ($search) {
                        $entityQuery->whereRaw('LOWER(CAST(uid AS TEXT)) LIKE ?', [$search]);
                    });
            });
        }

        $withoutPagination = filter_var($filters['paginate'] ?? true, FILTER_VALIDATE_BOOLEAN) === false;

        $result = $withoutPagination
            ? $query->limit(min(max((int) ($filters['per_page'] ?? 25), 1), 100))->get()
            : $query->paginate(
                ApiIndex::perPage($filters),
                ['*'],
                'quotations_page',
                ApiIndex::page($filters)
            );

        return $this->mapQuotationIndexResult($result);
    }

    public function getByUid(string $uid): Quotation
    {
        return $this->quotationByIdentifier($uid)
            ->with(['priceBook', 'items.product', 'items.catalogProduct', 'items.warehouse', 'quoteable'])
            ->firstOrFail();
    }

    public function payload(Quotation $quotation): array
    {
        return $this->serializeQuotationIndex($quotation);
    }

    public function create(array $data): Quotation
    {
        $data = $this->normalizeQuotationPayload($data);
        $validated = $this->validateQuotation($data);

        return DB::transaction(function () use ($validated) {
            $quoteable = $this->resolveQuoteable($validated['entity_type'] ?? null, $validated['entity_uid'] ?? null);
            $priceBook = $this->resolvePriceBook($validated['price_book_uid'] ?? null);
            if ($quoteable) {
                $this->creditService->ensureCanOperate($quoteable);
                $this->ensureDocumentsReady($quoteable);
            }

            $quotation = Quotation::query()->create([
                'owner_user_id' => $quoteable?->owner_user_id ?? auth()->id(),
                'created_by_user_id' => auth()->id(),
                'price_book_id' => $priceBook?->getKey(),
                'quoteable_type' => $quoteable ? get_class($quoteable) : null,
                'quoteable_id' => $quoteable?->getKey(),
                'quote_number' => $validated['quote_number'] ?? $this->generateQuoteNumber(),
                'title' => $validated['title'],
                'status' => $validated['status'] ?? 'draft',
                'currency' => $validated['currency'] ?? null,
                'exchange_rate' => $validated['exchange_rate'] ?? 1,
                'local_currency' => $validated['local_currency'] ?? (auth()->user()?->tenant?->currency?->code ?? 'COP'),
                'valid_until' => $validated['valid_until'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ]);

            if (! empty($validated['items'])) {
                $this->syncQuotationItems($quotation->fresh(['priceBook']), $validated['items']);
            }

            return $quotation->fresh(['priceBook', 'items.product', 'items.catalogProduct', 'items.warehouse', 'quoteable']);
        });
    }

    public function update(string $uid, array $data): Quotation
    {
        $quotation = $this->getByUid($uid);
        $data = $this->normalizeQuotationPayload($data);
        $validated = $this->validateQuotation($data, true);

        return DB::transaction(function () use ($quotation, $validated) {
            $payload = [];
            $previousStatus = $quotation->status;

            foreach (['quote_number', 'title', 'status', 'currency', 'exchange_rate', 'local_currency', 'valid_until', 'notes'] as $field) {
                if (array_key_exists($field, $validated)) {
                    $payload[$field] = $validated[$field];
                }
            }

            if (array_key_exists('price_book_uid', $validated)) {
                $payload['price_book_id'] = $this->resolvePriceBook($validated['price_book_uid'])?->getKey();
            }

            if (array_key_exists('entity_type', $validated) || array_key_exists('entity_uid', $validated)) {
                $quoteable = $this->resolveQuoteable($validated['entity_type'] ?? null, $validated['entity_uid'] ?? null);
                $payload['quoteable_type'] = $quoteable ? get_class($quoteable) : null;
                $payload['quoteable_id'] = $quoteable?->getKey();
                $payload['owner_user_id'] = $quoteable?->owner_user_id ?? $quotation->owner_user_id;
            }

            if (($payload['status'] ?? null) === 'cancelled' && $quotation->invoices()->exists()) {
                $payload['status'] = 'invoiced';
            }

            if ($payload !== []) {
                $quotation->update($payload);
            }

            if (array_key_exists('items', $validated)) {
                $this->syncQuotationItems($quotation->fresh(['priceBook', 'items.product', 'items.catalogProduct', 'items.warehouse']), $validated['items'] ?? []);
            }

            $quotation = $quotation->fresh(['priceBook', 'items.product', 'items.catalogProduct', 'items.warehouse', 'quoteable']);
            if ($quotation->quoteable) {
                $this->creditService->ensureCanOperate($quotation->quoteable);
                $this->ensureDocumentsReady($quotation->quoteable);
            }

            if (($payload['status'] ?? null) === 'approved' && $previousStatus !== 'approved') {
                $this->validateQuotationDependencies($quotation);
                $this->reservePendingQuotationStock($quotation);
                $quotation = $quotation->fresh(['priceBook', 'items.product', 'items.catalogProduct', 'items.warehouse', 'quoteable']);
            }

            return $quotation;
        });
    }

    private function ensureDocumentsReady(object $entity): void
    {
        if ($entity instanceof Account) {
            $this->documentValidationService->ensureReadyForAccount($entity);
        }
    }

    public function addItem(string $quotationUid, array $data): QuotationItem
    {
        $quotation = $this->getByUid($quotationUid);
        $validated = $this->validateItem($data);

        return DB::transaction(function () use ($quotation, $validated) {
            $catalogProduct = $this->resolveCatalogProduct($validated['catalog_product_uid'] ?? null);
            $product = $this->resolveProduct(
                $validated['product_uid'] ?? $catalogProduct?->inventoryProduct?->uid
            );
            $warehouse = $this->resolveWarehouse($validated['warehouse_uid'] ?? null);
            $pricing = $this->buildPricingPayload(
                $quotation->priceBook,
                $product,
                (int) $validated['quantity'],
                $validated['unit_price'] ?? null,
                $validated['discount_percent'] ?? (float) ($catalogProduct?->default_discount_percent ?? 0),
                $validated['discount_amount'] ?? null,
                $catalogProduct?->default_price
            );

            $item = QuotationItem::query()->create([
                'quotation_id' => $quotation->getKey(),
                'product_id' => $product?->getKey(),
                'catalog_product_id' => $catalogProduct?->getKey(),
                'warehouse_id' => $warehouse?->getKey(),
                'sku' => $validated['sku'] ?? $catalogProduct?->sku ?? $product?->sku,
                'description' => $validated['description'] ?? $catalogProduct?->name,
                'quantity' => $validated['quantity'],
                'list_unit_price' => $pricing['list_unit_price'],
                'discount_percent' => $pricing['discount_percent'],
                'discount_amount' => $pricing['discount_amount'],
                'net_unit_price' => $pricing['net_unit_price'],
                'unit_price' => $pricing['net_unit_price'],
                'unit_cost' => $pricing['unit_cost'],
                'margin_amount' => $pricing['margin_amount'],
                'margin_percent' => $pricing['margin_percent'],
                'min_margin_percent' => $pricing['min_margin_percent'],
                'below_min_margin' => $pricing['below_min_margin'],
            ]);

            if ($catalogProduct) {
                $this->syncRequiredDependencies($quotation, $catalogProduct, $item);
            }

            return $item->fresh(['quotation', 'product', 'catalogProduct', 'warehouse']);
        });
    }

    public function updateItem(string $itemUid, array $data): QuotationItem
    {
        $item = $this->getItemByUid($itemUid);
        $validated = $this->validateItem($data, true);

        return DB::transaction(function () use ($item, $validated) {
            $payload = [];
            $product = $item->product;
            $catalogProduct = $item->catalogProduct;

            if (array_key_exists('product_uid', $validated)) {
                $product = $this->resolveProduct($validated['product_uid']);
                $payload['product_id'] = $product?->getKey();
            }

            if (array_key_exists('catalog_product_uid', $validated)) {
                $catalogProduct = $this->resolveCatalogProduct($validated['catalog_product_uid']);
                $payload['catalog_product_id'] = $catalogProduct?->getKey();

                if (! array_key_exists('product_uid', $validated)) {
                    $product = $this->resolveProduct($catalogProduct?->inventoryProduct?->uid);
                    $payload['product_id'] = $product?->getKey();
                }
            }

            if (array_key_exists('warehouse_uid', $validated)) {
                $payload['warehouse_id'] = $this->resolveWarehouse($validated['warehouse_uid'])?->getKey();
            }

            foreach (['sku', 'description', 'quantity'] as $field) {
                if (array_key_exists($field, $validated)) {
                    $payload[$field] = $validated[$field];
                }
            }

            if (array_key_exists('quantity', $validated) && $item->reserved_quantity > (int) $validated['quantity']) {
                throw ValidationException::withMessages([
                    'quantity' => ['La cantidad no puede ser menor que el stock ya reservado'],
                ]);
            }

            if (
                array_key_exists('quantity', $validated)
                || array_key_exists('product_uid', $validated)
                || array_key_exists('unit_price', $validated)
                || array_key_exists('discount_percent', $validated)
                || array_key_exists('discount_amount', $validated)
            ) {
                $quotation = $item->quotation ?? $this->getByUid($item->quotation_uid);
                $quantity = (int) ($validated['quantity'] ?? $item->quantity);
                $pricing = $this->buildPricingPayload(
                    $quotation->priceBook,
                    $product,
                    $quantity,
                    $validated['unit_price'] ?? null,
                    $validated['discount_percent'] ?? (float) $item->discount_percent,
                    $validated['discount_amount'] ?? null,
                    $catalogProduct?->default_price
                );

                $payload = array_merge($payload, [
                    'list_unit_price' => $pricing['list_unit_price'],
                    'discount_percent' => $pricing['discount_percent'],
                    'discount_amount' => $pricing['discount_amount'],
                    'net_unit_price' => $pricing['net_unit_price'],
                    'unit_price' => $pricing['net_unit_price'],
                    'unit_cost' => $pricing['unit_cost'],
                    'margin_amount' => $pricing['margin_amount'],
                    'margin_percent' => $pricing['margin_percent'],
                    'min_margin_percent' => $pricing['min_margin_percent'],
                    'below_min_margin' => $pricing['below_min_margin'],
                ]);
            }

            $item->update($payload);

            if ($catalogProduct) {
                $this->syncRequiredDependencies($item->quotation, $catalogProduct, $item);
            }

            return $item->fresh(['quotation', 'product', 'catalogProduct', 'warehouse']);
        });
    }

    public function deleteItem(string $itemUid): void
    {
        $item = $this->getItemByUid($itemUid);

        if ($item->reserved_quantity > 0) {
            throw ValidationException::withMessages([
                'item' => ['No puedes eliminar un item con stock reservado activo'],
            ]);
        }

        $item->delete();
    }

    public function reserveItemStock(string $itemUid, array $data): array
    {
        $item = $this->getItemByUid($itemUid);
        $validated = Validator::make($data, [
            'quantity' => 'required|integer|min:1',
            'comment' => 'nullable|string',
        ])->validate();

        if (! $item->product_id || ! $item->warehouse_id) {
            throw ValidationException::withMessages([
                'item' => ['El item debe tener producto y bodega para reservar stock'],
            ]);
        }

        $remaining = $item->quantity - $item->reserved_quantity;

        if ((int) $validated['quantity'] > $remaining) {
            throw ValidationException::withMessages([
                'quantity' => ['La reserva excede la cantidad pendiente del item de cotizacion'],
            ]);
        }

        $result = $this->inventoryService->reserveStock([
            'product_uid' => $item->product_uid,
            'warehouse_uid' => $item->warehouse_uid,
            'quantity' => (int) $validated['quantity'],
            'source_type' => 'quotation_item',
            'source_uid' => $item->uid,
            'comment' => $validated['comment'] ?? null,
        ]);

        return [
            'quotation_uid' => $item->quotation_uid,
            'item' => $item->fresh(['quotation', 'product', 'warehouse']),
            'reservation' => $result['reservation'],
            'preview' => $result['preview'],
        ];
    }

    public function releaseItemReservation(string $itemUid, string $reservationUid): array
    {
        $item = $this->getItemByUid($itemUid);
        $reservation = InventoryReservation::query()
            ->where('uid', $reservationUid)
            ->where('source_type', 'quotation_item')
            ->where('source_uid', $item->uid)
            ->firstOrFail();

        $result = $this->inventoryService->releaseReservation($reservation->uid);

        return [
            'quotation_uid' => $item->quotation_uid,
            'item' => $item->fresh(['quotation', 'product', 'warehouse']),
            'reservation' => $result['reservation'],
            'preview' => $result['preview'],
        ];
    }

    private function validateQuotation(array $data, bool $partial = false): array
    {
        $validator = Validator::make($data, [
            'quote_number' => [$partial ? 'sometimes' : 'nullable', 'string', 'max:255'],
            'title' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'status' => 'sometimes|string|in:draft,sent,approved,rejected,cancelled,invoiced',
            'currency' => 'nullable|string|max:10',
            'exchange_rate' => 'sometimes|numeric|min:0.000001',
            'local_currency' => 'nullable|string|max:10',
            'valid_until' => 'nullable|date',
            'notes' => 'nullable|string',
            'price_book_uid' => 'nullable|uuid',
            'entity_type' => 'nullable|string',
            'entity_uid' => 'nullable|uuid',
            'items' => 'nullable|array',
            'items.*.uid' => 'nullable|uuid',
            'items.*.product_uid' => 'nullable|uuid',
            'items.*.catalog_product_uid' => 'nullable|uuid',
            'items.*.warehouse_uid' => 'nullable|uuid',
            'items.*.description' => 'required|string|max:255',
            'items.*.sku' => 'nullable|string|max:50',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.list_unit_price' => 'required|numeric|min:0',
            'items.*.discount_percent' => 'nullable|numeric|min:0|max:100',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $validated = $validator->validated();

        if (! empty($validated['entity_type']) xor ! empty($validated['entity_uid'])) {
            throw ValidationException::withMessages([
                'entity_uid' => ['Debes enviar entity_type y entity_uid juntos'],
            ]);
        }

        return $validated;
    }

    private function normalizeQuotationPayload(array $data): array
    {
        foreach (['uid', 'quote_number', 'owner_user_uid', 'created_by_user_uid', 'price_book_uid', 'entity_uid'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] === '') {
                unset($data[$field]);
            }
        }

        if (array_key_exists('items', $data) && is_array($data['items'])) {
            $data['items'] = array_map(function ($item) {
                if (! is_array($item)) {
                    return $item;
                }

                foreach (['uid', 'product_uid', 'catalog_product_uid', 'warehouse_uid'] as $field) {
                    if (array_key_exists($field, $item) && $item[$field] === '') {
                        unset($item[$field]);
                    }
                }

                return $item;
            }, $data['items']);
        }

        return $data;
    }

    private function mapQuotationIndexResult($result)
    {
        if (method_exists($result, 'through')) {
            return $result->through(fn (Quotation $quotation) => $this->serializeQuotationIndex($quotation));
        }

        return collect($result)
            ->map(fn (Quotation $quotation) => $this->serializeQuotationIndex($quotation))
            ->values();
    }

    private function serializeQuotationIndex(Quotation $quotation): array
    {
        $items = $quotation->items->map(fn (QuotationItem $item) => $this->serializeQuotationItemIndex($item))->values();
        $subtotal = $items->sum(fn (array $item) => $item['list_line_total']);
        $discountTotal = $items->sum(fn (array $item) => $item['discount_total']);
        $total = $items->sum(fn (array $item) => $item['line_total']);

        return [
            'uid' => $quotation->uid,
            'quote_number' => $quotation->quote_number,
            'title' => $quotation->title,
            'status' => $quotation->status,
            'currency' => $quotation->currency,
            'exchange_rate' => (float) $quotation->exchange_rate,
            'local_currency' => $quotation->local_currency,
            'valid_until' => $quotation->valid_until,
            'notes' => $quotation->notes,
            'owner_user_uid' => $this->resolveModelUid(\App\Models\User::class, $quotation->owner_user_id),
            'created_by_user_uid' => $this->resolveModelUid(\App\Models\User::class, $quotation->created_by_user_id),
            'price_book_uid' => $quotation->priceBook?->uid,
            'quoteable_type' => $quotation->quoteable_type,
            'quoteable_uid' => $this->resolveModelUid($quotation->quoteable_type, $quotation->quoteable_id),
            'opportunity_uid' => $quotation->quoteable_type === Opportunity::class
                ? $this->resolveModelUid(Opportunity::class, $quotation->quoteable_id)
                : null,
            'client_name' => $this->resolveQuoteableName($quotation),
            'subtotal' => round((float) $subtotal, 2),
            'discount_total' => round((float) $discountTotal, 2),
            'total' => round((float) $total, 2),
            'reserved_items_count' => 0,
            'reservation_indicator' => 'not_reserved',
            'items' => $items,
            'created_at' => $quotation->created_at,
            'updated_at' => $quotation->updated_at,
        ];
    }

    private function serializeQuotationItemIndex(QuotationItem $item): array
    {
        $listUnitPrice = (float) $item->list_unit_price;
        $discountAmount = (float) $item->discount_amount;
        $netUnitPrice = (float) $item->net_unit_price;
        $quantity = (int) $item->quantity;

        return [
            'uid' => $item->uid,
            'product_uid' => $item->product?->uid,
            'catalog_product_uid' => $item->catalogProduct?->uid,
            'warehouse_uid' => $item->warehouse?->uid,
            'sku' => $item->sku,
            'description' => $item->description,
            'quantity' => $quantity,
            'list_unit_price' => $listUnitPrice,
            'discount_percent' => (float) $item->discount_percent,
            'discount_amount' => $discountAmount,
            'net_unit_price' => $netUnitPrice,
            'unit_price' => (float) $item->unit_price,
            'unit_cost' => (float) $item->unit_cost,
            'margin_amount' => (float) $item->margin_amount,
            'margin_percent' => (float) $item->margin_percent,
            'min_margin_percent' => (float) $item->min_margin_percent,
            'below_min_margin' => (bool) $item->below_min_margin,
            'line_total' => round($netUnitPrice * $quantity, 2),
            'list_line_total' => round($listUnitPrice * $quantity, 2),
            'discount_total' => round($discountAmount * $quantity, 2),
        ];
    }

    private function resolveModelUid(?string $class, ?int $id): ?string
    {
        if (! $class || ! $id || ! is_subclass_of($class, \Illuminate\Database\Eloquent\Model::class)) {
            return null;
        }

        return $class::withoutGlobalScopes()->whereKey($id)->value('uid');
    }

    private function resolveQuoteableName(Quotation $quotation): ?string
    {
        if (! $quotation->quoteable_type || ! $quotation->quoteable_id || ! is_subclass_of($quotation->quoteable_type, \Illuminate\Database\Eloquent\Model::class)) {
            return $quotation->title ?? $quotation->quote_number;
        }

        $quoteable = $quotation->quoteable_type::withoutGlobalScopes()->whereKey($quotation->quoteable_id)->first();

        return $quoteable?->display_name
            ?? $quoteable?->name
            ?? $quotation->title
            ?? $quotation->quote_number;
    }

    private function validateItem(array $data, bool $partial = false): array
    {
        $validator = Validator::make($data, [
            'product_uid' => 'nullable|uuid',
            'catalog_product_uid' => 'nullable|uuid',
            'warehouse_uid' => 'nullable|uuid',
            'sku' => 'nullable|string|max:255',
            'description' => [$partial ? 'sometimes' : 'nullable', 'string', 'max:255'],
            'quantity' => [$partial ? 'sometimes' : 'required', 'integer', 'min:1'],
            'list_unit_price' => 'sometimes|numeric|min:0',
            'unit_price' => 'sometimes|numeric|min:0',
            'discount_percent' => 'sometimes|numeric|min:0|max:100',
            'discount_amount' => 'sometimes|numeric|min:0',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $validated = $validator->validated();

        if (! $partial && empty($validated['product_uid']) && empty($validated['catalog_product_uid'])) {
            throw ValidationException::withMessages([
                'catalog_product_uid' => ['Debes asociar un producto de inventario o un producto del catalogo'],
            ]);
        }

        if (! empty($validated['warehouse_uid']) && empty($validated['product_uid']) && empty($validated['catalog_product_uid'])) {
            throw ValidationException::withMessages([
                'product_uid' => ['Debes asociar un producto si vas a definir bodega'],
            ]);
        }

        return $validated;
    }

    private function syncQuotationItems(Quotation $quotation, array $items): void
    {
        $incomingUids = collect($items)
            ->pluck('uid')
            ->filter()
            ->values()
            ->all();

        $itemsToDelete = $quotation->items()
            ->when($incomingUids !== [], fn ($query) => $query->whereNotIn('uid', $incomingUids))
            ->get();

        foreach ($itemsToDelete as $item) {
            if ($item->reserved_quantity > 0) {
                throw ValidationException::withMessages([
                    'items' => ['No puedes eliminar items con stock reservado activo'],
                ]);
            }

            $item->delete();
        }

        foreach ($items as $itemData) {
            if (! empty($itemData['uid'])) {
                $item = $quotation->items()->where('uid', $itemData['uid'])->firstOr(function () {
                    throw ValidationException::withMessages([
                        'items' => ['Uno de los items enviados no pertenece a esta cotizacion'],
                    ]);
                });

                $this->updateQuotationItemFromBatch($quotation, $item, $itemData);
            } else {
                $this->createQuotationItemFromBatch($quotation, $itemData);
            }
        }
    }

    private function createQuotationItemFromBatch(Quotation $quotation, array $data): QuotationItem
    {
        $catalogProduct = $this->resolveCatalogProduct($data['catalog_product_uid'] ?? null)
            ?? $this->resolveCatalogProductBySku($data['sku'] ?? null);
        $product = $this->resolveProduct($data['product_uid'] ?? $catalogProduct?->inventoryProduct?->uid);
        $warehouse = $this->resolveWarehouse($data['warehouse_uid'] ?? null);
        $pricing = $this->buildPricingPayload(
            $quotation->priceBook,
            $product,
            (int) $data['quantity'],
            (float) $data['list_unit_price'],
            (float) ($data['discount_percent'] ?? 0),
            null
        );

        $item = QuotationItem::query()->create([
            'quotation_id' => $quotation->getKey(),
            'product_id' => $product?->getKey(),
            'catalog_product_id' => $catalogProduct?->getKey(),
            'warehouse_id' => $warehouse?->getKey(),
            'sku' => $data['sku'] ?? $catalogProduct?->sku ?? $product?->sku,
            'description' => $data['description'] ?? $catalogProduct?->name,
            'quantity' => $data['quantity'],
            'list_unit_price' => $pricing['list_unit_price'],
            'discount_percent' => $pricing['discount_percent'],
            'discount_amount' => $pricing['discount_amount'],
            'net_unit_price' => $pricing['net_unit_price'],
            'unit_price' => $pricing['net_unit_price'],
            'unit_cost' => $pricing['unit_cost'],
            'margin_amount' => $pricing['margin_amount'],
            'margin_percent' => $pricing['margin_percent'],
            'min_margin_percent' => $pricing['min_margin_percent'],
            'below_min_margin' => $pricing['below_min_margin'],
        ]);

        if ($catalogProduct) {
            $this->syncRequiredDependencies($quotation, $catalogProduct, $item);
        }

        return $item;
    }

    private function updateQuotationItemFromBatch(Quotation $quotation, QuotationItem $item, array $data): QuotationItem
    {
        if ($item->reserved_quantity > (int) $data['quantity']) {
            throw ValidationException::withMessages([
                'items' => ['La cantidad no puede ser menor que el stock ya reservado'],
            ]);
        }

        $catalogProduct = array_key_exists('catalog_product_uid', $data)
            ? $this->resolveCatalogProduct($data['catalog_product_uid'])
            : ($item->catalogProduct ?? $this->resolveCatalogProductBySku($data['sku'] ?? null));
        $product = array_key_exists('product_uid', $data)
            ? $this->resolveProduct($data['product_uid'])
            : ($catalogProduct?->inventoryProduct ?? $item->product);
        $warehouse = array_key_exists('warehouse_uid', $data)
            ? $this->resolveWarehouse($data['warehouse_uid'])
            : $item->warehouse;
        $pricing = $this->buildPricingPayload(
            $quotation->priceBook,
            $product,
            (int) $data['quantity'],
            (float) $data['list_unit_price'],
            (float) ($data['discount_percent'] ?? 0),
            null
        );

        $item->update([
            'product_id' => $product?->getKey(),
            'catalog_product_id' => $catalogProduct?->getKey(),
            'warehouse_id' => $warehouse?->getKey(),
            'sku' => $data['sku'] ?? $catalogProduct?->sku ?? $product?->sku,
            'description' => $data['description'] ?? $catalogProduct?->name,
            'quantity' => $data['quantity'],
            'list_unit_price' => $pricing['list_unit_price'],
            'discount_percent' => $pricing['discount_percent'],
            'discount_amount' => $pricing['discount_amount'],
            'net_unit_price' => $pricing['net_unit_price'],
            'unit_price' => $pricing['net_unit_price'],
            'unit_cost' => $pricing['unit_cost'],
            'margin_amount' => $pricing['margin_amount'],
            'margin_percent' => $pricing['margin_percent'],
            'min_margin_percent' => $pricing['min_margin_percent'],
            'below_min_margin' => $pricing['below_min_margin'],
        ]);

        if ($catalogProduct) {
            $this->syncRequiredDependencies($quotation, $catalogProduct, $item);
        }

        return $item;
    }

    private function resolveQuoteable(?string $type, ?string $uid)
    {
        if (! $type && ! $uid) {
            return null;
        }

        $entity = find_entity_by_uid($type, $uid);

        if (! $entity) {
            throw ValidationException::withMessages([
                'entity_uid' => ['La entidad comercial no existe o no es visible'],
            ]);
        }

        return $entity;
    }

    private function resolveProduct(?string $uid): ?InventoryProduct
    {
        if (! $uid) {
            return null;
        }

        return InventoryProduct::query()->where('uid', $uid)->firstOr(function () {
            throw ValidationException::withMessages([
                'product_uid' => ['El producto no existe o no pertenece a este tenant'],
            ]);
        });
    }

    private function resolveCatalogProduct(?string $uid): ?Product
    {
        if (! $uid) {
            return null;
        }

        try {
            return $this->productService->findByUid($uid);
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'catalog_product_uid' => ['El producto del catalogo no existe o no pertenece a este tenant'],
            ]);
        }
    }

    private function resolveCatalogProductBySku(?string $sku): ?Product
    {
        if (! $sku) {
            return null;
        }

        return Product::query()
            ->with('inventoryProduct')
            ->where('sku', $sku)
            ->where('status', 'active')
            ->first();
    }

    private function resolveWarehouse(?string $uid): ?Warehouse
    {
        if (! $uid) {
            return null;
        }

        return Warehouse::query()->where('uid', $uid)->firstOr(function () {
            throw ValidationException::withMessages([
                'warehouse_uid' => ['La bodega no existe o no pertenece a este tenant'],
            ]);
        });
    }

    private function getItemByUid(string $uid): QuotationItem
    {
        return QuotationItem::query()->with(['quotation', 'product', 'catalogProduct', 'warehouse'])->where('uid', $uid)->firstOrFail();
    }

    private function generateQuoteNumber(): string
    {
        $tenantId = auth()->user()?->tenant_id;
        $prefix = 'COT-'.now()->format('Y').'-';
        $lastNumber = Quotation::query()
            ->where('tenant_id', $tenantId)
            ->where('quote_number', 'like', $prefix.'%')
            ->lockForUpdate()
            ->orderByDesc('quote_number')
            ->value('quote_number');

        $nextSequence = 1;

        if ($lastNumber && preg_match('/^'.preg_quote($prefix, '/').'(\d+)$/', $lastNumber, $matches)) {
            $nextSequence = ((int) $matches[1]) + 1;
        }

        do {
            $quoteNumber = $prefix.str_pad((string) $nextSequence, 3, '0', STR_PAD_LEFT);
            $nextSequence++;
        } while (
            Quotation::query()
                ->where('tenant_id', $tenantId)
                ->where('quote_number', $quoteNumber)
                ->exists()
        );

        return $quoteNumber;
    }

    private function quotationByIdentifier(string $identifier)
    {
        $query = Quotation::query();

        if (Str::isUuid($identifier)) {
            return $query->where(function ($builder) use ($identifier) {
                $builder->where('uid', $identifier)
                    ->orWhere('quote_number', $identifier)
                    ->orWhere(function ($opportunityQuery) use ($identifier) {
                        $opportunityQuery
                            ->where('quoteable_type', Opportunity::class)
                            ->whereIn('quoteable_id', Opportunity::query()->where('uid', $identifier)->select('id'));
                    });
            });
        }

        return $query->where('quote_number', $identifier);
    }

    private function resolvePriceBook(?string $uid): ?PriceBook
    {
        if (! $uid) {
            return null;
        }

        return $this->priceBookService->resolveActivePriceBook($uid);
    }

    private function buildPricingPayload(
        ?PriceBook $priceBook,
        ?InventoryProduct $product,
        int $quantity,
        ?float $manualUnitPrice,
        float $discountPercent,
        ?float $manualDiscountAmount,
        ?float $fallbackUnitPrice = null
    ): array {
        $listPrice = $manualUnitPrice ?? $fallbackUnitPrice ?? 0;
        $minMarginPercent = 0;
        $unitCost = (float) ($product?->cost_price ?? 0);

        if ($priceBook && $product) {
            $priceBookItem = $this->pricingService->resolveProductPrice($priceBook, $product);

            $listPrice = $manualUnitPrice ?? (float) $priceBookItem->unit_price;
            $minMarginPercent = (float) $priceBookItem->min_margin_percent;
        }

        $discountAmount = $manualDiscountAmount ?? round($listPrice * ($discountPercent / 100), 2);
        $netUnitPrice = max(0, round($listPrice - $discountAmount, 2));
        $marginAmount = round($netUnitPrice - $unitCost, 2);
        $marginPercent = $netUnitPrice > 0 ? round(($marginAmount / $netUnitPrice) * 100, 2) : 0;

        return [
            'list_unit_price' => $listPrice,
            'discount_percent' => $discountPercent,
            'discount_amount' => $discountAmount,
            'net_unit_price' => $netUnitPrice,
            'unit_cost' => $unitCost,
            'margin_amount' => $marginAmount,
            'margin_percent' => $marginPercent,
            'min_margin_percent' => $minMarginPercent,
            'below_min_margin' => $marginPercent < $minMarginPercent,
            'quantity' => $quantity,
        ];
    }

    private function reservePendingQuotationStock(Quotation $quotation): void
    {
        foreach ($quotation->items()->with(['product', 'catalogProduct', 'warehouse'])->get() as $item) {
            $this->hydrateItemProductLinks($item);

            if (! $this->itemRequiresStockReservation($item)) {
                continue;
            }

            $product = $this->resolveInventoryProductForReservation($item);

            $pendingQuantity = max(0, (int) $item->quantity - (int) $item->reserved_quantity);

            if ($pendingQuantity === 0) {
                continue;
            }

            $this->reserveItemAcrossWarehouses($item, $product, $pendingQuantity);
        }
    }

    private function itemRequiresStockReservation(QuotationItem $item): bool
    {
        if ($item->catalogProduct?->type === 'service') {
            return false;
        }

        return true;
    }

    private function resolveInventoryProductForReservation(QuotationItem $item): InventoryProduct
    {
        $product = $item->product ?? $item->catalogProduct?->inventoryProduct;

        if (! $product) {
            throw ValidationException::withMessages([
                'quotation' => [
                    'El item "'.$item->description.'" no tiene producto de inventario asociado al catalogo comercial',
                ],
            ]);
        }

        if (! $item->product_id) {
            $item->forceFill(['product_id' => $product->getKey()])->save();
            $item->setRelation('product', $product);
        }

        return $product;
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
                'quotation' => [
                    'Stock insuficiente para '.$product->name.': disponible '.$available.', requerido '.$quantity,
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
                'comment' => 'Reserva automatica por aprobacion de cotizacion',
            ]);

            $firstWarehouseId ??= $stock->warehouse_id;
            $remaining -= $reservedQuantity;
        }

        if (! $item->warehouse_id && $firstWarehouseId) {
            $item->forceFill(['warehouse_id' => $firstWarehouseId])->save();
        }
    }

    private function syncRequiredDependencies(Quotation $quotation, Product $catalogProduct, QuotationItem $sourceItem): void
    {
        $requiredProducts = $this->dependencyService->getRequiredDependencies($catalogProduct);

        foreach ($requiredProducts as $requiredProduct) {
            $alreadyPresent = $quotation->items()
                ->where('catalog_product_id', $requiredProduct->getKey())
                ->exists();

            if ($alreadyPresent) {
                continue;
            }

            $linkedInventoryProduct = $requiredProduct->inventoryProduct;
            $pricing = $this->buildPricingPayload(
                $quotation->priceBook,
                $linkedInventoryProduct,
                (int) $sourceItem->quantity,
                null,
                (float) ($requiredProduct->default_discount_percent ?? 0),
                null,
                $requiredProduct->default_price
            );

            QuotationItem::query()->create([
                'quotation_id' => $quotation->getKey(),
                'product_id' => $linkedInventoryProduct?->getKey(),
                'catalog_product_id' => $requiredProduct->getKey(),
                'warehouse_id' => $sourceItem->warehouse_id,
                'sku' => $requiredProduct->sku,
                'description' => $requiredProduct->name,
                'quantity' => $sourceItem->quantity,
                'list_unit_price' => $pricing['list_unit_price'],
                'discount_percent' => 0,
                'discount_amount' => 0,
                'net_unit_price' => $pricing['net_unit_price'],
                'unit_price' => $pricing['net_unit_price'],
                'unit_cost' => $pricing['unit_cost'],
                'margin_amount' => $pricing['margin_amount'],
                'margin_percent' => $pricing['margin_percent'],
                'min_margin_percent' => $pricing['min_margin_percent'],
                'below_min_margin' => $pricing['below_min_margin'],
            ]);
        }
    }

    private function validateQuotationDependencies(Quotation $quotation): void
    {
        $catalogProducts = $quotation->items()
            ->with('catalogProduct')
            ->get()
            ->pluck('catalogProduct')
            ->filter()
            ->values();

        if ($catalogProducts->isEmpty()) {
            return;
        }

        $validation = $this->dependencyService->validateDependencies($catalogProducts);

        if (! empty($validation['required_missing']) || ! empty($validation['incompatibilities'])) {
            throw ValidationException::withMessages([
                'catalog_dependencies' => [
                    'La cotizacion tiene dependencias invalidas o productos incompatibles',
                ],
                'required_missing' => $validation['required_missing'],
                'incompatibilities' => $validation['incompatibilities'],
            ]);
        }
    }
}
