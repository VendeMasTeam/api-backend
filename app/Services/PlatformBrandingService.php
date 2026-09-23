<?php

namespace App\Services;

use App\Models\PlatformSetting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PlatformBrandingService
{
    private const SETTINGS_KEY = 'branding';

    public function get(): array
    {
        $settings = PlatformSetting::query()->where('key', self::SETTINGS_KEY)->first();

        return $this->serialize($settings);
    }

    public function update(array $data, ?UploadedFile $logo = null): array
    {
        $validated = Validator::make(array_merge($data, ['logo' => $logo]), [
            'name' => 'sometimes|string|min:1|max:120',
            'logo' => 'nullable|image|mimes:png,jpg,jpeg,webp|max:2048',
            'remove_logo' => 'sometimes|boolean',
        ])->validate();

        if (! array_key_exists('name', $validated) && ! $logo && ! ($validated['remove_logo'] ?? false)) {
            throw ValidationException::withMessages([
                'branding' => ['Debes enviar name, logo o remove_logo'],
            ]);
        }

        if (array_key_exists('name', $validated) && trim($validated['name']) === '') {
            throw ValidationException::withMessages([
                'name' => ['El nombre de la plataforma no puede estar vacio'],
            ]);
        }

        $settings = PlatformSetting::query()->firstOrCreate(
            ['key' => self::SETTINGS_KEY],
            ['brand_name' => config('app.name', 'Vende Mas')]
        );
        $oldDisk = $settings->logo_disk;
        $oldPath = $settings->logo_path;
        $payload = [];

        if (array_key_exists('name', $validated)) {
            $payload['brand_name'] = trim($validated['name']);
        }

        if ($logo) {
            $logoPath = $logo->store('platform/branding', 'public');

            if (! $logoPath) {
                throw ValidationException::withMessages([
                    'logo' => ['No fue posible almacenar el logo'],
                ]);
            }

            $payload['logo_disk'] = 'public';
            $payload['logo_path'] = $logoPath;
        } elseif ($validated['remove_logo'] ?? false) {
            $payload['logo_disk'] = null;
            $payload['logo_path'] = null;
        }

        $settings->update($payload);

        if (($logo || ($validated['remove_logo'] ?? false)) && $oldDisk && $oldPath) {
            Storage::disk($oldDisk)->delete($oldPath);
        }

        return $this->serialize($settings->fresh());
    }

    private function serialize(?PlatformSetting $settings): array
    {
        return [
            'name' => $settings?->brand_name ?: config('app.name', 'Vende Mas'),
            'logo_url' => $settings?->logo_disk && $settings?->logo_path
                ? Storage::disk($settings->logo_disk)->url($settings->logo_path)
                : null,
        ];
    }
}
