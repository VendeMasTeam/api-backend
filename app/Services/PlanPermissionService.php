<?php

namespace App\Services;

use App\Models\Permission;
use App\Models\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class PlanPermissionService
{
    private const FEATURE_KEYS = [
        'inventory' => ['inventory', 'inventario'],
        'reports' => ['reports', 'reportes'],
        'multicurrency' => ['multicurrency', 'multi-currency', 'multimoneda', 'multi-moneda'],
        'custom_fields' => ['custom-fields', 'customfields', 'campos-personalizados'],
    ];

    private const MODULE_PERMISSION_MAP = [
        'dashboard' => ['dashboard'],
        'inventario' => ['inventory', 'products', 'price-books'],
        'inventory' => ['inventory', 'products', 'price-books'],
        'ventas' => ['opportunities', 'quotations', 'products', 'finance', 'price-books'],
        'sales' => ['opportunities', 'quotations', 'products', 'finance', 'price-books'],
        'reportes' => ['reports', 'finance', 'inventory'],
        'reports' => ['reports', 'finance', 'inventory'],
        'multi-currency' => ['finance'],
        'api-publica' => ['metrics'],
        'crm' => ['accounts', 'contacts', 'relations', 'crm-entities', 'tags', 'search', 'tasks', 'interactions', 'activities', 'segments', 'documents'],
        'proyectos' => ['projects'],
        'projects' => ['projects'],
        'rh' => ['commissions'],
        'rh-comisiones' => ['commissions'],
        'comisiones' => ['commissions'],
        'incentivos-comisiones' => ['commissions'],
        'incentives' => ['commissions'],
        'partners' => ['partners'],
        'canales-partners' => ['partners'],
        'inteligencia-competitiva' => ['competitive-intelligence'],
        'intelligence' => ['competitive-intelligence'],
        'automatizacion' => ['automation', 'segments'],
        'automation' => ['automation', 'segments'],
        'configuracion' => ['settings', 'users', 'custom-fields', 'teams', 'tags', 'documents'],
        'settings' => ['settings', 'users', 'custom-fields', 'teams', 'tags', 'documents'],
        'tareas' => ['tasks'],
        'tasks' => ['tasks'],
        'gastos' => ['expenses'],
        'expenses' => ['expenses'],
        'compras' => ['purchases'],
        'purchases' => ['purchases'],
    ];

    private const CORE_MODULES = [
        'dashboard',
        'settings',
        'users',
        'custom-fields',
        'teams',
        'logs',
        'metrics',
    ];

    public function allowedModulesForTenant(Tenant $tenant): ?array
    {
        $features = $tenant->plan?->features ?? [];

        if (! is_array($features) || ! array_key_exists('modules', $features)) {
            return null;
        }

        $modules = $features['modules'];

        if (! is_array($modules)) {
            return null;
        }

        return collect($modules)
            ->flatMap(fn (string $module) => self::MODULE_PERMISSION_MAP[$this->normalizeModule($module)] ?? [$this->normalizeModule($module)])
            ->merge(self::CORE_MODULES)
            ->unique()
            ->values()
            ->all();
    }

    public function filterPermissionsForTenant(Collection $permissions, Tenant $tenant): Collection
    {
        $allowedModules = $this->allowedModulesForTenant($tenant);

        if ($allowedModules === null) {
            return $permissions;
        }

        return $permissions
            ->filter(fn (Permission $permission) => in_array($permission->module, $allowedModules, true))
            ->values();
    }

    public function assertPermissionsAllowedForTenant(Collection $permissions, Tenant $tenant): void
    {
        $allowedModules = $this->allowedModulesForTenant($tenant);

        if ($allowedModules === null) {
            return;
        }

        $denied = $permissions
            ->filter(fn (Permission $permission) => !in_array($permission->module, $allowedModules, true))
            ->pluck('key')
            ->values()
            ->all();

        if ($denied !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'permission_uids' => ['Permisos no incluidos en el plan activo: ' . implode(', ', $denied)],
            ]);
        }
    }

    public function featureFlagsForTenant(?Tenant $tenant): array
    {
        $featureKeys = array_keys(self::FEATURE_KEYS);

        if (! $tenant?->plan) {
            return array_fill_keys($featureKeys, false);
        }

        $features = $tenant->plan->features ?? [];
        $modules = collect(data_get($features, 'modules', []))
            ->filter(fn ($module) => is_string($module))
            ->map(fn (string $module) => $this->normalizeModule($module))
            ->values();

        return collect(self::FEATURE_KEYS)
            ->mapWithKeys(function (array $aliases, string $featureKey) use ($features, $modules) {
                $normalizedAliases = collect($aliases)
                    ->map(fn (string $alias) => $this->normalizeModule($alias))
                    ->unique()
                    ->values();

                $explicit = $this->explicitFeatureValue($features, $featureKey, $normalizedAliases->all());

                return [
                    $featureKey => $explicit ?? $modules->intersect($normalizedAliases)->isNotEmpty(),
                ];
            })
            ->all();
    }

    private function explicitFeatureValue(array $features, string $featureKey, array $aliases): ?bool
    {
        $candidates = collect([$featureKey, str_replace('_', '-', $featureKey), ...$aliases])
            ->unique()
            ->all();

        foreach ($candidates as $candidate) {
            foreach ([$candidate, str_replace('-', '_', $candidate)] as $key) {
                if (array_key_exists($key, $features)) {
                    return filter_var($features[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $features[$key];
                }
            }
        }

        return null;
    }

    private function normalizeModule(string $module): string
    {
        return Str::of($module)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '-')
            ->trim('-')
            ->toString();
    }
}
