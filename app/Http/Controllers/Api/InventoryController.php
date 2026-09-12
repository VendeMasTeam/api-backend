<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\InventoryService;
use App\Support\ApiIndex;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class InventoryController extends Controller
{
    public function __construct(private readonly InventoryService $inventoryService)
    {
    }

    public function master(Request $request)
    {
        try {
            $payload = $this->inventoryService->master($request->query());
            $meta = null;

            if (($payload['data'] ?? null) instanceof LengthAwarePaginator) {
                $meta = ApiIndex::meta($payload['data']);
                $payload['data'] = $payload['data']->items();
            }

            return response()->json([
                'success' => true,
                'message' => null,
                'data' => $payload,
                'meta' => $meta,
                'errors' => null,
            ]);
        } catch (ValidationException $e) {
            return $this->errorResponse('Validation error', 422, $e->errors());
        }
    }

    public function availability(Request $request)
    {
        try {
            return $this->successResponse($this->inventoryService->availability($request->query()));
        } catch (ValidationException $e) {
            return $this->errorResponse('Validation error', 422, $e->errors());
        }
    }

    public function adjust(Request $request)
    {
        try {
            return $this->successResponse($this->inventoryService->adjustStock($request->all()), 200, 'Stock ajustado');
        } catch (ValidationException $e) {
            return $this->errorResponse('Validation error', 422, $e->errors());
        } catch (\Throwable $e) {
            return $this->errorResponse('Server error', 500, ['server' => [$e->getMessage()]]);
        }
    }

    public function adjustBulk(Request $request)
    {
        try {
            return $this->successResponse($this->inventoryService->adjustStockBulk($request->all()), 200, 'Stock ajustado');
        } catch (ValidationException $e) {
            return $this->errorResponse('Validation error', 422, $e->errors());
        } catch (\Throwable $e) {
            return $this->errorResponse('Server error', 500, ['server' => [$e->getMessage()]]);
        }
    }

    public function reserve(Request $request)
    {
        try {
            return $this->successResponse($this->inventoryService->reserveStock($request->all()), 201, 'Stock reservado');
        } catch (ValidationException $e) {
            return $this->errorResponse('Validation error', 422, $e->errors());
        } catch (\Throwable $e) {
            return $this->errorResponse('Server error', 500, ['server' => [$e->getMessage()]]);
        }
    }

    public function releaseReservation(string $uid)
    {
        try {
            return $this->successResponse($this->inventoryService->releaseReservation($uid), 200, 'Reserva liberada');
        } catch (ValidationException $e) {
            return $this->errorResponse('Validation error', 422, $e->errors());
        } catch (\Throwable $e) {
            return $this->errorResponse('Server error', 500, ['server' => [$e->getMessage()]]);
        }
    }

    public function consumeReservation(Request $request, string $uid)
    {
        try {
            return $this->successResponse($this->inventoryService->consumeReservation($uid, $request->all()), 200, 'Reserva consumida');
        } catch (ValidationException $e) {
            return $this->errorResponse('Validation error', 422, $e->errors());
        } catch (\Throwable $e) {
            return $this->errorResponse('Server error', 500, ['server' => [$e->getMessage()]]);
        }
    }

    public function reservationsBySource(string $sourceType, string $sourceUid)
    {
        return $this->successResponse($this->inventoryService->reservationsBySource($sourceType, $sourceUid));
    }

    public function transfer(Request $request)
    {
        try {
            return $this->successResponse($this->inventoryService->transferStock($request->all()), 200, 'Movimiento realizado');
        } catch (ValidationException $e) {
            return $this->errorResponse('Validation error', 422, $e->errors());
        } catch (\Throwable $e) {
            return $this->errorResponse('Server error', 500, ['server' => [$e->getMessage()]]);
        }
    }

    public function movements(Request $request)
    {
        try {
            return $this->successResponse($this->inventoryService->movements($request->query()));
        } catch (ValidationException $e) {
            return $this->errorResponse('Validation error', 422, $e->errors());
        }
    }

    public function movementsSummary(Request $request)
    {
        try {
            return $this->successResponse($this->inventoryService->movementsSummary($request->query()));
        } catch (ValidationException $e) {
            return $this->errorResponse('Validation error', 422, $e->errors());
        }
    }

    public function report(Request $request)
    {
        try {
            return $this->successResponse($this->inventoryService->report($request->query()));
        } catch (ValidationException $e) {
            return $this->errorResponse('Validation error', 422, $e->errors());
        }
    }

    public function exportReport(Request $request)
    {
        try {
            $csv = $this->inventoryService->reportAsCsv($request->query());

            return response($csv, 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="inventory-report.csv"',
            ]);
        } catch (ValidationException $e) {
            return $this->errorResponse('Validation error', 422, $e->errors());
        }
    }

    public function exportStock(Request $request)
    {
        try {
            return $this->inventoryService->exportStock($request->all());
        } catch (ValidationException $e) {
            return $this->errorResponse('Validation error', 422, $e->errors());
        }
    }

    public function stockEntryOptions(Request $request)
    {
        try {
            return $this->successResponse($this->inventoryService->stockEntryOptions($request->query()));
        } catch (ValidationException $e) {
            return $this->errorResponse('Validation error', 422, $e->errors());
        }
    }
}
