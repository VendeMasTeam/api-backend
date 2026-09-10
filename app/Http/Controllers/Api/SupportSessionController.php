<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SupportSessionService;
use Illuminate\Http\Request;

class SupportSessionController extends Controller
{
    public function current(Request $request, SupportSessionService $supportSessionService)
    {
        return $this->successResponse(
            $supportSessionService->currentPayload($request->user(), $request->user()?->currentAccessToken())
        );
    }

    public function stop(Request $request, SupportSessionService $supportSessionService)
    {
        return $this->successResponse(
            $supportSessionService->stop($request->user(), $request->user()?->currentAccessToken()),
            200,
            'Sesion de soporte finalizada'
        );
    }
}
