<?php

namespace App\Services;

use App\Models\Permission;
use App\Models\User;

class PlatformInitService
{
    private const MODULE_FEATURES = [
        'inventory' => 'inventory',
        'reports' => 'reports',
    ];

    private const ITEM_FEATURES = [
        'sales' => [
            'multi-currency' => 'multicurrency',
        ],
        'settings' => [
            'custom-fields' => 'custom_fields',
        ],
    ];

    private const MODULES = [
        'dashboard' => ['label' => 'Dashboard', 'permissions' => ['dashboard.read']],
        'inventory' => ['label' => 'Inventario', 'permissions' => ['inventory.read', 'inventory.manage', 'inventory.reserve', 'inventory.report']],
        'sales' => ['label' => 'Ventas', 'permissions' => ['opportunities.read', 'opportunities.manage', 'quotes.read', 'quotes.manage', 'quotations.read', 'quotations.manage', 'products.read', 'products.manage']],
        'reports' => ['label' => 'Reportes', 'permissions' => ['reports.read', 'finance.read', 'inventory.report']],
        'crm' => ['label' => 'CRM', 'permissions' => ['accounts.read', 'accounts.create', 'accounts.update', 'accounts.delete', 'contacts.read', 'contacts.create', 'contacts.update', 'contacts.delete', 'crm-entities.read', 'crm-entities.create', 'crm-entities.update']],
        'projects' => ['label' => 'Proyectos', 'permissions' => ['projects.read', 'projects.manage']],
        'incentives' => ['label' => 'Incentivos y Comisiones', 'permissions' => ['commissions.read', 'commissions.manage']],
        'partners' => ['label' => 'Canales & Partners', 'permissions' => ['partners.read', 'partners.manage', 'partners.opportunities.read', 'partners.opportunities.manage', 'partners.resources.read', 'partners.resources.manage']],
        'intelligence' => ['label' => 'Inteligencia Competitiva', 'permissions' => ['competitive-intelligence.read', 'competitive-intelligence.manage', 'competitive-intelligence.report']],
        'automation' => ['label' => 'Automatizacion', 'permissions' => ['automation.read', 'automation.create', 'automation.update', 'automation.delete', 'segments.read', 'segments.manage']],
        'settings' => ['label' => 'Configuracion', 'permissions' => ['settings.manage', 'users.manage', 'custom-fields.manage', 'documents.read', 'documents.manage', 'tags.manage', 'teams.read', 'teams.manage']],
        'tasks' => ['label' => 'Tareas', 'permissions' => ['tasks.read', 'tasks.create', 'tasks.update', 'tasks.delete']],
        'expenses' => ['label' => 'Gastos', 'permissions' => ['expenses.read', 'expenses.manage', 'expenses.report']],
        'purchases' => ['label' => 'Compras', 'permissions' => ['purchases.read', 'purchases.manage']],
    ];

    private const MODULE_ITEMS = [
        'dashboard' => [
            ['key' => 'dashboard', 'label' => 'Dashboard', 'permissions' => ['dashboard.read']],
        ],
        'inventory' => [
            ['key' => 'products', 'label' => 'Productos', 'permissions' => ['inventory.read', 'products.read']],
            ['key' => 'warehouses', 'label' => 'Bodegas', 'permissions' => ['inventory.read', 'inventory.manage']],
            ['key' => 'stock', 'label' => 'Stock', 'permissions' => ['inventory.read', 'inventory.reserve', 'inventory.manage']],
        ],
        'sales' => [
            ['key' => 'pipeline', 'label' => 'Pipeline', 'permissions' => ['opportunities.read', 'opportunities.manage']],
            ['key' => 'catalog', 'label' => 'Catalogo Comercial', 'permissions' => ['products.read', 'products.manage']],
            ['key' => 'finance-dashboard', 'label' => 'Dashboard Financiero', 'permissions' => ['finance.read']],
            ['key' => 'quotations', 'label' => 'Cotizaciones', 'permissions' => ['quotations.read', 'quotations.create', 'quotations.update']],
            ['key' => 'invoices', 'label' => 'Facturas', 'permissions' => ['finance.read', 'finance.manage']],
            ['key' => 'credit-rules', 'label' => 'Reglas de Credito', 'permissions' => ['finance.manage']],
            ['key' => 'multi-currency', 'label' => 'Multimoneda', 'permissions' => ['finance.read', 'finance.manage']],
        ],
        'reports' => [
            ['key' => 'inventory-report', 'label' => 'Reporte Inventario', 'permissions' => ['reports.read', 'inventory.report']],
            ['key' => 'sales-report', 'label' => 'Reporte Ventas', 'permissions' => ['reports.read']],
        ],
        'incentives' => [
            ['key' => 'plans', 'label' => 'Planes', 'permissions' => ['commissions.read', 'commissions.manage']],
            ['key' => 'assignment', 'label' => 'Asignacion', 'permissions' => ['commissions.manage']],
            ['key' => 'dashboard', 'label' => 'Dashboard', 'permissions' => ['commissions.read']],
            ['key' => 'simulator', 'label' => 'Simulador', 'permissions' => ['commissions.read']],
            ['key' => 'history', 'label' => 'Historial', 'permissions' => ['commissions.read']],
        ],
        'projects' => [
            ['key' => 'projects', 'label' => 'Proyectos', 'permissions' => ['projects.read', 'projects.manage']],
        ],
        'settings' => [
            ['key' => 'users', 'label' => 'Usuarios', 'permissions' => ['users.manage']],
            ['key' => 'roles', 'label' => 'Roles', 'permissions' => ['users.manage']],
            ['key' => 'teams', 'label' => 'Equipos', 'permissions' => ['teams.read', 'teams.manage']],
            ['key' => 'custom-fields', 'label' => 'Custom Fields', 'permissions' => ['custom-fields.manage']],
            ['key' => 'localization', 'label' => 'Localizacion', 'permissions' => ['settings.manage']],
            ['key' => 'tags', 'label' => 'Tags', 'permissions' => ['tags.manage']],
            ['key' => 'document-types', 'label' => 'Tipos de Documento', 'permissions' => ['documents.read', 'documents.manage']],
        ],
        'crm' => [
            ['key' => 'contacts', 'label' => 'Directorio', 'permissions' => ['accounts.read', 'contacts.read', 'crm-entities.read']],
            ['key' => 'segments', 'label' => 'Segmentacion', 'permissions' => ['segments.read', 'segments.manage']],
            ['key' => 'schedule', 'label' => 'Agenda', 'permissions' => ['activities.read']],
        ],
        'partners' => [
            ['key' => 'partners', 'label' => 'Partners', 'permissions' => ['partners.read', 'partners.manage']],
            ['key' => 'opportunities', 'label' => 'Oportunidades', 'permissions' => ['partners.opportunities.read', 'partners.opportunities.manage']],
            ['key' => 'portal', 'label' => 'Portal', 'permissions' => ['partners.resources.read', 'partners.resources.manage']],
        ],
        'intelligence' => [
            ['key' => 'battlecards', 'label' => 'Battlecards', 'permissions' => ['competitive-intelligence.read', 'competitive-intelligence.manage']],
            ['key' => 'lost-reasons', 'label' => 'Razones de Perdida', 'permissions' => ['competitive-intelligence.read', 'competitive-intelligence.report']],
        ],
        'automation' => [
            ['key' => 'rules', 'label' => 'Reglas', 'permissions' => ['automation.read', 'automation.create', 'automation.update', 'automation.delete']],
            ['key' => 'assignment', 'label' => 'Asignacion', 'permissions' => ['automation.read', 'automation.create', 'automation.update', 'automation.delete']],
        ],
        'tasks' => [
            ['key' => 'tasks', 'label' => 'Tareas', 'permissions' => ['tasks.read', 'tasks.create', 'tasks.update', 'tasks.delete']],
        ],
        'expenses' => [
            ['key' => 'expenses', 'label' => 'Gastos', 'permissions' => ['expenses.read', 'expenses.manage']],
            ['key' => 'expense-report', 'label' => 'Reportes de Gastos', 'permissions' => ['expenses.report']],
        ],
        'purchases' => [
            ['key' => 'orders', 'label' => 'Ordenes de Compra', 'permissions' => ['purchases.read', 'purchases.manage']],
            ['key' => 'payables', 'label' => 'Cuentas por Pagar', 'permissions' => ['purchases.read']],
        ],
    ];

    public function __construct(private readonly PlanPermissionService $planPermissionService)
    {
    }

    public function init(User $user): array
    {
        $user->loadMissing(['tenant.plan', 'tenant.currency', 'roles', 'permissions', 'adminRoles']);
        $isPlatformSession = $user->is_platform_admin && $user->tenant_id === null;
        $effectivePermissions = $isPlatformSession
            ? collect()
            : $user->effectivePermissions();

        if (! $isPlatformSession && $user->tenant) {
            $effectivePermissions = $this->planPermissionService->filterPermissionsForTenant($effectivePermissions, $user->tenant);
        }

        $effectivePermissionKeys = $effectivePermissions->pluck('key')->values()->all();
        $role = $user->roles->first()?->key ?? ($user->is_platform_admin ? 'platform-admin' : 'user');

        // Si es superadmin, incluir permisos de admin_roles
        $adminRolesData = [];
        $adminPermissions = [];
        if ($isPlatformSession) {
            $adminRolesData = $user->adminRoles->map(fn ($r) => [
                'uid' => $r->uid,
                'name' => $r->name,
                'key' => $r->key,
                'is_system' => $r->is_system,
            ])->values()->all();

            $adminPermissions = $user->effectiveAdminPermissions()->pluck('key')->values()->all();
        }

        $featureFlags = (! $user->is_platform_admin && $user->tenant?->plan)
            ? $this->planPermissionService->featureFlagsForTenant($user->tenant)
            : null;

        return [
            'user' => [
                'uid' => $user->uid,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $role,
                'is_platform_admin' => (bool) $user->is_platform_admin,
                'avatar_url' => $user->avatar_url,
                'admin_roles' => $adminRolesData,
            ],
            'tenant' => [
                'uid' => $user->tenant?->uid,
                'name' => $user->tenant?->name,
                'plan' => $user->tenant?->plan?->tier ?? $user->tenant?->plan?->name ?? 'free',
                'logo_url' => null,
            ],
            'modules' => $this->modules($effectivePermissionKeys, (bool) $user->is_platform_admin, $featureFlags),
            'features' => $featureFlags ?? $this->defaultFeatureFlags(),
            'localization' => $this->localization($user),
            'permissions' => $this->permissionsPayload($effectivePermissionKeys),
            'admin_permissions' => $adminPermissions,
        ];
    }

    public function localization(User $user): array
    {
        $tenant = $user->tenant;
        $currency = $tenant?->currency;
        $currencyCode = $currency?->code ?? 'USD';

        return [
            'currency' => $currencyCode,
            'currency_symbol' => $currency?->symbol ?? '$',
            'locale' => $tenant?->locale ?? $this->localeFor($currencyCode),
            'timezone' => $tenant?->timezone ?? 'UTC',
            'date_format' => $this->frontendDateFormat($tenant?->date_format ?? 'Y-m-d'),
            'language' => 'es',
            'user_timezone' => $user->timezone ?? null,
        ];
    }

    private function modules(array $effectivePermissionKeys, bool $forceDisabled = false, ?array $featureFlags = null): array
    {
        return collect(self::MODULES)
            ->map(function (array $module, string $key) use ($effectivePermissionKeys, $forceDisabled, $featureFlags) {
                $actions = collect($module['permissions'])
                    ->filter(fn (string $permission) => in_array($permission, $effectivePermissionKeys, true))
                    ->map(fn (string $permission) => str($permission)->afterLast('.')->toString())
                    ->unique()
                    ->values()
                    ->all();
                $featureEnabled = $this->isModuleFeatureEnabled($key, $featureFlags);
                $disabled = $forceDisabled || ! $featureEnabled;

                return [
                    'key' => $key,
                    'label' => $module['label'],
                    'enabled' => $disabled ? false : !empty($actions),
                    'permissions' => $disabled ? [] : $actions,
                    'items' => $this->moduleItems($key, $effectivePermissionKeys, $disabled, $featureFlags),
                ];
            })
            ->values()
            ->all();
    }

    private function permissionsPayload(array $effectivePermissionKeys): array
    {
        $effective = collect($effectivePermissionKeys)
            ->unique()
            ->sort()
            ->values();

        return [
            'effective' => $effective->all(),
            'modules' => $effective
                ->groupBy(fn (string $permission) => str($permission)->before('.')->toString())
                ->map(fn ($permissions) => $permissions
                    ->map(fn (string $permission) => str($permission)->after('.')->toString())
                    ->unique()
                    ->values()
                    ->all())
                ->all(),
        ];
    }

    private function moduleItems(string $moduleKey, array $effectivePermissionKeys, bool $forceDisabled = false, ?array $featureFlags = null): array
    {
        return collect(self::MODULE_ITEMS[$moduleKey] ?? [])
            ->map(function (array $item) use ($moduleKey, $effectivePermissionKeys, $forceDisabled, $featureFlags) {
                $matchedPermissions = collect($item['permissions'])
                    ->filter(fn (string $permission) => in_array($permission, $effectivePermissionKeys, true))
                    ->values();
                $featureEnabled = $this->isItemFeatureEnabled($moduleKey, $item['key'], $featureFlags);
                $disabled = $forceDisabled || ! $featureEnabled;

                return [
                    'key' => $item['key'],
                    'label' => $item['label'],
                    'enabled' => $disabled ? false : $matchedPermissions->isNotEmpty(),
                    'permissions' => $disabled ? [] : $matchedPermissions
                        ->map(fn (string $permission) => str($permission)->afterLast('.')->toString())
                        ->unique()
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();
    }

    private function isModuleFeatureEnabled(string $moduleKey, ?array $featureFlags): bool
    {
        if ($featureFlags === null || ! isset(self::MODULE_FEATURES[$moduleKey])) {
            return true;
        }

        return (bool) ($featureFlags[self::MODULE_FEATURES[$moduleKey]] ?? false);
    }

    private function isItemFeatureEnabled(string $moduleKey, string $itemKey, ?array $featureFlags): bool
    {
        if ($featureFlags === null || ! isset(self::ITEM_FEATURES[$moduleKey][$itemKey])) {
            return true;
        }

        return (bool) ($featureFlags[self::ITEM_FEATURES[$moduleKey][$itemKey]] ?? false);
    }

    private function defaultFeatureFlags(): array
    {
        return [
            'inventory' => true,
            'reports' => true,
            'multicurrency' => true,
            'custom_fields' => true,
        ];
    }

    private function localeFor(string $currencyCode): string
    {
        return match ($currencyCode) {
            'ARS' => 'es-AR',
            'COP' => 'es-CO',
            'MXN' => 'es-MX',
            default => 'es-ES',
        };
    }

    private function frontendDateFormat(string $format): string
    {
        return match ($format) {
            'Y-m-d' => 'YYYY-MM-DD',
            'd/m/Y' => 'DD/MM/YYYY',
            'm/d/Y' => 'MM/DD/YYYY',
            default => $format,
        };
    }
}
