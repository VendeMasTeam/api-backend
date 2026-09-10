<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\TransientToken;

class EnsureSupportReadOnly
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        if (! $user?->tokenCan('support:tenant') || $token instanceof TransientToken) {
            return $next($request);
        }

        if ($request->isMethodSafe() || $request->is('api/support-session/stop') || $request->is('api/logout')) {
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'message' => 'Modo soporte solo permite lectura',
            'data' => null,
            'meta' => null,
            'errors' => [
                'support_mode' => ['No puedes modificar datos mientras estas en modo soporte'],
            ],
        ], 403);
    }
}
