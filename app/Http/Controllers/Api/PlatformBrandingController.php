<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PlatformBrandingService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PlatformBrandingController extends Controller
{
    public function __construct(private readonly PlatformBrandingService $service) {}

    public function show()
    {
        return $this->successResponse($this->service->get());
    }

    public function update(Request $request)
    {
        try {
            return $this->successResponse(
                $this->service->update($request->all(), $request->file('logo')),
                200,
                'Branding de plataforma actualizado'
            );
        } catch (ValidationException $e) {
            return $this->errorResponse('Validation error', 422, $e->errors());
        }
    }
}
