<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use App\Services\LoggerService;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )

    ->withSchedule(function (Schedule $schedule) {
        $schedule->command('admin-alerts:evaluate')->everyFiveMinutes()->withoutOverlapping();
        $schedule->command('activities:sync-overdue')->everyMinute()->withoutOverlapping();
    })

    ->withMiddleware(function (Middleware $middleware) {

        // Mantienes lo que ya tienes
        $middleware->redirectGuestsTo(fn (Request $request) => null);
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
        $middleware->append(\App\Http\Middleware\TrackRequests::class);

        // registrar alias del middleware SaaS
        $middleware->alias([
            'tenant.active' => \App\Http\Middleware\CheckTenantActive::class,
            'permission' => \App\Http\Middleware\EnsureUserHasPermission::class,
            'tenant.token' => \App\Http\Middleware\EnsureTenantTokenAccess::class,
            'tenant.schema' => \App\Http\Middleware\SetTenantSchema::class,
            'full.access' => \App\Http\Middleware\EnsureFullAccessToken::class,
            'feature' => \App\Http\Middleware\CheckFeatureEnabled::class,
            'platform.admin' => \App\Http\Middleware\EnsurePlatformAdminAccess::class,
            'platform.permission' => \App\Http\Middleware\EnsurePlatformPermission::class,
            'support.readonly' => \App\Http\Middleware\EnsureSupportReadOnly::class,
            'docs.auth' => \App\Http\Middleware\ProtectApiDocs::class,
        ]);
    })

    ->withExceptions(function (Exceptions $exceptions) {

        //  auth
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            return response()->json([
                'success' => false,
                'message' => 'No autenticado',
                'data' => null,
                'errors' => [
                    'auth' => ['No autenticado'],
                ],
            ], 401);
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'data' => null,
                'errors' => $e->errors(),
            ], 422);
        });

        // CAPTURA GLOBAL DE ERRORES
        $exceptions->report(function (\Throwable $e) {

            //evitar ruido (validaciones)
            if ($e instanceof \Illuminate\Validation\ValidationException) {
                return;
            }

            LoggerService::log('error', $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => substr($e->getTraceAsString(), 0, 1000) // 🔥 LIMITADO
            ]);

        });

    })

    ->create();
