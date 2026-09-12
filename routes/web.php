<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::get('/', function () {
    return view('welcome');
});

Route::withoutMiddleware([
    'web',
    EncryptCookies::class,
    AddQueuedCookiesToResponse::class,
    StartSession::class,
    ShareErrorsFromSession::class,
    VerifyCsrfToken::class,
])->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);

    Route::middleware(['auth:sanctum', 'tenant.active', 'tenant.token', 'tenant.schema'])->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/2fa/setup', [AuthController::class, 'twoFactorSetup']);
        Route::post('/2fa/confirm', [AuthController::class, 'confirmTwoFactor']);
    });

    Route::middleware(['auth:sanctum', 'tenant.active', 'tenant.token', 'tenant.schema', 'full.access', 'support.readonly'])->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::get('/me/features', [AuthController::class, 'features']);
        Route::put('/me', [AuthController::class, 'updateMe']);
        Route::get('/auth/init', [AuthController::class, 'init']);
        Route::post('/2fa/recovery-codes/regenerate', [AuthController::class, 'regenerateRecoveryCodes']);
        Route::delete('/2fa', [AuthController::class, 'disableTwoFactor']);
    });
});

Route::middleware('docs.auth')->group(function () {
    Route::get('/docs', fn () => response()->file(public_path('swagger/index.html')));
    Route::get('/swagger/index.html', fn () => response()->file(public_path('swagger/index.html')));
    Route::get('/openapi.yaml', fn () => response()->file(public_path('openapi.yaml'), [
        'Content-Type' => 'application/yaml; charset=UTF-8',
    ]));
})->withoutMiddleware([
    'web',
    EncryptCookies::class,
    AddQueuedCookiesToResponse::class,
    StartSession::class,
    ShareErrorsFromSession::class,
    VerifyCsrfToken::class,
]);
