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

    public function update(array $data, array $files = []): array
    {
        $files = collect($files)->filter(fn ($file) => $file instanceof UploadedFile)->all();
        $validated = Validator::make(array_merge($data, $files), [
            'name' => 'sometimes|string|min:1|max:120',
            'logo_light' => 'nullable|image|mimes:png,jpg,jpeg,webp|max:2048',
            'logo_dark' => 'nullable|image|mimes:png,jpg,jpeg,webp|max:2048',
            'favicon' => 'nullable|file|mimes:png,ico|max:512',
            'remove_logo_light' => 'sometimes|boolean',
            'remove_logo_dark' => 'sometimes|boolean',
            'remove_favicon' => 'sometimes|boolean',
        ])->validate();

        $removeRequested = collect(['remove_logo_light', 'remove_logo_dark', 'remove_favicon'])
            ->contains(fn (string $key) => (bool) ($validated[$key] ?? false));

        if (! array_key_exists('name', $validated) && $files === [] && ! $removeRequested) {
            throw ValidationException::withMessages([
                'branding' => ['Debes enviar name, alguno de los logos, favicon o una opcion de eliminacion'],
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
        $disk = $settings->assets_disk ?: 'public';
        $payload = [];
        $pathsToDelete = [];

        if (array_key_exists('name', $validated)) {
            $payload['brand_name'] = trim($validated['name']);
        }

        $assets = [
            'logo_light' => ['path' => 'logo_light_path', 'remove' => 'remove_logo_light'],
            'logo_dark' => ['path' => 'logo_dark_path', 'remove' => 'remove_logo_dark'],
            'favicon' => ['path' => 'favicon_path', 'remove' => 'remove_favicon'],
        ];

        foreach ($assets as $input => $asset) {
            $oldPath = $settings->{$asset['path']};

            if (isset($files[$input])) {
                $newPath = $files[$input]->store('platform/branding', 'public');

                if (! $newPath) {
                    throw ValidationException::withMessages([
                        $input => ['No fue posible almacenar el archivo'],
                    ]);
                }

                $payload['assets_disk'] = 'public';
                $payload[$asset['path']] = $newPath;

                if ($oldPath) {
                    $pathsToDelete[] = [$disk, $oldPath];
                }
            } elseif ($validated[$asset['remove']] ?? false) {
                $payload[$asset['path']] = null;

                if ($oldPath) {
                    $pathsToDelete[] = [$disk, $oldPath];
                }
            }
        }

        $settings->update($payload);

        foreach ($pathsToDelete as [$oldDisk, $oldPath]) {
            Storage::disk($oldDisk)->delete($oldPath);
        }

        return $this->serialize($settings->fresh());
    }

    private function serialize(?PlatformSetting $settings): array
    {
        return [
            'name' => $settings?->brand_name ?: config('app.name', 'Vende Mas'),
            'logo_light_url' => $this->assetUrl($settings, 'logo_light_path'),
            'logo_dark_url' => $this->assetUrl($settings, 'logo_dark_path'),
            'favicon_url' => $this->assetUrl($settings, 'favicon_path'),
        ];
    }

    private function assetUrl(?PlatformSetting $settings, string $pathColumn): ?string
    {
        $path = $settings?->{$pathColumn};

        return $path ? Storage::disk($settings->assets_disk ?: 'public')->url($path) : null;
    }
}
