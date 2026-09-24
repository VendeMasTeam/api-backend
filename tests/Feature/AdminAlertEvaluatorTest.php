<?php

namespace Tests\Feature;

use App\Models\AdminAlertRule;
use App\Models\Permission;
use App\Models\SystemLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminAlertEvaluatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_can_create_structured_alert_contract(): void
    {
        $this->authenticateSuperadmin(['admin.alerts.manage']);

        $response = $this->postJson('/api/admin/telemetry/alerts', [
            'nombre' => 'Errores criticos',
            'metric' => 'errores',
            'operator' => '>',
            'value' => 2,
            'period' => '1h',
            'canales' => ['EMAIL', 'SLACK'],
            'estado' => 'ACTIVO',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.nombre', 'Errores criticos')
            ->assertJsonPath('data.metric', 'errores')
            ->assertJsonPath('data.operator', '>')
            ->assertJsonPath('data.value', 2)
            ->assertJsonPath('data.period', '1h')
            ->assertJsonPath('data.canales.0', 'EMAIL')
            ->assertJsonPath('data.estado', 'ACTIVO');

        $this->assertDatabaseHas('admin_alert_rules', [
            'name' => 'Errores criticos',
            'metric' => 'errores',
            'operator' => '>',
            'period' => '1h',
            'is_active' => true,
        ]);
    }

    public function test_alert_evaluation_uses_real_logs_and_updates_last_triggered_at(): void
    {
        Mail::fake();
        Http::fake();

        $this->authenticateSuperadmin(['admin.alerts.manage']);
        $tenant = Tenant::query()->create([
            'name' => 'Tenant Logs',
            'status' => 'ACTIVO',
            'is_active' => true,
        ]);

        foreach (range(1, 3) as $index) {
            SystemLog::withoutGlobalScopes()->create([
                'tenant_id' => $tenant->getKey(),
                'level' => 'error',
                'message' => 'Error '.$index,
                'created_at' => now()->subMinutes(10),
                'updated_at' => now()->subMinutes(10),
            ]);
        }

        $rule = AdminAlertRule::query()->create([
            'name' => 'Errores criticos',
            'condition_text' => 'errores > 2 en 1h',
            'metric' => 'errores',
            'operator' => '>',
            'value' => 2,
            'period' => '1h',
            'channels' => ['EMAIL', 'SLACK', 'PUSH'],
            'is_active' => true,
        ]);

        $this->postJson('/api/admin/telemetry/alerts/evaluate')
            ->assertOk()
            ->assertJsonPath('data.evaluated', 1)
            ->assertJsonPath('data.triggered', 1)
            ->assertJsonPath('data.results.0.uid', $rule->uid)
            ->assertJsonPath('data.results.0.actual_value', 3)
            ->assertJsonPath('data.results.0.triggered', true);

        $this->assertNotNull($rule->fresh()->last_triggered_at);
    }

    public function test_alert_evaluation_ignores_inactive_and_unmet_rules(): void
    {
        $this->authenticateSuperadmin(['admin.alerts.manage']);
        $tenant = Tenant::query()->create([
            'name' => 'Tenant Logs',
            'status' => 'ACTIVO',
            'is_active' => true,
        ]);

        SystemLog::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->getKey(),
            'level' => 'warning',
            'message' => 'Warning',
            'created_at' => now()->subMinutes(10),
            'updated_at' => now()->subMinutes(10),
        ]);

        AdminAlertRule::query()->create([
            'name' => 'Warnings altos',
            'condition_text' => 'warnings > 5 en 1h',
            'metric' => 'warnings',
            'operator' => '>',
            'value' => 5,
            'period' => '1h',
            'channels' => ['EMAIL'],
            'is_active' => true,
        ]);
        AdminAlertRule::query()->create([
            'name' => 'Inactiva',
            'condition_text' => 'warnings > 0 en 1h',
            'metric' => 'warnings',
            'operator' => '>',
            'value' => 0,
            'period' => '1h',
            'channels' => ['EMAIL'],
            'is_active' => false,
        ]);

        $this->postJson('/api/admin/telemetry/alerts/evaluate')
            ->assertOk()
            ->assertJsonPath('data.evaluated', 1)
            ->assertJsonPath('data.triggered', 0)
            ->assertJsonPath('data.results.0.triggered', false);
    }

    private function authenticateSuperadmin(array $permissionKeys): User
    {
        foreach ($permissionKeys as $key) {
            Permission::query()->firstOrCreate(
                ['key' => $key],
                [
                    'module' => 'admin',
                    'action' => $key,
                    'description' => $key,
                    'scope' => Permission::SCOPE_PLATFORM,
                ]
            );
        }

        $admin = User::withoutGlobalScopes()->create([
            'name' => 'Super Admin',
            'email' => 'superadmin-alerts@example.test',
            'password' => bcrypt('secret123'),
            'tenant_id' => null,
            'is_platform_admin' => true,
            'two_factor_secret' => 'secret',
            'two_factor_confirmed_at' => now(),
        ]);

        $permissionIds = Permission::query()->whereIn('key', $permissionKeys)->pluck('id')->all();
        $admin->permissions()->sync($permissionIds);

        Sanctum::actingAs($admin, ['access:full', 'platform:admin']);

        return $admin;
    }
}
