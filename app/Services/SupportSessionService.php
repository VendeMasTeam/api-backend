<?php

namespace App\Services;

use App\Models\SupportSession;
use App\Models\Tenant;
use App\Models\User;

class SupportSessionService
{
    public function start(User $supportUser, Tenant $tenant, string $reason, ?string $ipAddress = null, ?string $userAgent = null): array
    {
        $impersonatedUser = $this->findImpersonatedUser($tenant);

        if (! $impersonatedUser) {
            throw new \RuntimeException('El tenant no tiene un owner activo para iniciar soporte');
        }

        $expiresAt = now()->addMinutes((int) config('support.session_ttl_minutes', 60));

        $session = SupportSession::query()->create([
            'support_user_id' => $supportUser->getKey(),
            'tenant_id' => $tenant->getKey(),
            'impersonated_user_id' => $impersonatedUser->getKey(),
            'reason' => $reason,
            'started_at' => now(),
            'expires_at' => $expiresAt,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
        ]);

        $token = $impersonatedUser->createToken('support-session', [
            'access:full',
            'tenant:' . $tenant->uid,
            'support:tenant',
            'support_session:' . $session->uid,
        ], $expiresAt);

        $session->forceFill([
            'token_id' => $token->accessToken->getKey(),
        ])->save();

        return [
            'token' => $token->plainTextToken,
            'expires_at' => $expiresAt->toISOString(),
            'session' => $this->serialize($session->fresh(['tenant', 'supportUser', 'impersonatedUser'])),
        ];
    }

    public function current(?User $user = null, mixed $token = null): ?SupportSession
    {
        if (! $user || ! $token || ! method_exists($token, 'getKey') || ! $user->tokenCan('support:tenant')) {
            return null;
        }

        return SupportSession::query()
            ->with(['tenant', 'supportUser', 'impersonatedUser'])
            ->where('token_id', $token->getKey())
            ->whereNull('ended_at')
            ->where('expires_at', '>', now())
            ->first();
    }

    public function currentPayload(?User $user = null, mixed $token = null): array
    {
        $session = $this->current($user, $token);

        if (! $session) {
            return ['active' => false];
        }

        return $this->serialize($session);
    }

    public function stop(?User $user = null, mixed $token = null): array
    {
        $session = $this->current($user, $token);

        if ($session) {
            $session->forceFill(['ended_at' => now()])->save();
        }

        if ($token && method_exists($token, 'delete')) {
            $token->delete();
        }

        return $session ? $this->serialize($session->fresh(['tenant', 'supportUser', 'impersonatedUser'])) : ['active' => false];
    }

    public function serialize(SupportSession $session): array
    {
        return [
            'active' => $session->isActive(),
            'uid' => $session->uid,
            'tenant_uid' => $session->tenant?->uid,
            'tenant_name' => $session->tenant?->name,
            'support_user_uid' => $session->supportUser?->uid,
            'support_user_name' => $session->supportUser?->name,
            'impersonated_user_uid' => $session->impersonatedUser?->uid,
            'impersonated_user_email' => $session->impersonatedUser?->email,
            'reason' => $session->reason,
            'started_at' => $session->started_at?->toISOString(),
            'expires_at' => $session->expires_at?->toISOString(),
            'ended_at' => $session->ended_at?->toISOString(),
            'mode' => 'support',
            'readonly' => true,
        ];
    }

    private function findImpersonatedUser(Tenant $tenant): ?User
    {
        return User::withoutGlobalScopes()
            ->with(['roles' => fn ($query) => $query->withoutGlobalScopes()])
            ->where('tenant_id', $tenant->getKey())
            ->where('is_platform_admin', false)
            ->where(function ($query) {
                $query->whereNull('locked_until')->orWhere('locked_until', '<=', now());
            })
            ->whereHas('roles', function ($query) use ($tenant) {
                $query
                    ->withoutGlobalScopes()
                    ->where('tenant_id', $tenant->getKey())
                    ->where('key', 'owner');
            })
            ->orderBy('id')
            ->first();
    }
}
