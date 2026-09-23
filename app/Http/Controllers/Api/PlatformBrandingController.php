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
                $this->service->update($request->all(), [
                    'logo_light' => $request->file('logo_light'),
                    'logo_dark' => $request->file('logo_dark'),
                    'favicon' => $request->file('favicon'),
                ]),
                200,
                'Branding de plataforma actualizado'
            );
        } catch (ValidationException $e) {
            return $this->errorResponse('Validation error', 422, $e->errors());
        }
    }
}
