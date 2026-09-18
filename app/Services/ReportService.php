<?php

namespace App\Services;

use App\Models\InventoryCategory;
use App\Models\InventoryMovement;
use App\Models\InventoryProduct;
use App\Models\InventoryStock;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\Warehouse;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;

class ReportService
{
    public function __construct(
        private readonly InventoryService $inventoryService,
        private readonly FinancialDashboardService $financialDashboardService,
        private readonly ExportService $exportService
    ) {
    }

    public function sales(array $filters = []): array
    {
        $validated = $this->validateFilters($filters, ['status', 'products', 'distributors', 'vs'], 'status');
        [$start, $end] = $this->dateRange($validated);
        $warehouse = $this->warehouseFromFilter($validated['warehouse'] ?? null);
        $category = $this->categoryFromFilter($validated['category'] ?? null);

        $quotations = Quotation::query()
            ->with(['items.product.category', 'items.warehouse', 'quoteable', 'owner'])
            ->whereBetween('created_at', [$start, $end])
            ->when($warehouse || $category, function (Builder $query) use ($warehouse, $category) {
                $query->whereHas('items', function (Builder $items) use ($warehouse, $category) {
                    $items->when($warehouse, fn (Builder $itemQuery) => $itemQuery->where('warehouse_id', $warehouse->getKey()))
                        ->when($category, function (Builder $itemQuery) use ($category) {
                            $itemQuery->whereHas('product', fn (Builder $productQuery) => $productQuery->where('category_id', $category->getKey()));
                        });
                });
            })
            ->latest()
            ->get();

        $statusCounts = [
            'Aprobadas' => $quotations->whereIn('status', ['approved', 'accepted', 'won'])->count(),
            'Facturadas' => $quotations->whereIn('status', ['invoiced'])->count(),
            'Enviadas' => $quotations->whereIn('status', ['sent', 'pending'])->count(),
            'Borrador' => $quotations->whereIn('status', ['draft'])->count(),
            'Rechazadas' => $quotations->whereIn('status', ['rejected', 'lost'])->count(),
        ];

        $response = [
            'kpis' => [
                'Total Generadas' => $quotations->count(),
                'Aprobadas' => $statusCounts['Aprobadas'],
                'Pendientes (En aire)' => $statusCounts['Enviadas'],
                'Rechazadas' => $statusCounts['Rechazadas'],
            ],
            'chart_data' => $this->salesChartData($validated['tab'], $quotations, $statusCounts),
            'table_data' => $quotations->take(25)->map(fn (Quotation $quotation) => $this->salesRow($quotation))->values()->all(),
        ];

        return array_merge($response, [
            'filters' => $this->normalizedFilterPayload($validated, $warehouse, $category),
            'monthly_sales' => $this->financialDashboardService->getMonthlySales(),
        ]);
    }

    public function inventory(array $filters = []): array
    {
        $validated = $this->validateFilters($filters, ['warehouse', 'risk', 'movements', 'category', 'b2b'], 'warehouse');
        [$start, $end] = $this->dateRange($validated);
        $warehouse = $this->warehouseFromFilter($validated['warehouse'] ?? null);
        $category = $this->categoryFromFilter($validated['category'] ?? null);

        $inventoryFilters = [];
        if ($warehouse) {
            $inventoryFilters['warehouse_uid'] = $warehouse->uid;
        }
        if ($category) {
            $inventoryFilters['category_uid'] = $category->uid;
        }

        $legacyReport = $this->inventoryService->report($inventoryFilters);
        $rows = collect($legacyReport['critical_products'])
            ->merge(collect($this->inventoryService->master(array_merge($inventoryFilters, ['paginate' => false]))['data'])->whereNotIn('stock_state', ['low', 'out']))
            ->unique('uid')
            ->values();

        $critical = $rows->whereIn('stock_state', ['low', 'out'])->sortBy('stock_available_total')->values();
        $response = [
            'kpis' => [
                'Productos' => $rows->count(),
                'Disponibles' => (int) $rows->sum('stock_available_total'),
                'Stock bajo' => $rows->where('stock_state', 'low')->count(),
                'Sin stock' => $rows->where('stock_state', 'out')->count(),
            ],
            'chart_data' => $this->inventoryChartData($validated['tab'], $rows, $start, $end, $warehouse, $category),
            'table_data' => $this->inventoryTableData($validated['tab'], $rows, $start, $end, $warehouse, $category),
            'most_critical' => $validated['tab'] === 'risk' ? $this->mostCritical($critical) : null,
            'filters' => $this->normalizedFilterPayload($validated, $warehouse, $category),
        ];

        return array_merge($legacyReport, $response);
    }

    public function filters(): array
    {
        return [
            'warehouses' => Warehouse::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get()
                ->map(fn (Warehouse $warehouse) => [
                    'value' => $warehouse->uid,
                    'label' => $warehouse->name,
                ])
                ->values()
                ->all(),
            'categories' => InventoryCategory::query()
                ->orderBy('name')
                ->get()
                ->map(fn (InventoryCategory $category) => [
                    'value' => $category->uid,
                    'label' => $category->name,
                ])
                ->values()
                ->all(),
        ];
    }

    public function exportSales(array $payload)
    {
        $validated = $this->validateExportPayload($payload, ['status', 'products', 'distributors', 'vs']);
        $filters = array_merge($validated['filters'] ?? [], ['tab' => $validated['tab'] ?? 'status']);
        $report = $this->sales($filters);

        return $this->exportService->file('reporte-ventas', $report['table_data'] ?? [], [
            'format' => $validated['format'],
            'fields' => $validated['fields'] ?? [],
            'filters' => $filters,
        ]);
    }

    public function exportInventory(array $payload)
    {
        $validated = $this->validateExportPayload($payload, ['warehouse', 'risk', 'movements', 'category', 'b2b']);
        $filters = array_merge($validated['filters'] ?? [], ['tab' => $validated['tab'] ?? 'warehouse']);
        $report = $this->inventory($filters);

        return $this->exportService->file('reporte-inventario', $report['table_data'] ?? [], [
            'format' => $validated['format'],
            'fields' => $validated['fields'] ?? [],
            'filters' => $filters,
        ]);
    }

    private function validateFilters(array $filters, array $tabs, string $defaultTab): array
    {
        return Validator::make($filters, [
            'tab' => 'nullable|string|in:' . implode(',', $tabs),
            'period' => 'nullable|string|in:Hoy,Esta semana,Este mes,Este trimestre,Personalizado',
            'warehouse' => 'nullable|string|max:255',
            'category' => 'nullable|string|max:255',
            'start_date' => 'nullable|required_if:period,Personalizado|date_format:Y-m-d',
            'end_date' => 'nullable|required_if:period,Personalizado|date_format:Y-m-d|after_or_equal:start_date',
        ])->validate() + [
            'tab' => $defaultTab,
            'period' => 'Este mes',
        ];
    }

    private function validateExportPayload(array $payload, array $tabs): array
    {
        return Validator::make($payload, [
            'format' => 'required|string|in:excel,pdf,csv',
            'fields' => 'nullable|array',
            'fields.*' => 'string',
            'filters' => 'nullable|array',
            'tab' => 'nullable|string|in:' . implode(',', $tabs),
        ])->validate();
    }

    private function dateRange(array $filters): array
    {
        $today = CarbonImmutable::today();

        return match ($filters['period'] ?? 'Este mes') {
            'Hoy' => [$today->startOfDay(), $today->endOfDay()],
            'Esta semana' => [$today->startOfWeek(), $today->endOfWeek()],
            'Este trimestre' => [$today->firstOfQuarter()->startOfDay(), $today->lastOfQuarter()->endOfDay()],
            'Personalizado' => [
                CarbonImmutable::parse($filters['start_date'])->startOfDay(),
                CarbonImmutable::parse($filters['end_date'])->endOfDay(),
            ],
            default => [$today->startOfMonth(), $today->endOfMonth()],
        };
    }

    private function salesChartData(string $tab, Collection $quotations, array $statusCounts): array
    {
        if ($tab === 'status') {
            return [
                'series' => array_values($statusCounts),
                'labels' => array_keys($statusCounts),
                'categories' => null,
            ];
        }

        if ($tab === 'products') {
            $products = $quotations->flatMap(fn (Quotation $quotation) => $quotation->items)
                ->groupBy(fn (QuotationItem $item) => $item->description ?: $item->product?->name ?: 'Sin producto')
                ->map(fn (Collection $items) => round((float) $items->sum(fn (QuotationItem $item) => $item->line_total), 2))
                ->sortDesc()
                ->take(10);

            return [
                'series' => $products->values()->all(),
                'labels' => null,
                'categories' => $products->keys()->values()->all(),
            ];
        }

        if ($tab === 'distributors') {
            $distributors = $quotations
                ->groupBy(fn (Quotation $quotation) => $this->quoteableName($quotation))
                ->map(fn (Collection $group) => round((float) $group->sum(fn (Quotation $quotation) => $quotation->total), 2))
                ->sortDesc()
                ->take(10);

            return [
                'series' => $distributors->values()->all(),
                'labels' => null,
                'categories' => $distributors->keys()->values()->all(),
            ];
        }

        $byDay = $quotations
            ->groupBy(fn (Quotation $quotation) => $quotation->created_at->format('Y-m-d'))
            ->sortKeys()
            ->map(fn (Collection $group) => round((float) $group->sum(fn (Quotation $quotation) => $quotation->total), 2));

        return [
            'series' => $byDay->values()->all(),
            'labels' => null,
            'categories' => $byDay->keys()->values()->all(),
        ];
    }

    private function salesRow(Quotation $quotation): array
    {
        return [
            'id' => $quotation->uid,
            'cliente' => $this->quoteableName($quotation),
            'fecha' => $quotation->created_at?->toDateString(),
            'items' => $quotation->items->sum('quantity'),
            'total' => '$' . number_format((float) $quotation->total, 0),
            'ejecutivo' => $quotation->owner?->name,
            'statusBadge' => $this->statusBadge($quotation->status),
        ];
    }

    private function inventoryChartData(string $tab, Collection $rows, CarbonImmutable $start, CarbonImmutable $end, ?Warehouse $warehouse, ?InventoryCategory $category): array
    {
        if ($tab === 'risk') {
            $counts = [
                'Normal' => $rows->where('stock_state', 'normal')->count(),
                'Stock bajo' => $rows->where('stock_state', 'low')->count(),
                'Sin stock' => $rows->where('stock_state', 'out')->count(),
            ];

            return [
                'series' => array_values($counts),
                'labels' => array_keys($counts),
                'categories' => null,
            ];
        }

        if ($tab === 'movements') {
            $movements = $this->filteredMovements($start, $end, $warehouse, $category)
                ->groupBy('type')
                ->map(fn (Collection $group) => (int) $group->sum('quantity'));

            return [
                'series' => $movements->values()->all(),
                'labels' => null,
                'categories' => $movements->keys()->values()->all(),
            ];
        }

        $grouped = $rows->groupBy(match ($tab) {
            'category' => fn (array $row) => $row['category_name'] ?? 'Sin categoria',
            'b2b' => fn (array $row) => $row['name'] ?? $row['sku'],
            default => fn (array $row) => $row['stocks']->first()['warehouse']['name'] ?? 'Sin bodega',
        })->map(fn (Collection $group) => (int) $group->sum('stock_available_total'));

        return [
            'series' => $grouped->values()->all(),
            'labels' => null,
            'categories' => $grouped->keys()->values()->all(),
        ];
    }

    private function inventoryTableData(string $tab, Collection $rows, CarbonImmutable $start, CarbonImmutable $end, ?Warehouse $warehouse, ?InventoryCategory $category): array
    {
        if ($tab === 'movements') {
            return $this->filteredMovements($start, $end, $warehouse, $category)
                ->take(50)
                ->map(fn (InventoryMovement $movement) => [
                    'id' => $movement->uid,
                    'fecha' => $movement->created_at?->toDateString(),
                    'producto' => $movement->product?->name,
                    'tipo' => $movement->type,
                    'cantidad' => (int) $movement->quantity,
                    'bodega_origen' => $movement->fromWarehouse?->name,
                    'bodega_destino' => $movement->toWarehouse?->name,
                ])
                ->values()
                ->all();
        }

        return $rows->take(50)->map(fn (array $row) => [
            'id' => $row['uid'],
            'sku' => $row['sku'],
            'producto' => $row['name'],
            'categoria' => $row['category_name'],
            'disponible' => (int) $row['stock_available_total'],
            'reservado' => (int) $row['stock_reserved_total'],
            'min_stock' => (int) $row['reorder_point'],
            'estado' => $row['stock_state'],
        ])->values()->all();
    }

    private function filteredMovements(CarbonImmutable $start, CarbonImmutable $end, ?Warehouse $warehouse, ?InventoryCategory $category): Collection
    {
        return InventoryMovement::query()
            ->with(['product.category', 'fromWarehouse', 'toWarehouse'])
            ->whereBetween('created_at', [$start, $end])
            ->when($warehouse, function (Builder $query) use ($warehouse) {
                $query->where(function (Builder $nested) use ($warehouse) {
                    $nested->where('from_warehouse_id', $warehouse->getKey())
                        ->orWhere('to_warehouse_id', $warehouse->getKey());
                });
            })
            ->when($category, fn (Builder $query) => $query->whereHas('product', fn (Builder $productQuery) => $productQuery->where('category_id', $category->getKey())))
            ->latest()
            ->get();
    }

    private function mostCritical(Collection $critical): ?array
    {
        $row = $critical->first();

        if (!$row) {
            return null;
        }

        return [
            'sku' => $row['sku'],
            'name' => $row['name'],
            'available' => (int) $row['stock_available_total'],
            'min_stock' => (int) $row['reorder_point'],
        ];
    }

    private function warehouseFromFilter(?string $value): ?Warehouse
    {
        if (!$value) {
            return null;
        }

        return Warehouse::query()
            ->where(function (Builder $query) use ($value) {
                $query->where('uid', $value)->orWhere('code', $value);
            })
            ->first();
    }

    private function categoryFromFilter(?string $value): ?InventoryCategory
    {
        if (!$value) {
            return null;
        }

        return InventoryCategory::query()
            ->where(function (Builder $query) use ($value) {
                $query->where('uid', $value)->orWhere('key', $value);
            })
            ->first();
    }

    private function normalizedFilterPayload(array $filters, ?Warehouse $warehouse, ?InventoryCategory $category): array
    {
        return [
            'tab' => $filters['tab'],
            'period' => $filters['period'],
            'warehouse' => $warehouse?->uid,
            'category' => $category?->uid,
        ];
    }

    private function quoteableName(Quotation $quotation): string
    {
        return $quotation->quoteable?->display_name
            ?? $quotation->quoteable?->name
            ?? $quotation->title
            ?? 'Sin cliente';
    }

    private function statusBadge(string $status): array
    {
        return match ($status) {
            'approved', 'accepted', 'won' => ['label' => 'Aprobada', 'color' => 'success'],
            'invoiced' => ['label' => 'Facturada', 'color' => 'success'],
            'sent', 'pending' => ['label' => 'Enviada', 'color' => 'info'],
            'rejected', 'lost' => ['label' => 'Rechazada', 'color' => 'error'],
            'draft' => ['label' => 'Borrador', 'color' => 'default'],
            default => ['label' => ucfirst($status), 'color' => 'warning'],
        };
    }
}
