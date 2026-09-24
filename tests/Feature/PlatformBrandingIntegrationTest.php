<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlatformBrandingIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_admin_can_update_public_branding(): void
    {
        Storage::fake('public');
        $admin = $this->authenticatePlatformAdmin();

        $this->getJson('/api/platform/branding')
            ->assertOk()
            ->assertJsonPath('data.name', config('app.name'))
            ->assertJsonPath('data.logo_light_url', null)
            ->assertJsonPath('data.logo_dark_url', null)
            ->assertJsonPath('data.favicon_url', null);

        $response = $this->post('/api/admin/branding', [
            'name' => 'Mi CRM',
            'logo_light' => $this->png('logo-light.png'),
            'logo_dark' => $this->png('logo-dark.png'),
            'favicon' => UploadedFile::fake()->createWithContent(
                'favicon.ico',
                "\x00\x00\x01\x00\x01\x00\x01\x01\x00\x00\x01\x00\x20\x00"
            ),
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.name', 'Mi CRM');

        $logoLightUrl = $response->json('data.logo_light_url');
        $logoDarkUrl = $response->json('data.logo_dark_url');
        $faviconUrl = $response->json('data.favicon_url');
        $this->assertNotNull($logoLightUrl);
        $this->assertNotNull($logoDarkUrl);
        $this->assertNotNull($faviconUrl);

        foreach ([$logoLightUrl, $logoDarkUrl, $faviconUrl] as $url) {
            Storage::disk('public')->assertExists(str($url)->after('/storage/')->toString());
        }

        $this->getJson('/api/platform/branding')
            ->assertOk()
            ->assertJsonPath('data.name', 'Mi CRM')
            ->assertJsonPath('data.logo_light_url', $logoLightUrl)
            ->assertJsonPath('data.logo_dark_url', $logoDarkUrl)
            ->assertJsonPath('data.favicon_url', $faviconUrl);

        Sanctum::actingAs($admin, ['access:full', 'platform:admin']);
        $this->getJson('/api/auth/init')
            ->assertOk()
            ->assertJsonPath('data.branding.name', 'Mi CRM')
            ->assertJsonPath('data.branding.logo_light_url', $logoLightUrl)
            ->assertJsonPath('data.branding.logo_dark_url', $logoDarkUrl)
            ->assertJsonPath('data.branding.favicon_url', $faviconUrl);

        $this->postJson('/api/admin/branding', [
            'remove_logo_light' => true,
            'remove_logo_dark' => true,
            'remove_favicon' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Mi CRM')
            ->assertJsonPath('data.logo_light_url', null)
            ->assertJsonPath('data.logo_dark_url', null)
            ->assertJsonPath('data.favicon_url', null);

        foreach ([$logoLightUrl, $logoDarkUrl, $faviconUrl] as $url) {
            Storage::disk('public')->assertMissing(str($url)->after('/storage/')->toString());
        }
    }

    private function png(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=')
        );
    }

    private function authenticatePlatformAdmin(): User
    {
        $permission = Permission::query()->firstOrCreate(
            ['key' => 'admin.tenants.manage'],
            [
                'module' => 'admin',
                'action' => 'tenants.manage',
                'description' => 'Administrar tenants',
                'scope' => Permission::SCOPE_PLATFORM,
            ]
        );

        $admin = User::withoutGlobalScopes()->create([
            'name' => 'Platform Admin',
            'email' => 'branding-admin@example.test',
            'password' => bcrypt('secret123'),
            'tenant_id' => null,
            'is_platform_admin' => true,
        ]);
        $admin->permissions()->sync([$permission->getKey()]);

        Sanctum::actingAs($admin, ['access:full', 'platform:admin']);

        return $admin;
    }
}
