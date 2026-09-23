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
            ->assertJsonPath('data.logo_url', null);

        $response = $this->post('/api/admin/branding', [
            'name' => 'Mi CRM',
            'logo' => UploadedFile::fake()->createWithContent(
                'logo.png',
                base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=')
            ),
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.name', 'Mi CRM');

        $logoUrl = $response->json('data.logo_url');
        $this->assertNotNull($logoUrl);
        $logoPath = str($logoUrl)->after('/storage/')->toString();
        Storage::disk('public')->assertExists($logoPath);

        $this->getJson('/api/platform/branding')
            ->assertOk()
            ->assertJsonPath('data.name', 'Mi CRM')
            ->assertJsonPath('data.logo_url', $logoUrl);

        Sanctum::actingAs($admin, ['access:full', 'platform:admin']);
        $this->getJson('/api/auth/init')
            ->assertOk()
            ->assertJsonPath('data.branding.name', 'Mi CRM')
            ->assertJsonPath('data.branding.logo_url', $logoUrl);

        $this->postJson('/api/admin/branding', ['remove_logo' => true])
            ->assertOk()
            ->assertJsonPath('data.name', 'Mi CRM')
            ->assertJsonPath('data.logo_url', null);

        Storage::disk('public')->assertMissing($logoPath);
    }

    private function authenticatePlatformAdmin(): User
    {
        $permission = Permission::query()->firstOrCreate(
            ['key' => 'admin.tenants.manage'],
            [
                'module' => 'admin',
                'action' => 'tenants.manage',
                'description' => 'Administrar tenants',
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
