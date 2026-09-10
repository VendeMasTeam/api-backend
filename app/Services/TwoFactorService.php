<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class TwoFactorService
{
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function generateSecret(int $length = 32): string
    {
        $secret = '';
        $alphabet = self::BASE32_ALPHABET;
        $maxIndex = strlen($alphabet) - 1;

        for ($i = 0; $i < $length; $i++) {
            $secret .= $alphabet[random_int(0, $maxIndex)];
        }

        return $secret;
    }

    public function otpAuthUrl(User $user, string $secret): string
    {
        $issuer = rawurlencode(config('app.name', 'API Backend'));
        $label = rawurlencode(($user->email ?? $user->uid) . '@' . ($user->tenant?->name ?? 'tenant'));

        return "otpauth://totp/{$issuer}:{$label}?secret={$secret}&issuer={$issuer}&algorithm=SHA1&digits=6&period=30";
    }

    public function verifyCode(string $secret, string $code, int $window = 1): bool
    {
        $normalizedCode = preg_replace('/\s+/', '', $code);

        if (!preg_match('/^\d{6}$/', $normalizedCode)) {
            return false;
        }

        $timeSlice = (int) floor(time() / 30);

        for ($offset = -$window; $offset <= $window; $offset++) {
            if (hash_equals($this->totp($secret, $timeSlice + $offset), $normalizedCode)) {
                return true;
            }
        }

        return false;
    }

    public function currentCode(string $secret): string
    {
        return $this->totp($secret, (int) floor(time() / 30));
    }

    public function generateRecoveryCodes(int $count = 8): array
    {
        $plain = [];
        $hashed = [];

        for ($i = 0; $i < $count; $i++) {
            $code = strtoupper(Str::random(4) . '-' . Str::random(4));
            $plain[] = $code;
            $hashed[] = Hash::make($code);
        }

        return [
            'plain' => $plain,
            'hashed' => $hashed,
        ];
    }

    public function consumeRecoveryCode(User $user, string $code): bool
    {
        $storedCodes = $user->two_factor_recovery_codes ?? [];

        foreach ($storedCodes as $index => $hashedCode) {
            if (!Hash::check($code, $hashedCode)) {
                continue;
            }

            unset($storedCodes[$index]);
            $user->forceFill([
                'two_factor_recovery_codes' => array_values($storedCodes),
            ])->save();

            return true;
        }

        return false;
    }

    public function disableForUser(User $user): User
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
        ])->save();

        return $user->refresh();
    }

    private function totp(string $secret, int $timeSlice): string
    {
        $secretKey = $this->base32Decode($secret);
        $time = pack('N*', 0, $timeSlice);
        $hash = hash_hmac('sha1', $time, $secretKey, true);
        $offset = ord(substr($hash, -1)) & 0x0F;
        $truncatedHash = substr($hash, $offset, 4);
        $value = unpack('N', $truncatedHash)[1] & 0x7FFFFFFF;

        return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private function base32Decode(string $secret): string
    {
        $secret = strtoupper($secret);
        $secret = preg_replace('/[^A-Z2-7]/', '', $secret);

        $binaryString = '';
        $alphabet = array_flip(str_split(self::BASE32_ALPHABET));

        foreach (str_split($secret) as $character) {
            $binaryString .= str_pad(decbin($alphabet[$character]), 5, '0', STR_PAD_LEFT);
        }

        $decoded = '';

        foreach (str_split($binaryString, 8) as $byte) {
            if (strlen($byte) < 8) {
                continue;
            }

            $decoded .= chr(bindec($byte));
        }

        return $decoded;
    }
}
