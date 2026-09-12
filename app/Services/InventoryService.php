<?php

namespace App\Services;

use App\Models\InventoryCategory;
use App\Models\InventoryMovement;
use App\Models\InventoryProduct;
use App\Models\InventoryReservation;
use App\Models\InventoryStock;
use App\Models\Warehouse;
use App\Support\ApiIndex;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class InventoryService
{
    public function __construct(private readonly ExportService $exportService)
    {
    }

    public function listCategories(array $filters = [])
    {
        $validated = Validator::make($filters, [
            'search' => 'nullable|string|max:255',
        ])->validate();

        return ApiIndex::paginateOrGet(
            InventoryCategory::query()
                ->when(!empty($validated['search']), function ($query) use ($validated) {
                    $search = '%'.mb_strtolower($validated['search']).'%';

                    $query->whereRaw('LOWER(name) LIKE ?', [$search]);
                })
                ->orderBy('name'),
            $filters,
            'inventory_categories_page'
        );
    }

    public function createCategory(array $data): InventoryCategory
    {
        $validated = Validator::make($data, [
            'name' => 'required|string|max:255',
            'key' => 'nullable|string|max:255',
            'description' => 'nullable|string',
        ])->validate();

        $validated['key'] = $this->uniqueInventoryCategoryKey($validated['key'] ?? $validated['name']);

        return InventoryCategory::query()->create($validated);
    }

    public function updateCategory(string $uid, array $data): InventoryCategory
    {
        $category = $this->getCategoryByUid($uid);
        $validated = Validator::make($data, [
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
        ])->validate();

        $category->update($validated);

        return $category->fresh();
    }

    public function deleteCategory(string $uid): void
    {
        $this->getCategoryByUid($uid)->delete();
    }

    public function listProducts(array $filters = [])
    {
        $validated = Validator::make($filters, [
            'search' => 'nullable|string|max:255',
            'is_active' => 'nullable|string|in:true,false,1,0',
        ])->validate();

        return ApiIndex::paginateOrGet(
            InventoryProduct::query()
                ->with(['category', 'stocks.warehouse'])
                ->when(!empty($validated['search']), fn ($query) => $this->applyProductSearch($query, $validated['search']))
                ->when(array_key_exists('is_active', $validated), fn ($query) => $query->where('is_active', filter_var($validated['is_active'], FILTER_VALIDATE_BOOLEAN)))
                ->orderBy('name'),
            $this->paginationFilters($filters),
            'inventory_products_page'
        );
    }

    public function createProduct(array $data): InventoryProduct
    {
        $validated = $this->validateProduct($data);

        return DB::transaction(function () use ($validated) {
            $product = InventoryProduct::query()->create([
                'category_id' => $this->resolveCategoryId($validated['category_uid'] ?? null),
                'sku' => $validated['sku'],
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'cost_price' => $validated['unit_cost'] ?? $validated['cost_price'] ?? 0,
                'sale_price' => $validated['sale_price'] ?? null,
                'discount_percent' => $validated['discount_percent'] ?? 0,
                'reorder_point' => $validated['reorder_point'] ?? 0,
                'is_active' => $validated['is_active'] ?? true,
            ]);

            $this->syncWarehouseStocks($product, $validated['warehouse_stocks'] ?? []);

            return $product->fresh(['category', 'stocks.warehouse']);
        });
    }

    public function updateProduct(string $uid, array $data): InventoryProduct
    {
        $product = $this->getProductByUid($uid);
        $validated = $this->validateProduct($data, true);

        return DB::transaction(function () use ($product, $validated) {
            $payload = [];

            if (array_key_exists('category_uid', $validated)) {
                $payload['category_id'] = $this->resolveCategoryId($validated['category_uid']);
            }

            foreach (['sku', 'name', 'description', 'reorder_point', 'is_active'] as $field) {
                if (array_key_exists($field, $validated)) {
                    $payload[$field] = $validated[$field];
                }
            }

            if (array_key_exists('unit_cost', $validated) || array_key_exists('cost_price', $validated)) {
                $payload['cost_price'] = $validated['unit_cost'] ?? $validated['cost_price'];
            }

            foreach (['sale_price', 'discount_percent'] as $field) {
                if (array_key_exists($field, $validated)) {
                    $payload[$field] = $validated[$field];
                }
            }

            if ($payload !== []) {
                $product->update($payload);
            }

            if (array_key_exists('warehouse_stocks', $validated)) {
                $this->syncWarehouseStocks($product, $validated['warehouse_stocks'], true);
            }

            return $product->fresh(['category', 'stocks.warehouse']);
        });
    }

    public function deleteProduct(string $uid): void
    {
        $this->getProductByUid($uid)->delete();
    }

    public function exportProducts(array $payload)
    {
        $validated = $this->validateExportPayload($payload);
        $filters = $validated['filters'] ?? [];

        $rows = InventoryProduct::query()
            ->with(['category', 'stocks.warehouse'])
            ->when(!empty($filters['search']), function ($query) use ($filters) {
                $search = $filters['search'];
                $query->where(function ($builder) use ($search) {
                    $builder->where('name', 'like', '%' . $search . '%')
                        ->orWhere('sku', 'like', '%' . $search . '%');
                });
            })
            ->when(!empty($filters['category_uid']), fn ($query) => $query->where('category_id', $this->resolveCategoryId($filters['category_uid']) ?: 0))
            ->when(array_key_exists('is_active', $filters), fn ($query) => $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN)))
            ->when(!empty($filters['warehouse_uid']), function ($query) use ($filters) {
                $query->whereHas('stocks.warehouse', fn ($warehouseQuery) => $warehouseQuery->where('uid', $filters['warehouse_uid']));
            })
            ->orderBy('name')
            ->limit(50000)
            ->get()
            ->map(fn (InventoryProduct $product) => [
                'uid' => $product->uid,
                'sku' => $product->sku,
                'name' => $product->name,
                'category' => $product->category?->name,
                'cost_price' => (float) $product->cost_price,
                'sale_price' => (float) $product->sale_price,
                'discount_percent' => (float) $product->discount_percent,
                'reorder_point' => (int) $product->reorder_point,
                'is_active' => (bool) $product->is_active,
                'physical_stock' => (int) $product->stocks->sum('physical_stock'),
                'reserved_stock' => (int) $product->stocks->sum('reserved_stock'),
            ]);

        return $this->exportService->file('inventario-productos', $rows, [
            'format' => $validated['format'],
            'fields' => $validated['fields'] ?? [],
            'filters' => $filters,
        ]);
    }

    public function exportStock(array $payload)
    {
        $validated = $this->validateExportPayload($payload);
        $filters = $validated['filters'] ?? [];
        $masterFilters = [
            'category_uid' => $filters['category_uid'] ?? null,
            'warehouse_uid' => $filters['warehouse_uid'] ?? null,
            'stock_state' => $filters['stock_status'] ?? $filters['stock_state'] ?? null,
        ];

        $rows = collect($this->master(array_filter($masterFilters, fn ($value) => $value !== null && $value !== ''))['data'])
            ->when(!empty($filters['search']), function (Collection $rows) use ($filters) {
                $search = strtolower((string) $filters['search']);

                return $rows->filter(fn (array $row) => str_contains(strtolower($row['name'] ?? ''), $search)
                    || str_contains(strtolower($row['sku'] ?? ''), $search));
            })
            ->map(fn (array $row) => [
                'uid' => $row['uid'],
                'sku' => $row['sku'],
                'name' => $row['name'],
                'category' => $row['category_name'] ?? null,
                'stock_state' => $row['stock_state'],
                'physical_stock' => (int) $row['stock_physical_total'],
                'reserved_stock' => (int) $row['stock_reserved_total'],
                'available_stock' => (int) $row['stock_available_total'],
                'reorder_point' => (int) $row['reorder_point'],
            ])
            ->values();

        return $this->exportService->file('inventario-stock', $rows, [
            'format' => $validated['format'],
            'fields' => $validated['fields'] ?? [],
            'filters' => $filters,
        ]);
    }

    public function listWarehouses(array $filters = [])
    {
        $validated = Validator::make($filters, [
            'search' => 'nullable|string|max:255',
            'has_stock' => 'nullable|string|in:true,false,1,0',
        ])->validate();

        $baseQuery = Warehouse::query()
            ->when(array_key_exists('has_stock', $validated), function ($query) use ($validated) {
                $hasStock = filter_var($validated['has_stock'], FILTER_VALIDATE_BOOLEAN);

                if ($hasStock) {
                    $query->whereHas('stocks', fn ($stockQuery) => $stockQuery->where('physical_stock', '>', 0));
                } else {
                    $query->whereDoesntHave('stocks', fn ($stockQuery) => $stockQuery->where('physical_stock', '>', 0));
                }
            })
            ->when(!empty($validated['search']), function ($query) use ($validated) {
                $search = '%' . mb_strtolower($validated['search']) . '%';

                $query->where(function ($builder) use ($search) {
                    $builder->whereRaw('LOWER(name) LIKE ?', [$search])
                        ->orWhereRaw('LOWER(code) LIKE ?', [$search]);
                });
            })
            ->orderBy('name');

        $summaryRows = (clone $baseQuery)->select('warehouses.*')->get();
        $warehouseIds = $summaryRows->pluck('id')->all();
        $stockSummary = $warehouseIds === []
            ? collect()
            : InventoryStock::query()
                ->with('product')
                ->whereIn('warehouse_id', $warehouseIds)
                ->get();

        $query = (clone $baseQuery)->with(['stocks.product']);
        $result = ApiIndex::paginateOrGet($query, $this->paginationFilters($filters), 'warehouses_page');
        $warehouses = $result instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator
            ? collect($result->items())
            : $result;

        $data = $warehouses->map(fn (Warehouse $warehouse) => $this->warehouseRow($warehouse))->values();

        if ($result instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator) {
            $result->setCollection($data);
        }

        $totalPhysicalStock = (int) $stockSummary->sum('physical_stock');
        $totalAvailableStock = (int) $stockSummary->sum(fn (InventoryStock $stock) => $stock->available_stock);
        $totalStockValue = round((float) $stockSummary->sum(fn (InventoryStock $stock) => $stock->physical_stock * (float) ($stock->product?->cost_price ?? 0)), 2);

        return [
            'data' => $result,
            'summary' => [
                'total_warehouses' => $summaryRows->count(),
                'active_warehouses' => $summaryRows->where('is_active', true)->count(),
                'total_physical_stock' => $totalPhysicalStock,
                'total_available_stock' => $totalAvailableStock,
                'total_stock_value' => $totalStockValue,
                'stock_physical_total' => $totalPhysicalStock,
                'stock_available_total' => $totalAvailableStock,
                'stock_value_total' => $totalStockValue,
            ],
        ];
    }

    public function createWarehouse(array $data): Warehouse
    {
        $validated = Validator::make($data, [
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:255',
            'location' => 'nullable|string|max:255',
            'is_active' => 'sometimes|boolean',
        ])->validate();

        return Warehouse::query()->create($validated);
    }

    public function updateWarehouse(string $uid, array $data): Warehouse
    {
        $warehouse = $this->getWarehouseByUid($uid);
        $validated = Validator::make($data, [
            'name' => 'sometimes|string|max:255',
            'code' => 'sometimes|string|max:255',
            'location' => 'nullable|string|max:255',
            'is_active' => 'sometimes|boolean',
        ])->validate();

        $warehouse->update($validated);

        return $warehouse->fresh();
    }

    public function deleteWarehouse(string $uid): void
    {
        $warehouse = $this->getWarehouseByUid($uid);

        if ($warehouse->stocks()->where('physical_stock', '>', 0)->exists()) {
            throw ValidationException::withMessages([
                'warehouse' => ['No puedes eliminar una bodega con stock fisico'],
            ]);
        }

        $warehouse->delete();
    }

    public function master(array $filters): array
    {
        $validated = Validator::make($filters, [
            'category_uid' => 'nullable|uuid',
            'warehouse_uid' => 'nullable|uuid',
            'stock_state' => 'nullable|string|in:normal,low,out',
            'search' => 'nullable|string|max:255',
            'is_active' => 'nullable|string|in:true,false,1,0',
        ])->validate();

        $warehouse = !empty($validated['warehouse_uid'])
            ? $this->getWarehouseByUid($validated['warehouse_uid'])
            : null;

        $products = InventoryProduct::query()
            ->with(['category', 'stocks.warehouse'])
            ->when(!empty($validated['search']), fn ($query) => $this->applyProductSearch($query, $validated['search']))
            ->when(array_key_exists('is_active', $validated), fn ($query) => $query->where('is_active', filter_var($validated['is_active'], FILTER_VALIDATE_BOOLEAN)))
            ->when(!empty($validated['category_uid']), function ($query) use ($validated) {
                $query->where('category_id', $this->resolveCategoryId($validated['category_uid']) ?: 0);
            })
            ->orderBy('name')
            ->get();

        $rows = $products
            ->map(fn (InventoryProduct $product) => $this->masterRow($product, $warehouse))
            ->when(!empty($validated['stock_state']), fn (Collection $collection) => $collection->where('stock_state', $validated['stock_state']))
            ->values();

        return [
            'filters' => [
                'category_uid' => $validated['category_uid'] ?? null,
                'warehouse_uid' => $validated['warehouse_uid'] ?? null,
                'stock_state' => $validated['stock_state'] ?? null,
                'search' => $validated['search'] ?? null,
                'is_active' => array_key_exists('is_active', $validated)
                    ? filter_var($validated['is_active'], FILTER_VALIDATE_BOOLEAN)
                    : null,
            ],
            'data' => $rows,
            'summary' => [
                'products' => $rows->count(),
                'active_products' => $rows->where('is_active', true)->count(),
                'out_of_stock_count' => $rows->where('stock_state', 'out')->count(),
                'total_physical_stock' => (int) $rows->sum('stock_physical_total'),
                'total_reserved_stock' => (int) $rows->sum('stock_reserved_total'),
                'total_available_stock' => (int) $rows->sum('stock_available_total'),
            ],
        ];
    }

    public function warehouseStocks(string $warehouseUid): array
    {
        $warehouse = $this->getWarehouseByUid($warehouseUid);

        $products = InventoryProduct::query()
            ->with(['category', 'stocks' => fn ($query) => $query->where('warehouse_id', $warehouse->getKey())->with('warehouse')])
            ->orderBy('name')
            ->get();

        return [
            'warehouse' => $warehouse,
            'data' => $products->map(fn (InventoryProduct $product) => $this->masterRow($product, $warehouse))->values(),
        ];
    }

    public function stockEntryOptions(array $filters): array
    {
        $validated = Validator::make($filters, [
            'warehouse_uid' => 'nullable|uuid',
            'search' => 'nullable|string|max:255',
            'is_active' => 'nullable|string|in:true,false,1,0',
            'limit' => 'nullable|integer|min:1|max:1000',
        ])->validate();

        $warehouse = !empty($validated['warehouse_uid'])
            ? $this->getWarehouseByUid($validated['warehouse_uid'])
            : null;

        $limit = (int) ($validated['limit'] ?? 500);
        $isActive = array_key_exists('is_active', $validated)
            ? filter_var($validated['is_active'], FILTER_VALIDATE_BOOLEAN)
            : true;

        $warehouses = Warehouse::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'uid', 'name', 'code', 'location'])
            ->map(fn (Warehouse $warehouse) => [
                'uid' => $warehouse->uid,
                'name' => $warehouse->name,
                'code' => $warehouse->code,
                'location' => $warehouse->location,
            ])
            ->values();

        $products = InventoryProduct::query()
            ->with([
                'category',
                'stocks' => fn ($query) => $query
                    ->when($warehouse, fn ($stockQuery) => $stockQuery->where('warehouse_id', $warehouse->getKey()))
                    ->with('warehouse'),
            ])
            ->where('is_active', $isActive)
            ->when(!empty($validated['search']), fn ($query) => $this->applyProductSearch($query, $validated['search']))
            ->orderBy('name')
            ->limit($limit)
            ->get()
            ->map(fn (InventoryProduct $product) => [
                'uid' => $product->uid,
                'sku' => $product->sku,
                'name' => $product->name,
                'category_uid' => $product->category?->uid,
                'category_name' => $product->category?->name,
                'unit_cost' => $product->unit_cost,
                'sale_price' => $product->sale_price,
                'is_active' => (bool) $product->is_active,
                'stocks' => $product->stocks
                    ->map(fn (InventoryStock $stock) => [
                        'warehouse_uid' => $stock->warehouse?->uid,
                        'warehouse_name' => $stock->warehouse?->name,
                        'warehouse_code' => $stock->warehouse?->code,
                        'physical_stock' => (int) $stock->physical_stock,
                        'reserved_stock' => (int) $stock->reserved_stock,
                        'available_stock' => (int) $stock->available_stock,
                    ])
                    ->values(),
            ])
            ->values();

        return [
            'filters' => [
                'warehouse_uid' => $warehouse?->uid,
                'search' => $validated['search'] ?? null,
                'is_active' => $isActive,
                'limit' => $limit,
            ],
            'warehouses' => $warehouses,
            'products' => $products,
            'summary' => [
                'warehouses' => $warehouses->count(),
                'products' => $products->count(),
            ],
        ];
    }

    public function availability(array $filters): array
    {
        $validated = Validator::make($filters, [
            'product_uid' => 'nullable|uuid',
            'warehouse_uid' => 'nullable|uuid',
        ])->validate();

        $product = !empty($validated['product_uid']) ? $this->getProductByUid($validated['product_uid']) : null;
        $warehouse = !empty($validated['warehouse_uid']) ? $this->getWarehouseByUid($validated['warehouse_uid']) : null;

        $stocks = InventoryStock::query()
            ->with(['product', 'warehouse'])
            ->when($product, fn ($query) => $query->where('product_id', $product->getKey()))
            ->when($warehouse, fn ($query) => $query->where('warehouse_id', $warehouse->getKey()))
            ->get();

        return [
            'filters' => [
                'product_uid' => $product?->uid,
                'warehouse_uid' => $warehouse?->uid,
            ],
            'data' => $stocks,
            'summary' => [
                'physical_stock' => (int) $stocks->sum('physical_stock'),
                'reserved_stock' => (int) $stocks->sum('reserved_stock'),
                'available_stock' => (int) $stocks->sum(fn (InventoryStock $stock) => $stock->available_stock),
            ],
        ];
    }

    public function adjustStock(array $data): array
    {
        $validated = Validator::make($data, [
            'product_uid' => 'required|uuid',
            'warehouse_uid' => 'required|uuid',
            'operation' => 'required|string|in:in,out,set',
            'quantity' => 'required|integer|min:0',
            'comment' => 'nullable|string',
        ])->validate();

        return DB::transaction(function () use ($validated) {
            $product = $this->getProductByUid($validated['product_uid']);
            $warehouse = $this->getWarehouseByUid($validated['warehouse_uid']);
            $stock = $this->getOrCreateStock($product, $warehouse);
            $previousPhysical = (int) $stock->physical_stock;

            $projectedPhysical = match ($validated['operation']) {
                'in' => $previousPhysical + (int) $validated['quantity'],
                'out' => $previousPhysical - (int) $validated['quantity'],
                default => (int) $validated['quantity'],
            };

            if ($projectedPhysical < 0) {
                throw ValidationException::withMessages([
                    'quantity' => ['El stock fisico no puede quedar negativo'],
                ]);
            }

            if ($projectedPhysical < (int) $stock->reserved_stock) {
                throw ValidationException::withMessages([
                    'quantity' => ['El stock fisico no puede quedar por debajo del stock reservado'],
                ]);
            }

            $stock->update(['physical_stock' => $projectedPhysical]);

            $movement = $this->recordMovement([
                'product_id' => $product->getKey(),
                'from_warehouse_id' => $validated['operation'] === 'out' ? $warehouse->getKey() : null,
                'to_warehouse_id' => $validated['operation'] === 'in' || $validated['operation'] === 'set' ? $warehouse->getKey() : null,
                'type' => match ($validated['operation']) {
                    'in' => 'adjustment_in',
                    'out' => 'adjustment_out',
                    default => 'set_balance',
                },
                'quantity' => abs($projectedPhysical - $previousPhysical),
                'comment' => $validated['comment'] ?? null,
                'meta' => [
                    'previous_physical_stock' => $previousPhysical,
                    'projected_physical_stock' => $projectedPhysical,
                    'reserved_stock' => (int) $stock->reserved_stock,
                ],
            ]);

            return [
                'movement' => $movement,
                'stock' => $stock->fresh(['product', 'warehouse']),
                'preview' => [
                    'physical_stock' => $previousPhysical,
                    'reserved_stock' => (int) $stock->reserved_stock,
                    'available_stock' => max(0, $previousPhysical - (int) $stock->reserved_stock),
                    'projected_physical_stock' => $projectedPhysical,
                    'projected_available_stock' => max(0, $projectedPhysical - (int) $stock->reserved_stock),
                ],
            ];
        });
    }

    public function adjustStockBulk(array $data): array
    {
        $validated = Validator::make($data, [
            'warehouse_uid' => 'required|uuid',
            'comment' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_uid' => 'required|uuid',
            'items.*.quantity' => 'required|integer|min:1',
        ])->validate();

        return DB::transaction(function () use ($validated) {
            $movements = [];
            $stocks = [];

            foreach ($validated['items'] as $item) {
                $result = $this->adjustStock([
                    'product_uid' => $item['product_uid'],
                    'warehouse_uid' => $validated['warehouse_uid'],
                    'operation' => 'in',
                    'quantity' => $item['quantity'],
                    'comment' => $validated['comment'] ?? null,
                ]);

                $movements[] = $result['movement'];
                $stocks[] = $result['stock'];
            }

            return [
                'movements' => $movements,
                'stocks' => $stocks,
                'summary' => [
                    'warehouse_uid' => $validated['warehouse_uid'],
                    'items' => count($validated['items']),
                    'total_quantity' => (int) collect($validated['items'])->sum('quantity'),
                ],
            ];
        });
    }

    public function reserveStock(array $data): array
    {
        $validated = Validator::make($data, [
            'product_uid' => 'required|uuid',
            'warehouse_uid' => 'required|uuid',
            'quantity' => 'required|integer|min:1',
            'source_type' => 'required|string|max:255',
            'source_uid' => 'required|string|max:255',
            'comment' => 'nullable|string',
        ])->validate();

        return DB::transaction(function () use ($validated) {
            $product = $this->getProductByUid($validated['product_uid']);
            $warehouse = $this->getWarehouseByUid($validated['warehouse_uid']);
            $stock = $this->getOrCreateStock($product, $warehouse);
            $preview = $this->reservationPreview($stock, (int) $validated['quantity']);

            if ($preview['exceeds_available']) {
                throw ValidationException::withMessages([
                    'quantity' => ['La reserva excede el stock disponible'],
                ]);
            }

            $reservation = InventoryReservation::query()
                ->where('product_id', $product->getKey())
                ->where('warehouse_id', $warehouse->getKey())
                ->where('source_type', $validated['source_type'])
                ->where('source_uid', $validated['source_uid'])
                ->where('status', 'active')
                ->first();

            if ($reservation) {
                $reservation->update([
                    'quantity' => (int) $reservation->quantity + (int) $validated['quantity'],
                    'comment' => $validated['comment'] ?? $reservation->comment,
                    'meta' => array_merge($reservation->meta ?? [], [
                        'preview' => $preview,
                        'controlled_merge' => true,
                    ]),
                ]);
            } else {
                $reservation = InventoryReservation::query()->create([
                    'product_id' => $product->getKey(),
                    'warehouse_id' => $warehouse->getKey(),
                    'reserved_by_user_id' => auth()->id(),
                    'source_type' => $validated['source_type'],
                    'source_uid' => $validated['source_uid'],
                    'quantity' => (int) $validated['quantity'],
                    'comment' => $validated['comment'] ?? null,
                    'status' => 'active',
                    'meta' => [
                        'preview' => $preview,
                    ],
                ]);
            }

            $stock->increment('reserved_stock', (int) $validated['quantity']);

            $movement = $this->recordMovement([
                'product_id' => $product->getKey(),
                'from_warehouse_id' => $warehouse->getKey(),
                'type' => 'reservation',
                'quantity' => (int) $validated['quantity'],
                'comment' => $validated['comment'] ?? null,
                'reference_type' => $validated['source_type'],
                'reference_uid' => $validated['source_uid'],
                'meta' => $preview,
            ]);

            return [
                'reservation' => $reservation->fresh(['product', 'warehouse', 'reservedBy']),
                'movement' => $movement,
                'preview' => $this->reservationPreview($stock->fresh(), (int) $validated['quantity']),
            ];
        });
    }

    public function releaseReservation(string $uid): array
    {
        return DB::transaction(function () use ($uid) {
            $reservation = InventoryReservation::query()->with(['product', 'warehouse', 'reservedBy'])->where('uid', $uid)->firstOrFail();

            if ($reservation->status !== 'active') {
                throw ValidationException::withMessages([
                    'reservation' => ['La reserva ya no esta activa'],
                ]);
            }

            $stock = InventoryStock::query()
                ->where('product_id', $reservation->product_id)
                ->where('warehouse_id', $reservation->warehouse_id)
                ->firstOrFail();

            $stock->update([
                'reserved_stock' => max(0, (int) $stock->reserved_stock - (int) $reservation->quantity),
            ]);

            $reservation->update([
                'status' => 'released',
                'released_at' => now(),
            ]);

            $movement = $this->recordMovement([
                'product_id' => $reservation->product_id,
                'to_warehouse_id' => $reservation->warehouse_id,
                'type' => 'reservation_release',
                'quantity' => (int) $reservation->quantity,
                'comment' => $reservation->comment,
                'reference_type' => $reservation->source_type,
                'reference_uid' => $reservation->source_uid,
            ]);

            return [
                'reservation' => $reservation->fresh(['product', 'warehouse', 'reservedBy']),
                'movement' => $movement,
                'preview' => $this->reservationPreview($stock->fresh(), 0),
            ];
        });
    }

    public function consumeReservation(string $uid, array $data = []): array
    {
        $validated = Validator::make($data, [
            'reference_type' => 'nullable|string|max:255',
            'reference_uid' => 'nullable|string|max:255',
            'comment' => 'nullable|string',
        ])->validate();

        return DB::transaction(function () use ($uid, $validated) {
            $reservation = InventoryReservation::query()->with(['product', 'warehouse', 'reservedBy'])->where('uid', $uid)->firstOrFail();

            if ($reservation->status !== 'active') {
                throw ValidationException::withMessages([
                    'reservation' => ['La reserva ya no esta activa'],
                ]);
            }

            $stock = InventoryStock::query()
                ->where('product_id', $reservation->product_id)
                ->where('warehouse_id', $reservation->warehouse_id)
                ->firstOrFail();

            $physical = (int) $stock->physical_stock;
            $reserved = (int) $stock->reserved_stock;
            $quantity = (int) $reservation->quantity;

            if ($physical < $quantity) {
                throw ValidationException::withMessages([
                    'reservation' => ['No hay stock fisico suficiente para consumir la reserva'],
                ]);
            }

            if ($reserved < $quantity) {
                throw ValidationException::withMessages([
                    'reservation' => ['El stock reservado es inconsistente para consumir la reserva'],
                ]);
            }

            $stock->update([
                'physical_stock' => $physical - $quantity,
                'reserved_stock' => $reserved - $quantity,
            ]);

            $reservation->update([
                'status' => 'consumed',
                'consumed_at' => now(),
                'comment' => $validated['comment'] ?? $reservation->comment,
            ]);

            $movement = $this->recordMovement([
                'product_id' => $reservation->product_id,
                'from_warehouse_id' => $reservation->warehouse_id,
                'type' => 'reservation_consume',
                'quantity' => $quantity,
                'comment' => $validated['comment'] ?? $reservation->comment,
                'reference_type' => $validated['reference_type'] ?? $reservation->source_type,
                'reference_uid' => $validated['reference_uid'] ?? $reservation->source_uid,
                'meta' => [
                    'reservation_uid' => $reservation->uid,
                    'stock_actual' => $physical,
                    'stock_reservado_actual' => $reserved,
                    'projected_physical_stock' => $physical - $quantity,
                    'projected_reserved_stock' => $reserved - $quantity,
                ],
            ]);

            return [
                'reservation' => $reservation->fresh(['product', 'warehouse', 'reservedBy']),
                'movement' => $movement,
                'preview' => [
                    'stock_actual' => $physical,
                    'stock_reservado_actual' => $reserved,
                    'stock_disponible' => max(0, $physical - $reserved),
                    'projected_physical_stock' => $physical - $quantity,
                    'projected_reserved_stock' => $reserved - $quantity,
                    'projected_available_stock' => max(0, ($physical - $quantity) - ($reserved - $quantity)),
                ],
            ];
        });
    }

    public function reservationsBySource(string $sourceType, string $sourceUid): array
    {
        $reservations = InventoryReservation::query()
            ->with(['product', 'warehouse', 'reservedBy'])
            ->where('source_type', $sourceType)
            ->where('source_uid', $sourceUid)
            ->latest()
            ->get();

        return [
            'source_type' => $sourceType,
            'source_uid' => $sourceUid,
            'reservations' => $reservations,
            'totals' => [
                'reserved_units' => (int) $reservations->where('status', 'active')->sum('quantity'),
                'active_reservations' => (int) $reservations->where('status', 'active')->count(),
            ],
        ];
    }

    public function movements(array $filters)
    {
        $validated = Validator::make($filters, [
            'product_uid' => 'nullable|uuid',
            'warehouse_uid' => 'nullable|uuid',
            'reference_type' => 'nullable|string|max:255',
            'reference_uid' => 'nullable|string|max:255',
            'type' => 'nullable|string|max:255',
            'search' => 'nullable|string|max:255',
        ])->validate();

        $productId = !empty($validated['product_uid']) ? $this->getProductByUid($validated['product_uid'])->getKey() : null;
        $warehouseId = !empty($validated['warehouse_uid']) ? $this->getWarehouseByUid($validated['warehouse_uid'])->getKey() : null;

        $query = InventoryMovement::query()
            ->with(['product', 'fromWarehouse', 'toWarehouse', 'performedBy'])
            ->when($productId, fn ($query) => $query->where('product_id', $productId))
            ->when($warehouseId, function ($query) use ($warehouseId) {
                $query->where(function ($nested) use ($warehouseId) {
                    $nested->where('from_warehouse_id', $warehouseId)
                        ->orWhere('to_warehouse_id', $warehouseId);
                });
            })
            ->when(!empty($validated['reference_type']), fn ($query) => $query->where('reference_type', $validated['reference_type']))
            ->when(!empty($validated['reference_uid']), fn ($query) => $query->where('reference_uid', $validated['reference_uid']))
            ->when(!empty($validated['type']), fn ($query) => $query->where('type', $validated['type']))
            ->when(!empty($validated['search']), function ($query) use ($validated) {
                $search = '%' . mb_strtolower($validated['search']) . '%';

                $query->where(function ($builder) use ($search) {
                    $builder
                        ->whereHas('product', function ($productQuery) use ($search) {
                            $productQuery
                                ->whereRaw('LOWER(name) LIKE ?', [$search])
                                ->orWhereRaw('LOWER(sku) LIKE ?', [$search]);
                        })
                        ->orWhereRaw('LOWER(reference_uid) LIKE ?', [$search])
                        ->orWhereRaw('LOWER(comment) LIKE ?', [$search]);
                });
            })
            ->latest();

        return ApiIndex::paginateOrGet($query, $filters, 'inventory_movements_page');
    }

    public function movementsSummary(array $filters = []): array
    {
        $validated = Validator::make($filters, [
            'period' => 'nullable|date_format:Y-m',
        ])->validate();

        $period = $validated['period'] ?? now()->format('Y-m');
        $start = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $period . '-01 00:00:00')->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $movements = InventoryMovement::query()
            ->whereBetween('created_at', [$start, $end])
            ->get();

        return [
            'total' => $movements->count(),
            'entries' => $movements->where('type', 'adjustment_in')->count(),
            'transfers' => $movements->where('type', 'transfer')->count(),
            'adjustments' => $movements
                ->whereIn('type', ['adjustment_out', 'set_balance'])
                ->count(),
        ];
    }

    public function transferStock(array $data): array
    {
        $validated = Validator::make($data, [
            'product_uid' => 'required|uuid',
            'from_warehouse_uid' => 'required|uuid|different:to_warehouse_uid',
            'to_warehouse_uid' => 'required|uuid',
            'quantity' => 'required|integer|min:1',
            'comment' => 'nullable|string',
        ])->validate();

        return DB::transaction(function () use ($validated) {
            $product = $this->getProductByUid($validated['product_uid']);
            $from = $this->getWarehouseByUid($validated['from_warehouse_uid']);
            $to = $this->getWarehouseByUid($validated['to_warehouse_uid']);
            $fromStock = $this->getOrCreateStock($product, $from);
            $toStock = $this->getOrCreateStock($product, $to);

            $fromBefore = (int) $fromStock->physical_stock;
            $toBefore = (int) $toStock->physical_stock;
            $available = max(0, $fromBefore - (int) $fromStock->reserved_stock);
            $projectedFrom = $fromBefore - (int) $validated['quantity'];
            $projectedTo = $toBefore + (int) $validated['quantity'];

            if ($available < (int) $validated['quantity']) {
                throw ValidationException::withMessages([
                    'quantity' => ['La transferencia excede el stock disponible en origen'],
                ]);
            }

            $fromStock->update(['physical_stock' => $projectedFrom]);
            $toStock->update(['physical_stock' => $projectedTo]);

            $movement = $this->recordMovement([
                'product_id' => $product->getKey(),
                'from_warehouse_id' => $from->getKey(),
                'to_warehouse_id' => $to->getKey(),
                'type' => 'transfer',
                'quantity' => (int) $validated['quantity'],
                'comment' => $validated['comment'] ?? null,
                'meta' => [
                    'from_previous_physical_stock' => $fromBefore,
                    'from_projected_physical_stock' => $projectedFrom,
                    'to_previous_physical_stock' => $toBefore,
                    'to_projected_physical_stock' => $projectedTo,
                ],
            ]);

            return [
                'movement' => $movement,
                'preview' => [
                    'product_uid' => $product->uid,
                    'from_warehouse_uid' => $from->uid,
                    'to_warehouse_uid' => $to->uid,
                    'quantity' => (int) $validated['quantity'],
                    'from' => [
                        'physical_stock' => $fromBefore,
                        'reserved_stock' => (int) $fromStock->reserved_stock,
                        'available_stock' => $available,
                        'projected_physical_stock' => $projectedFrom,
                        'projected_available_stock' => max(0, $projectedFrom - (int) $fromStock->reserved_stock),
                    ],
                    'to' => [
                        'physical_stock' => $toBefore,
                        'reserved_stock' => (int) $toStock->reserved_stock,
                        'available_stock' => max(0, $toBefore - (int) $toStock->reserved_stock),
                        'projected_physical_stock' => $projectedTo,
                        'projected_available_stock' => max(0, $projectedTo - (int) $toStock->reserved_stock),
                    ],
                ],
            ];
        });
    }

    public function report(array $filters): array
    {
        $master = $this->master($filters);
        $rows = collect($master['data']);
        $summaryByCategory = $rows
            ->groupBy(fn (array $row) => $row['category_name'] ?? 'Sin categoria')
            ->map(fn (Collection $group, string $category) => [
                'category' => $category,
                'products' => $group->count(),
                'physical_stock' => (int) $group->sum('stock_physical_total'),
                'reserved_stock' => (int) $group->sum('stock_reserved_total'),
                'available_stock' => (int) $group->sum('stock_available_total'),
            ])
            ->values();

        $criticalProducts = $rows->whereIn('stock_state', ['low', 'out'])->values();
        $criticalCount = $criticalProducts->count();
        $totalProducts = max(1, $rows->count());
        $criticalRatio = round(($criticalCount / $totalProducts) * 100, 2);

        return [
            'filters' => $master['filters'],
            'summary_by_category' => $summaryByCategory,
            'critical_products' => $criticalProducts,
            'rupture_risk' => [
                'critical_products_count' => $criticalCount,
                'out_of_stock_count' => $rows->where('stock_state', 'out')->count(),
                'low_stock_count' => $rows->where('stock_state', 'low')->count(),
                'risk_percentage' => $criticalRatio,
                'risk_level' => match (true) {
                    $criticalRatio >= 60 => 'high',
                    $criticalRatio >= 25 => 'medium',
                    default => 'low',
                },
            ],
        ];
    }

    public function reportAsCsv(array $filters): string
    {
        $rows = collect($this->master($filters)['data']);
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, [
            'sku',
            'product',
            'category',
            'warehouse_uid',
            'stock_physical_total',
            'stock_reserved_total',
            'stock_available_total',
            'stock_state',
            'stock_indicator',
        ]);

        foreach ($rows as $row) {
            fputcsv($stream, [
                $row['sku'],
                $row['product'],
                $row['category_name'],
                $row['warehouse_uid'],
                $row['stock_physical_total'],
                $row['stock_reserved_total'],
                $row['stock_available_total'],
                $row['stock_state'],
                $row['stock_indicator'],
            ]);
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return $csv ?: '';
    }

    private function validateProduct(array $data, bool $partial = false): array
    {
        return Validator::make($data, [
            'category_uid' => 'nullable|uuid',
            'sku' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'description' => 'nullable|string',
            'unit_cost' => 'sometimes|numeric|min:0',
            'cost_price' => 'sometimes|numeric|min:0',
            'sale_price' => 'nullable|numeric|min:0',
            'discount_percent' => 'sometimes|numeric|min:0|max:100',
            'reorder_point' => 'sometimes|integer|min:0',
            'is_active' => 'sometimes|boolean',
            'warehouse_stocks' => 'sometimes|array',
            'warehouse_stocks.*.warehouse_uid' => 'required_with:warehouse_stocks|uuid',
            'warehouse_stocks.*.physical_stock' => 'required_without:warehouse_stocks.*.quantity|integer|min:0',
            'warehouse_stocks.*.quantity' => 'required_without:warehouse_stocks.*.physical_stock|integer|min:0',
        ])->validate();
    }

    private function paginationFilters(array $filters): array
    {
        return $filters + ['page' => 1, 'per_page' => ApiIndex::perPage($filters)];
    }

    private function validateExportPayload(array $payload): array
    {
        return Validator::make($payload, [
            'format' => 'required|string|in:excel,pdf,csv',
            'fields' => 'nullable|array',
            'fields.*' => 'string',
            'filters' => 'nullable|array',
        ])->validate();
    }

    private function syncWarehouseStocks(InventoryProduct $product, array $warehouseStocks, bool $updating = false): void
    {
        foreach ($warehouseStocks as $stockPayload) {
            $warehouse = $this->getWarehouseByUid($stockPayload['warehouse_uid']);
            $stock = $this->getOrCreateStock($product, $warehouse);

            $physicalStock = (int) ($stockPayload['physical_stock'] ?? $stockPayload['quantity']);

            if ($updating && (int) $stock->reserved_stock > $physicalStock) {
                throw ValidationException::withMessages([
                    'warehouse_stocks' => ['El stock fisico no puede quedar por debajo del stock reservado'],
                ]);
            }

            $stock->update([
                'physical_stock' => $physicalStock,
            ]);
        }
    }

    private function getCategoryByUid(string $uid): InventoryCategory
    {
        return InventoryCategory::query()->where('uid', $uid)->firstOrFail();
    }

    private function getProductByUid(string $uid): InventoryProduct
    {
        return InventoryProduct::query()->with(['category', 'stocks.warehouse'])->where('uid', $uid)->firstOrFail();
    }

    private function getWarehouseByUid(string $uid): Warehouse
    {
        return Warehouse::query()->where('uid', $uid)->firstOrFail();
    }

    private function resolveCategoryId(?string $uid): ?int
    {
        if (!$uid) {
            return null;
        }

        $categoryId = InventoryCategory::query()->where('uid', $uid)->value('id');

        if (!$categoryId) {
            throw ValidationException::withMessages([
                'category_uid' => ['La categoria no existe o no pertenece a este tenant'],
            ]);
        }

        return $categoryId;
    }

    private function uniqueInventoryCategoryKey(string $source): string
    {
        $base = str_replace('-', '_', Str::slug($source, '_')) ?: 'categoria';
        $key = $base;
        $suffix = 2;

        while (InventoryCategory::query()->where('key', $key)->exists()) {
            $key = "{$base}_{$suffix}";
            $suffix++;
        }

        return $key;
    }

    private function getOrCreateStock(InventoryProduct $product, Warehouse $warehouse): InventoryStock
    {
        return InventoryStock::query()->firstOrCreate(
            [
                'product_id' => $product->getKey(),
                'warehouse_id' => $warehouse->getKey(),
            ],
            [
                'physical_stock' => 0,
                'reserved_stock' => 0,
            ]
        );
    }

    private function recordMovement(array $payload): InventoryMovement
    {
        return InventoryMovement::query()->create([
            'product_id' => $payload['product_id'],
            'from_warehouse_id' => $payload['from_warehouse_id'] ?? null,
            'to_warehouse_id' => $payload['to_warehouse_id'] ?? null,
            'performed_by_user_id' => auth()->id(),
            'type' => $payload['type'],
            'quantity' => $payload['quantity'],
            'comment' => $payload['comment'] ?? null,
            'reference_type' => $payload['reference_type'] ?? null,
            'reference_uid' => $payload['reference_uid'] ?? null,
            'meta' => $payload['meta'] ?? null,
        ])->fresh(['product', 'fromWarehouse', 'toWarehouse', 'performedBy']);
    }

    private function applyProductSearch($query, string $search)
    {
        $term = '%' . mb_strtolower($search) . '%';

        return $query->where(function ($builder) use ($term) {
            $builder
                ->whereRaw('LOWER(name) LIKE ?', [$term])
                ->orWhereRaw('LOWER(sku) LIKE ?', [$term]);
        });
    }

    private function reservationPreview(InventoryStock $stock, int $requestedQuantity): array
    {
        $physical = (int) $stock->physical_stock;
        $reserved = (int) $stock->reserved_stock;
        $available = max(0, $physical - $reserved);
        $projectedReserved = $reserved + $requestedQuantity;
        $projectedAvailable = $physical - $projectedReserved;

        return [
            'stock_actual' => $physical,
            'stock_reservado_actual' => $reserved,
            'stock_disponible' => $available,
            'unidades_a_reservar' => $requestedQuantity,
            'resultado_final_proyectado' => $projectedAvailable,
            'exceeds_available' => $projectedAvailable < 0,
        ];
    }

    private function masterRow(InventoryProduct $product, ?Warehouse $warehouse = null): array
    {
        $stocks = $warehouse
            ? $product->stocks->where('warehouse_id', $warehouse->getKey())
            : $product->stocks;

        $physical = (int) $stocks->sum('physical_stock');
        $reserved = (int) $stocks->sum('reserved_stock');
        $available = max(0, $physical - $reserved);
        $state = match (true) {
            $available <= 0 => 'out',
            $available <= (int) $product->reorder_point => 'low',
            default => 'normal',
        };

        return [
            'uid' => $product->uid,
            'sku' => $product->sku,
            'name' => $product->name,
            'product' => $product->name,
            'description' => $product->description,
            'category_uid' => $product->category?->uid,
            'category_name' => $product->category?->name,
            'unit_cost' => (float) $product->cost_price,
            'sale_price' => (float) $product->sale_price,
            'discount_percent' => (float) $product->discount_percent,
            'is_active' => (bool) $product->is_active,
            'warehouse_uid' => $warehouse?->uid,
            'stock_physical_total' => $physical,
            'stock_reserved_total' => $reserved,
            'stock_available_total' => $available,
            'stock_state' => $state,
            'stock_indicator' => match ($state) {
                'out' => 'red',
                'low' => 'yellow',
                default => 'green',
            },
            'reorder_point' => (int) $product->reorder_point,
            'stocks' => $stocks->map(function (InventoryStock $stock) {
                return [
                    'uid' => $stock->uid,
                    'product_uid' => $stock->product?->uid ?? $stock->product_uid,
                    'warehouse_uid' => $stock->warehouse?->uid ?? $stock->warehouse_uid,
                    'physical_stock' => (int) $stock->physical_stock,
                    'reserved_stock' => (int) $stock->reserved_stock,
                    'available_stock' => (int) $stock->available_stock,
                    'stock_state' => $stock->stock_state,
                    'stock_indicator' => $stock->stock_indicator,
                    'warehouse' => $stock->warehouse ? [
                        'uid' => $stock->warehouse->uid,
                        'name' => $stock->warehouse->name,
                        'code' => $stock->warehouse->code,
                    ] : null,
                ];
            })->values(),
        ];
    }

    private function warehouseRow(Warehouse $warehouse): array
    {
        $stocks = $warehouse->stocks;

        return [
            'uid' => $warehouse->uid,
            'name' => $warehouse->name,
            'code' => $warehouse->code,
            'location' => $warehouse->location,
            'is_active' => (bool) $warehouse->is_active,
            'summary' => [
                'sku_count' => $stocks->where('physical_stock', '>', 0)->pluck('product_id')->unique()->count(),
                'total_physical' => (int) $stocks->sum('physical_stock'),
                'total_reserved' => (int) $stocks->sum('reserved_stock'),
                'total_available' => (int) $stocks->sum(fn (InventoryStock $stock) => $stock->available_stock),
                'total_value' => round((float) $stocks->sum(fn (InventoryStock $stock) => (int) $stock->physical_stock * (float) ($stock->product?->cost_price ?? 0)), 2),
            ],
        ];
    }
}
