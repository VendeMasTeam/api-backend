<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PlanPermissionService;
use App\Services\PlatformInitService;
use App\Services\TwoFactorService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        protected TwoFactorService $twoFactorService,
        protected PlatformInitService $platformInitService
    ) {
    }

    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string|min:6',
            'two_factor_code' => 'nullable|string',
            'recovery_code' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation error', 422, $validator->errors()->toArray());
        }

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return $this->invalidCredentialsResponse();
        }

        if ($user->isLocked()) {
            return $this->errorResponse('Cuenta bloqueada temporalmente', 423, [
                'auth' => ['Demasiados intentos fallidos. Intenta mas tarde.'],
            ], [
                'locked_until' => $user->locked_until?->toISOString(),
            ]);
        }

        if (!Hash::check($request->password, $user->password)) {
            $this->registerFailedAttempt($user);
            return $this->invalidCredentialsResponse();
        }

        if (!$user->hasTwoFactorEnabled()) {
            $this->clearSecurityCounters($user, $request->ip());
            $token = $this->createFullAccessToken($user);

            return $this->successResponse([
                'token' => $token,
                'user' => $this->serializeUser($user),
            ]);
        }

        $code = $request->input('two_factor_code');
        $recoveryCode = $request->input('recovery_code');

        if (!$code && !$recoveryCode) {
            return $this->errorResponse('Validation error', 422, [
                'two_factor_code' => ['El codigo 2FA es obligatorio'],
            ]);
        }

        $twoFactorValidated = $code
            ? $this->twoFactorService->verifyCode($user->two_factor_secret, $code)
            : $this->twoFactorService->consumeRecoveryCode($user, (string) $recoveryCode);

        if (!$twoFactorValidated) {
            $this->registerFailedAttempt($user);

            return $this->errorResponse('Codigo 2FA invalido', 422, [
                'two_factor_code' => ['El codigo 2FA o recovery code es invalido'],
            ]);
        }

        $this->clearSecurityCounters($user, $request->ip());
        $token = $this->createFullAccessToken($user);

        return $this->successResponse([
            'token' => $token,
            'user' => $this->serializeUser($user),
        ]);
    }

    public function me(Request $request)
    {
        return $this->successResponse($this->serializeUser($request->user()));
    }

    public function features(Request $request, PlanPermissionService $planPermissionService)
    {
        $request->user()->loadMissing('tenant.plan');

        return $this->successResponse($planPermissionService->featureFlagsForTenant($request->user()->tenant));
    }

    public function updateMe(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => ['sometimes', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => 'sometimes|string|min:8',
            'avatar_url' => 'sometimes|nullable|url|max:2048',
        ]);

        $payload = [];

        foreach (['name', 'email', 'avatar_url'] as $field) {
            if (array_key_exists($field, $validated)) {
                $payload[$field] = $validated[$field];
            }
        }

        if (array_key_exists('password', $validated)) {
            $payload['password'] = Hash::make($validated['password']);
        }

        if ($payload !== []) {
            $user->forceFill($payload)->save();
        }

        return $this->successResponse($this->serializeUser($user->fresh()), 200, 'Perfil actualizado');
    }

    public function init(Request $request)
    {
        return $this->successResponse($this->platformInitService->init($request->user()));
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return $this->successResponse(null, 200, 'Sesion cerrada correctamente');
    }

    public function forgotPassword(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email',
        ]);

        try {
            if (app()->bound('auth.password')) {
                $status = Password::sendResetLink([
                    'email' => $validated['email'],
                ]);

                Log::info('Password reset link requested', [
                    'email' => $validated['email'],
                    'status' => $status,
                    'mailer' => config('mail.default'),
                    'from' => config('mail.from.address'),
                ]);
            } else {
                Log::warning('Password reset service is not bound', [
                    'email' => $validated['email'],
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Password reset email could not be sent', [
                'email' => $validated['email'],
                'mailer' => config('mail.default'),
                'from' => config('mail.from.address'),
                'error' => $e->getMessage(),
            ]);

            report($e);
        }

        return $this->successResponse(null, 200, 'Si el correo existe, enviaremos un enlace de recuperacion');
    }

    public function resetPassword(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'token' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        if (!app()->bound('auth.password')) {
            return $this->errorResponse('No fue posible restablecer la contraseña', 422, [
                'token' => ['El servicio de recuperacion no esta disponible'],
            ]);
        }

        $status = Password::reset(
            $validated,
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'failed_login_attempts' => 0,
                    'locked_until' => null,
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return $this->errorResponse('No fue posible restablecer la contraseña', 422, [
                'token' => [__($status)],
            ]);
        }

        return $this->successResponse(null, 200, 'Contraseña restablecida correctamente');
    }

    public function twoFactorSetup(Request $request)
    {
        $user = $request->user();
        $token = $user->currentAccessToken();

        if (!$token || (!$token->can('*') && !$token->can('2fa:setup'))) {
            return $this->errorResponse('No autorizado para configurar 2FA', 403, [
                'two_factor' => ['El token actual no permite configurar 2FA'],
            ]);
        }

        if ($user->hasTwoFactorEnabled()) {
            return $this->successResponse([
                'already_enabled' => true,
                'user' => $this->serializeUser($user),
            ], 200, '2FA ya esta configurado');
        }

        $secret = $user->two_factor_secret ?: $this->twoFactorService->generateSecret();

        $user->forceFill([
            'two_factor_secret' => $secret,
        ])->save();

        return $this->successResponse([
            'secret' => $secret,
            'otpauth_url' => $this->twoFactorService->otpAuthUrl($user, $secret),
            'user' => $this->serializeUser($user),
        ], 200, 'Escanea el codigo con tu autenticador');
    }

    public function confirmTwoFactor(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string',
        ]);

        $user = $request->user();
        $token = $user->currentAccessToken();

        if (!$token || (!$token->can('*') && !$token->can('2fa:setup'))) {
            return $this->errorResponse('No autorizado para confirmar 2FA', 403, [
                'two_factor' => ['El token actual no permite confirmar 2FA'],
            ]);
        }

        if (empty($user->two_factor_secret)) {
            return $this->errorResponse('Primero debes generar un secreto 2FA', 422, [
                'two_factor' => ['No existe un secreto 2FA pendiente de confirmacion'],
            ]);
        }

        if (!$this->twoFactorService->verifyCode($user->two_factor_secret, $validated['code'])) {
            return $this->errorResponse('Validation error', 422, [
                'code' => ['Codigo 2FA invalido'],
            ]);
        }

        $recoveryCodes = $this->twoFactorService->generateRecoveryCodes();

        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => $recoveryCodes['hashed'],
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        $token->delete();
        $fullAccessToken = $this->createFullAccessToken($user);

        return $this->successResponse([
            'token' => $fullAccessToken,
            'recovery_codes' => $recoveryCodes['plain'],
            'user' => $this->serializeUser($user),
        ], 200, '2FA configurado correctamente');
    }

    public function regenerateRecoveryCodes(Request $request)
    {
        $user = $request->user();

        if (!$user->hasTwoFactorEnabled()) {
            return $this->errorResponse('2FA no esta habilitado', 422, [
                'two_factor' => ['Debes tener 2FA habilitado para regenerar recovery codes'],
            ]);
        }

        $recoveryCodes = $this->twoFactorService->generateRecoveryCodes();

        $user->forceFill([
            'two_factor_recovery_codes' => $recoveryCodes['hashed'],
        ])->save();

        return $this->successResponse([
            'recovery_codes' => $recoveryCodes['plain'],
        ], 200, 'Recovery codes regenerados');
    }

    public function disableTwoFactor(Request $request)
    {
        $validated = $request->validate([
            'password' => 'required|string',
        ]);

        $user = $request->user();

        if (!Hash::check($validated['password'], $user->password)) {
            return $this->errorResponse('Validation error', 422, [
                'password' => ['La contraseña no es correcta'],
            ]);
        }

        if ($user->hasTwoFactorEnabled()) {
            $user = $this->twoFactorService->disableForUser($user);
        }

        return $this->successResponse([
            'user' => $this->serializeUser($user),
        ], 200, '2FA desactivado correctamente');
    }

    private function serializeUser(User $user): array
    {
        return [
            'uid' => $user->uid,
            'name' => $user->name,
            'email' => $user->email,
            'tenant_uid' => $user->tenant?->uid,
            'is_platform_admin' => (bool) $user->is_platform_admin,
            'avatar_url' => $user->avatar_url,
            'two_factor_enabled' => $user->hasTwoFactorEnabled(),
            'locked_until' => $user->locked_until?->toISOString(),
        ];
    }

    private function createSetupToken(User $user): string
    {
        return $user->createToken(
            '2fa-setup',
            $this->tenantAbilities($user, ['2fa:setup']),
            now()->addMinutes(10)
        )->plainTextToken;
    }

    private function createFullAccessToken(User $user): string
    {
        return $user->createToken(
            'api-token',
            $this->tenantAbilities($user, ['access:full']),
            now()->addHours(12)
        )->plainTextToken;
    }

    private function tenantAbilities(User $user, array $abilities): array
    {
        if ($user->is_platform_admin && $user->tenant_id === null) {
            return array_merge($abilities, [
                'platform:admin',
            ]);
        }

        return array_merge($abilities, [
            'tenant:' . $user->tenant?->uid,
        ]);
    }

    private function invalidCredentialsResponse()
    {
        return $this->errorResponse('Credenciales incorrectas', 401, [
            'credentials' => ['Credenciales incorrectas'],
        ]);
    }

    private function registerFailedAttempt(User $user): void
    {
        $attempts = (int) $user->failed_login_attempts + 1;
        $payload = [
            'failed_login_attempts' => $attempts,
        ];

        if ($attempts >= 5) {
            $payload['locked_until'] = now()->addMinutes(15);
            $payload['failed_login_attempts'] = 0;
        }

        $user->forceFill($payload)->save();
    }

    private function clearSecurityCounters(User $user, ?string $ip): void
    {
        $user->forceFill([
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'last_login_at' => now(),
            'last_login_ip' => $ip,
        ])->save();
    }
}
