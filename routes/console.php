<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\ActivityService;
use App\Services\AdminAlertEvaluatorService;
use App\Services\SearchBenchmarkService;
use App\Services\TenantSchemaService;
use App\Services\TenantDemoDataService;
use App\Models\Permission;
use Laravel\Sanctum\Sanctum;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('search:benchmark {--tenant_uid=} {--user_uid=} {--iterations=5}', function () {
    $tenantUid = $this->option('tenant_uid');
    $userUid = $this->option('user_uid');
    $iterations = (int) $this->option('iterations');
    $schemaService = app(TenantSchemaService::class);

    $tenant = $tenantUid
        ? Tenant::query()->where('uid', $tenantUid)->first()
        : Tenant::query()->first();

    if (!$tenant) {
        $this->error('No hay tenants disponibles para ejecutar el benchmark.');
        return 1;
    }

    $user = $userUid
        ? User::query()->where('uid', $userUid)->first()
        : User::query()->where('tenant_id', $tenant->getKey())->first();

    if (!$user) {
        $this->error('No hay usuarios disponibles para ejecutar el benchmark.');
        return 1;
    }

    Sanctum::actingAs($user, [
        'access:full',
        'tenant:' . $tenant->uid,
    ]);

    $results = $schemaService->runForTenant(
        $tenant,
        fn () => app(SearchBenchmarkService::class)->run($iterations)
    );

    foreach ($results as $name => $result) {
        $this->info("Scenario: {$name}");
        $this->line('  avg_ms: ' . $result['avg_ms']);
        $this->line('  min_ms: ' . $result['min_ms']);
        $this->line('  max_ms: ' . $result['max_ms']);
        $this->line('  iterations: ' . $result['iterations']);
    }

    return 0;
})->purpose('Measure search performance using representative backend scenarios');

Artisan::command('finance:sync-overdue {--tenant_uid=}', function () {
    $tenantUid = $this->option('tenant_uid');
    $schemaService = app(TenantSchemaService::class);
    $invoiceService = app(InvoiceService::class);
    $query = Tenant::query()
        ->where('is_active', true)
        ->whereIn('status', ['ACTIVO', 'TRIAL'])
        ->orderBy('id');

    if ($tenantUid) {
        $query->where('uid', $tenantUid);
    }

    $tenants = $query->get();
    $total = 0;

    foreach ($tenants as $tenant) {
        [$updated, $usingSchema] = $schemaService->runForTenant($tenant, function () use ($tenant, $invoiceService, $schemaService) {
            $result = $invoiceService->syncOverdue($tenant->getKey());

            return [
                (int) ($result['updated_invoices'] ?? 0),
                $schemaService->shouldUseSchemaMode($tenant),
            ];
        });

        $total += $updated;

        $this->line(sprintf(
            'Tenant %s [%s]: %s facturas vencidas',
            $tenant->uid,
            $usingSchema ? $tenant->schema_name : 'public',
            $updated
        ));
    }

    $this->info('Facturas vencidas sincronizadas: ' . $total);

    return 0;
})->purpose('Mark issued and partial invoices as overdue when due date has passed');

Artisan::command('activities:sync-overdue {--tenant_uid=}', function () {
    $tenantUid = $this->option('tenant_uid');
    $schemaService = app(TenantSchemaService::class);
    $activityService = app(ActivityService::class);
    $query = Tenant::query()
        ->where('is_active', true)
        ->whereIn('status', ['ACTIVO', 'TRIAL'])
        ->orderBy('id');

    if ($tenantUid) {
        $query->where('uid', $tenantUid);
    }

    $tenants = $query->get();
    $total = 0;

    foreach ($tenants as $tenant) {
        [$updated, $usingSchema] = $schemaService->runForTenant($tenant, function () use ($tenant, $activityService, $schemaService) {
            return [
                $activityService->syncOverdueStatuses($tenant->getKey()),
                $schemaService->shouldUseSchemaMode($tenant),
            ];
        });

        $total += $updated;

        $this->line(sprintf(
            'Tenant %s [%s]: %s actividades vencidas',
            $tenant->uid,
            $usingSchema ? $tenant->schema_name : 'public',
            $updated
        ));
    }

    $this->info('Actividades vencidas sincronizadas: ' . $total);

    return 0;
})->purpose('Mark pending activities as overdue when scheduled date has passed');

Artisan::command('admin-alerts:evaluate', function () {
    $result = app(AdminAlertEvaluatorService::class)->evaluateActiveRules();

    $this->info('Alertas evaluadas: ' . $result['evaluated']);
    $this->info('Alertas disparadas: ' . $result['triggered']);

    return 0;
})->purpose('Evaluate active platform telemetry alert rules');

Artisan::command('tenants:schemas:provision {--tenant_uid=} {--dry-run}', function () {
    $tenantUid = $this->option('tenant_uid');
    $dryRun = (bool) $this->option('dry-run');
    $schemaService = app(TenantSchemaService::class);

    $query = Tenant::query()->orderBy('id');

    if ($tenantUid) {
        $query->where('uid', $tenantUid);
    }

    $tenants = $query->get();

    if ($tenants->isEmpty()) {
        $this->warn('No se encontraron tenants para provisionar.');

        return 0;
    }

    foreach ($tenants as $tenant) {
        $schemaName = $tenant->schema_name ?: $schemaService->generateSchemaName($tenant);

        if ($dryRun) {
            $this->line($tenant->uid.' -> '.$schemaName);

            continue;
        }

        $schemaService->provision($tenant);
        $this->info($tenant->uid.' -> '.$schemaName);
    }

    return 0;
})->purpose('Create or backfill PostgreSQL schemas for tenants without switching runtime tenancy mode');

Artisan::command('tenants:migrate {--tenant_uid=} {--path=database/migrations/tenant} {--pretend} {--force}', function () {
    $tenantUid = $this->option('tenant_uid');
    $path = (string) $this->option('path');
    $pretend = (bool) $this->option('pretend');
    $schemaService = app(TenantSchemaService::class);

    if (! is_dir(base_path($path))) {
        $this->warn('No existe el directorio de migraciones tenant: '.$path);

        return 0;
    }

    $query = Tenant::query()->orderBy('id');

    if ($tenantUid) {
        $query->where('uid', $tenantUid);
    }

    $tenants = $query->get();

    if ($tenants->isEmpty()) {
        $this->warn('No se encontraron tenants para migrar.');

        return 0;
    }

    $originalMigrationTable = config('database.migrations.table', 'migrations');

    foreach ($tenants as $tenant) {
        $this->info('Migrando tenant '.$tenant->uid.' en schema '.$tenant->schema_name);

        try {
            $this->line($schemaService->runTenantMigrations($tenant, $path, $pretend));
        } finally {
            config(['database.migrations.table' => $originalMigrationTable]);
            $schemaService->resetSearchPath();
        }
    }

    return 0;
})->purpose('Run tenant-scoped migrations inside every tenant schema');

Artisan::command('tenants:migrations:baseline-existing {tenant_uid?} {--all} {--path=database/migrations/tenant} {--through=2026_05_28_000004_create_tenant_procurement_commission_partner_tables}', function () {
    $tenantUid = $this->argument('tenant_uid');
    $all = (bool) $this->option('all');
    $path = (string) $this->option('path');
    $through = (string) $this->option('through');
    $schemaService = app(TenantSchemaService::class);

    if (! $tenantUid && ! $all) {
        $this->error('Envia tenant_uid o usa --all.');

        return 1;
    }

    if (! is_dir(base_path($path))) {
        $this->warn('No existe el directorio de migraciones tenant: '.$path);

        return 0;
    }

    $migrations = collect(glob(base_path($path).'/*.php') ?: [])
        ->map(fn (string $file) => pathinfo($file, PATHINFO_FILENAME))
        ->filter(fn (string $migration) => $migration <= $through)
        ->sort()
        ->values();

    if ($migrations->isEmpty()) {
        $this->warn('No hay migraciones para baseline.');

        return 0;
    }

    $query = Tenant::query()->orderBy('id');

    if ($tenantUid) {
        $query->where('uid', $tenantUid);
    }

    $tenants = $query->get();

    if ($tenants->isEmpty()) {
        $this->warn('No se encontraron tenants para baseline.');

        return 0;
    }

    foreach ($tenants as $tenant) {
        $this->info('Baseline tenant '.$tenant->uid.' en schema '.$tenant->schema_name);

        try {
            $schemaService->createSchema($tenant);
            $schemaService->setSearchPath($tenant);

            if (! Schema::hasTable('tenant_migrations')) {
                Schema::create('tenant_migrations', function (Blueprint $table) {
                    $table->increments('id');
                    $table->string('migration');
                    $table->integer('batch');
                });
            }

            $existing = collect(DB::table('tenant_migrations')->pluck('migration')->all());
            $batch = ((int) DB::table('tenant_migrations')->max('batch')) + 1;
            $inserted = 0;

            foreach ($migrations as $migration) {
                if ($existing->contains($migration)) {
                    continue;
                }

                DB::table('tenant_migrations')->insert([
                    'migration' => $migration,
                    'batch' => $batch,
                ]);
                $inserted++;
            }

            $this->line('  migraciones marcadas: '.$inserted);
        } finally {
            $schemaService->resetSearchPath();
        }
    }

    return 0;
})->purpose('Mark existing tenant create-table migrations as already applied without deleting data');

Artisan::command('tenants:schemas:copy-data {tenant_uid?} {--all} {--tables=} {--execute} {--truncate}', function () {
    $tenantUid = $this->argument('tenant_uid');
    $all = (bool) $this->option('all');
    $execute = (bool) $this->option('execute');
    $truncate = (bool) $this->option('truncate');
    $tablesOption = $this->option('tables');
    $tables = $tablesOption
        ? collect(explode(',', (string) $tablesOption))->map(fn ($table) => trim($table))->filter()->values()->all()
        : [];

    if (! $tenantUid && ! $all) {
        $this->error('Debes enviar tenant_uid o usar --all. Por seguridad el comando no copia todos los tenants por defecto.');

        return 1;
    }

    if ($truncate && ! $execute) {
        $this->warn('--truncate se ignora en dry-run. Agrega --execute para copiar datos realmente.');
    }

    $query = Tenant::query()->orderBy('id');

    if ($tenantUid) {
        $query->where('uid', $tenantUid);
    }

    $tenants = $query->get();

    if ($tenants->isEmpty()) {
        $this->warn('No se encontraron tenants para copiar.');

        return 0;
    }

    $schemaService = app(TenantSchemaService::class);

    foreach ($tenants as $tenant) {
        $result = $schemaService->copyTenantData($tenant, $tables, $execute, $truncate);
        $this->info(($execute ? 'Copiando' : 'Dry-run').' tenant '.$result['tenant_uid'].' en schema '.$result['schema_name']);

        foreach ($result['tables'] as $tableResult) {
            $this->line(sprintf(
                '  %-36s %-22s source=%s columns=%s copied=%s',
                $tableResult['table'],
                $tableResult['status'],
                $tableResult['source_count'] ?? 'n/a',
                count($tableResult['columns'] ?? []),
                $tableResult['copied']
            ));
        }
    }

    if (! $execute) {
        $this->warn('No se copiaron datos. Repite con --execute cuando las tablas tenant existan y el dry-run este correcto.');
    }

    return 0;
})->purpose('Dry-run or copy tenant-owned rows from public tables into tenant schemas');

Artisan::command('tenants:schemas:reset-sequences {tenant_uid} {--tables=}', function () {
    $tenantUid = $this->argument('tenant_uid');
    $tables = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('tables')))));

    $tenant = Tenant::query()->where('uid', $tenantUid)->first();

    if (! $tenant) {
        $this->error('Tenant no encontrado.');

        return 1;
    }

    $schemaService = app(TenantSchemaService::class);
    $result = $schemaService->resetTenantSequences($tenant, $tables);

    $this->info('Secuencias reiniciadas para tenant '.$result['tenant_uid'].' en schema '.$result['schema_name']);

    foreach ($result['tables'] as $tableResult) {
        $this->line(sprintf(
            '  %-36s sequence=%s max_id=%s total=%s',
            $tableResult['table'],
            $tableResult['sequence'],
            $tableResult['max_id'],
            $tableResult['total']
        ));
    }

    return 0;
})->purpose('Reset PostgreSQL sequences after copying explicit IDs into a tenant schema');

Artisan::command('tenants:schemas:verify {tenant_uid?} {--all} {--tables=} {--orphans}', function () {
    $tenantUid = $this->argument('tenant_uid');
    $all = (bool) $this->option('all');
    $tables = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('tables')))));
    $schemaService = app(TenantSchemaService::class);

    if ((bool) $this->option('orphans')) {
        $knownSchemas = Tenant::query()
            ->whereNotNull('schema_name')
            ->pluck('schema_name')
            ->all();
        $knownLookup = array_flip($knownSchemas);
        $orphans = collect($schemaService->tenantSchemas())
            ->reject(fn (string $schemaName) => isset($knownLookup[$schemaName]))
            ->values();

        if ($orphans->isEmpty()) {
            $this->info('No hay schemas tenant huerfanos.');
        } else {
            $this->warn('Schemas tenant sin registro activo en tenants:');
            $orphans->each(fn (string $schemaName) => $this->line('  '.$schemaName));
        }

        if (! $tenantUid && ! $all) {
            return 0;
        }
    }

    if (! $tenantUid && ! $all) {
        $this->error('Debes enviar tenant_uid o usar --all. Tambien puedes usar solo --orphans.');

        return 1;
    }

    $query = Tenant::query()->orderBy('id');

    if ($tenantUid) {
        $query->where('uid', $tenantUid);
    }

    $tenants = $query->get();

    if ($tenants->isEmpty()) {
        $this->warn('No se encontraron tenants para verificar.');

        return 0;
    }

    foreach ($tenants as $tenant) {
        $result = $schemaService->tenantDataStatus($tenant, $tables);
        $this->info('Tenant '.$result['tenant_uid'].' en schema '.$result['schema_name']);

        foreach ($result['tables'] as $tableResult) {
            $this->line(sprintf(
                '  %-36s public=%s schema=%s delta=%s tenant_table=%s',
                $tableResult['table'],
                $tableResult['public_count'] ?? 'n/a',
                $tableResult['schema_count'] ?? 'n/a',
                $tableResult['delta'] ?? 'n/a',
                $tableResult['tenant_exists'] ? 'yes' : 'no'
            ));
        }
    }

    return 0;
})->purpose('Verify tenant schema table counts against public tenant rows');

Artisan::command('tenants:schemas:activate {tenant_uid}', function () {
    $tenantUid = $this->argument('tenant_uid');

    $tenant = Tenant::query()->where('uid', $tenantUid)->first();

    if (! $tenant) {
        $this->error('Tenant no encontrado.');

        return 1;
    }

    $schemaService = app(TenantSchemaService::class);
    $tenant = $schemaService->markMigrated($tenant);

    $this->info('Tenant activado para schema en modo hybrid/schema.');
    $this->line('tenant_uid: '.$tenant->uid);
    $this->line('schema_name: '.$tenant->schema_name);
    $this->line('schema_migrated_at: '.$tenant->schema_migrated_at?->toISOString());

    return 0;
})->purpose('Mark one tenant as schema-migrated so hybrid mode routes it to its PostgreSQL schema');

Artisan::command('tenants:schemas:deactivate {tenant_uid}', function () {
    $tenantUid = $this->argument('tenant_uid');

    $tenant = Tenant::query()->where('uid', $tenantUid)->first();

    if (! $tenant) {
        $this->error('Tenant no encontrado.');

        return 1;
    }

    $schemaService = app(TenantSchemaService::class);
    $tenant = $schemaService->unmarkMigrated($tenant);

    $this->warn('Tenant devuelto a modo shared en TENANCY_MODE=hybrid.');
    $this->line('tenant_uid: '.$tenant->uid);
    $this->line('schema_name: '.$tenant->schema_name);
    $this->line('schema_migrated_at: null');

    return 0;
})->purpose('Unmark one tenant as schema-migrated for quick hybrid-mode rollback');

Artisan::command('tenants:seed-demo {tenant_uid?} {--active} {--user_email=}', function () {
    $tenantUid = $this->argument('tenant_uid');
    $useActive = (bool) $this->option('active');
    $userEmail = $this->option('user_email');

    if (! $tenantUid && ! $useActive) {
        $this->error('Debes enviar tenant_uid o usar --active.');

        return 1;
    }

    $query = Tenant::query()->orderBy('id');

    if ($tenantUid) {
        $query->where('uid', $tenantUid);
    } else {
        $query->where('is_active', true)->where('status', 'ACTIVO');
    }

    $tenants = $query->get();

    if ($tenants->isEmpty()) {
        $this->error('No se encontro tenant para cargar datos demo.');

        return 1;
    }

    if (! $tenantUid && $tenants->count() > 1) {
        $this->error('Hay mas de un tenant activo. Ejecuta el comando con tenant_uid para evitar cargar datos en el tenant equivocado.');
        $tenants->each(fn (Tenant $tenant) => $this->line($tenant->uid.' - '.$tenant->name));

        return 1;
    }

    $tenant = $tenants->first();
    $owner = null;

    if ($userEmail) {
        $owner = User::withoutGlobalScopes()
            ->where('tenant_id', $tenant->getKey())
            ->where('email', $userEmail)
            ->first();

        if (! $owner) {
            $this->error('No existe un usuario con ese email dentro del tenant.');

            return 1;
        }
    }

    $schemaService = app(TenantSchemaService::class);
    $result = $schemaService->runForTenant(
        $tenant,
        fn () => app(TenantDemoDataService::class)->seed($tenant, $owner)
    );

    $this->info('Datos demo cargados correctamente.');
    foreach ($result as $key => $value) {
        $this->line($key.': '.$value);
    }

    return 0;
})->purpose('Seed demo CRM and pipeline data into an existing tenant');

Artisan::command('superadmin:create {email} {--name=Platform Superadmin} {--password=} {--regenerate-password} {--reset-2fa}', function (string $email) {
    $name = (string) $this->option('name');
    $providedPassword = $this->option('password');
    $password = $providedPassword;
    $regeneratePassword = (bool) $this->option('regenerate-password');
    $resetTwoFactor = (bool) $this->option('reset-2fa');
    $shouldShowPassword = false;

    if (!$password) {
        $password = \Illuminate\Support\Str::random(16);
    }

    if (strlen((string) $password) < 12) {
        $this->error('La password del superadmin debe tener al menos 12 caracteres.');
        return 1;
    }

    $user = User::withoutGlobalScopes()->where('email', $email)->first();
    $shouldUpdatePassword = !$user || $regeneratePassword || filled($providedPassword);

    if ($user && !$shouldUpdatePassword) {
        $this->warn('El usuario ya existe. Se mantuvo su password actual.');
    }

    if ($shouldUpdatePassword) {
        $shouldShowPassword = true;
    }

    $payload = [
        'name' => $name,
        'password' => $shouldUpdatePassword ? Hash::make($password) : $user->password,
        'tenant_id' => null,
        'is_platform_admin' => true,
    ];

    if ($resetTwoFactor) {
        $payload = array_merge($payload, [
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
            'failed_login_attempts' => 0,
            'locked_until' => null,
        ]);
    }

    $user = User::withoutGlobalScopes()->updateOrCreate(
        ['email' => $email],
        $payload
    );

    $permissions = Permission::query()
        ->whereIn('key', [
            'plans.manage',
            'admin.dashboard.read',
            'admin.tenants.manage',
            'admin.tenants.purge',
            'admin.billing.manage',
            'admin.telemetry.read',
            'admin.alerts.manage',
            'users.manage',
        ])
        ->get();

    if ($permissions->isEmpty()) {
        $this->error('No existen permisos administrativos. Ejecuta primero los seeders de permisos.');
        return 1;
    }

    $permissions->each(fn (Permission $permission) => $user->givePermissionTo($permission));

    $this->info('Superadmin global listo.');
    $this->line('Email: ' . $user->email);
    $this->line('Nombre: ' . $user->name);
    $this->line('UID: ' . $user->uid);
    $this->line('is_platform_admin: true');
    $this->line('tenant_id: null');
    $this->line('Permisos admin sincronizados: ' . $permissions->pluck('key')->implode(', '));

    if ($resetTwoFactor) {
        $this->line('2FA reseteado: si');
    }

    if ($shouldShowPassword) {
        $this->line('Password temporal: ' . $password);
    }

    return 0;
})->purpose('Create or update the first global platform superadmin user');
