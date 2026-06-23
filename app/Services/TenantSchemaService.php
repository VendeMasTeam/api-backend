<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TenantSchemaService
{
    public function provision(Tenant $tenant): string
    {
        return $this->createSchema($tenant);
    }

    public function bootstrapTenant(Tenant $tenant, ?callable $afterMigrations = null, string $path = 'database/migrations/tenant'): Tenant
    {
        $this->createSchema($tenant);

        if (! $this->supportsSchemas()) {
            if ($afterMigrations) {
                $afterMigrations($tenant, false);
            }

            return $tenant->refresh();
        }

        $this->runTenantMigrations($tenant, $path);
        $tenant = $this->markMigrated($tenant);

        if ($afterMigrations) {
            $this->runForTenant(
                $tenant,
                fn (Tenant $tenant, bool $usingSchema) => $afterMigrations($tenant, $usingSchema)
            );
        }

        return $tenant->refresh();
    }

    public function createSchema(Tenant $tenant): string
    {
        $schemaName = $this->ensureSchemaName($tenant);

        if ($this->supportsSchemas()) {
            DB::statement('CREATE SCHEMA IF NOT EXISTS '.$this->quoteIdentifier($schemaName));
        }

        return $schemaName;
    }

    public function dropSchema(Tenant $tenant, bool $cascade = false): void
    {
        if (! $this->supportsSchemas() || ! $tenant->schema_name) {
            return;
        }

        $suffix = $cascade ? ' CASCADE' : ' RESTRICT';

        DB::statement('DROP SCHEMA IF EXISTS '.$this->quoteIdentifier($tenant->schema_name).$suffix);
    }

    public function runTenantMigrations(Tenant $tenant, string $path = 'database/migrations/tenant', bool $pretend = false): string
    {
        $this->createSchema($tenant);
        $this->setSearchPath($tenant);

        $originalMigrationTable = config('database.migrations.table', 'migrations');

        try {
            config(['database.migrations.table' => 'tenant_migrations']);

            Artisan::call('migrate', array_filter([
                '--path' => $path,
                '--force' => true,
                '--pretend' => $pretend ?: null,
            ], fn ($value) => $value !== null));

            return Artisan::output();
        } finally {
            config(['database.migrations.table' => $originalMigrationTable]);
            $this->resetSearchPath();
        }
    }

    public function generateSchemaName(Tenant $tenant): string
    {
        $prefix = Str::of((string) config('tenancy.schema_prefix', 'tenant'))
            ->lower()
            ->replaceMatches('/[^a-z0-9_]+/', '_')
            ->trim('_')
            ->value() ?: 'tenant';

        $source = $tenant->name ?: $tenant->uid ?: (string) Str::uuid();
        $suffix = Str::of($source)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9_]+/', '_')
            ->trim('_')
            ->value() ?: Str::of($tenant->uid ?: (string) Str::uuid())
                ->lower()
                ->replace('-', '_')
                ->replaceMatches('/[^a-z0-9_]+/', '_')
                ->trim('_')
                ->value();

        $baseName = Str::limit($prefix.'_'.$suffix, 63, '');

        if (! $this->schemaNameIsTaken($baseName, $tenant)) {
            return $baseName;
        }

        $uidSuffix = Str::of($tenant->uid ?: (string) Str::uuid())
            ->lower()
            ->before('-')
            ->replaceMatches('/[^a-z0-9_]+/', '_')
            ->trim('_')
            ->value();

        return Str::limit($baseName, max(1, 62 - strlen($uidSuffix)), '').'_'.$uidSuffix;
    }

    public function searchPath(Tenant $tenant): string
    {
        $schemaName = $tenant->schema_name ?: $this->generateSchemaName($tenant);

        return $this->quoteIdentifier($schemaName).', public';
    }

    public function setSearchPath(Tenant $tenant): void
    {
        if (! $this->supportsSchemas()) {
            return;
        }

        DB::statement('SET search_path TO '.$this->searchPath($tenant));
    }

    public function resetSearchPath(): void
    {
        if (! $this->supportsSchemas()) {
            return;
        }

        DB::statement('SET search_path TO public');
    }

    public function shouldUseSchemaMode(?Tenant $tenant = null): bool
    {
        $mode = config('tenancy.mode', 'shared');

        if ($mode === 'schema') {
            return true;
        }

        if ($mode === 'hybrid') {
            return (bool) $tenant?->hasMigratedSchema();
        }

        return false;
    }

    public function runForTenant(Tenant $tenant, callable $callback): mixed
    {
        $usingSchema = $this->shouldUseSchemaMode($tenant);

        try {
            if ($usingSchema) {
                $this->setSearchPath($tenant);
            } else {
                $this->resetSearchPath();
            }

            return $callback($tenant, $usingSchema);
        } finally {
            $this->resetSearchPath();
        }
    }

    public function markMigrated(Tenant $tenant): Tenant
    {
        $schemaName = $this->createSchema($tenant);

        $tenant->forceFill([
            'schema_name' => $schemaName,
            'schema_migrated_at' => now(),
        ])->save();

        return $tenant->refresh();
    }

    public function unmarkMigrated(Tenant $tenant): Tenant
    {
        $tenant->forceFill([
            'schema_migrated_at' => null,
        ])->save();

        return $tenant->refresh();
    }

    public function tenantTables(): array
    {
        return array_values(array_unique(config('tenancy.tenant_tables', [])));
    }

    public function globalTables(): array
    {
        return array_values(array_unique(config('tenancy.global_tables', [])));
    }

    public function copyTenantData(Tenant $tenant, array $tables = [], bool $execute = false, bool $truncate = false): array
    {
        if (! $this->supportsSchemas()) {
            throw ValidationException::withMessages([
                'database' => ['La migracion por schema solo esta soportada en PostgreSQL'],
            ]);
        }

        $schemaName = $this->createSchema($tenant);
        $tables = $tables ?: $this->tenantTables();
        $results = [];

        foreach ($tables as $table) {
            $table = trim((string) $table);

            if ($table === '') {
                continue;
            }

            $publicExists = Schema::hasTable($table);
            $hasTenantId = $publicExists && Schema::hasColumn($table, 'tenant_id');
            $tenantExists = $this->tableExists($schemaName, $table);
            $columns = $publicExists && $tenantExists
                ? $this->copyableColumns($schemaName, $table)
                : [];
            $sourceCount = $hasTenantId
                ? (int) DB::table($table)->where('tenant_id', $tenant->getKey())->count()
                : null;

            $result = [
                'table' => $table,
                'public_exists' => $publicExists,
                'tenant_exists' => $tenantExists,
                'has_tenant_id' => $hasTenantId,
                'source_count' => $sourceCount,
                'columns' => $columns,
                'copied' => 0,
                'status' => 'dry_run',
            ];

            if (! $publicExists) {
                $result['status'] = 'missing_public_table';
                $results[] = $result;
                continue;
            }

            if (! $hasTenantId) {
                $result['status'] = 'missing_tenant_id';
                $results[] = $result;
                continue;
            }

            if (! $tenantExists) {
                $result['status'] = 'missing_tenant_table';
                $results[] = $result;
                continue;
            }

            if ($columns === []) {
                $result['status'] = 'no_common_columns';
                $results[] = $result;
                continue;
            }

            if ($execute) {
                if ($truncate) {
                    DB::statement('TRUNCATE TABLE '.$this->qualifiedTable($schemaName, $table).' RESTART IDENTITY CASCADE');
                }

                $columnList = collect($columns)
                    ->map(fn ($column) => $this->quoteIdentifier($column))
                    ->implode(', ');

                $result['copied'] = DB::affectingStatement(
                    'INSERT INTO '.$this->qualifiedTable($schemaName, $table).' ('.$columnList.')'
                    .' SELECT '.$columnList.' FROM public.'.$this->quoteIdentifier($table)
                    .' WHERE tenant_id = ? ON CONFLICT DO NOTHING',
                    [$tenant->getKey()]
                );
                $result['status'] = 'copied';

                $sequence = $this->resetTableSequence($schemaName, $table);

                if ($sequence) {
                    $result['sequence_reset'] = $sequence;
                }
            }

            $results[] = $result;
        }

        return [
            'tenant_uid' => $tenant->uid,
            'schema_name' => $schemaName,
            'execute' => $execute,
            'truncate' => $truncate && $execute,
            'tables' => $results,
        ];
    }

    public function resetTenantSequences(Tenant $tenant, array $tables = []): array
    {
        if (! $this->supportsSchemas()) {
            throw ValidationException::withMessages([
                'database' => ['La migracion por schema solo esta soportada en PostgreSQL'],
            ]);
        }

        $schemaName = $tenant->schema_name ?: $this->generateSchemaName($tenant);
        $tables = $tables ?: $this->tenantTables();
        $results = [];

        foreach ($tables as $table) {
            $table = trim((string) $table);

            if ($table === '' || ! $this->tableExists($schemaName, $table)) {
                continue;
            }

            $sequence = $this->resetTableSequence($schemaName, $table);

            if ($sequence) {
                $results[] = [
                    'table' => $table,
                    'sequence' => $sequence['sequence'],
                    'max_id' => $sequence['max_id'],
                    'total' => $sequence['total'],
                ];
            }
        }

        return [
            'tenant_uid' => $tenant->uid,
            'schema_name' => $schemaName,
            'tables' => $results,
        ];
    }

    public function tenantDataStatus(Tenant $tenant, array $tables = []): array
    {
        if (! $this->supportsSchemas()) {
            throw ValidationException::withMessages([
                'database' => ['La migracion por schema solo esta soportada en PostgreSQL'],
            ]);
        }

        $schemaName = $tenant->schema_name ?: $this->generateSchemaName($tenant);
        $tables = $tables ?: $this->tenantTables();
        $results = [];

        foreach ($tables as $table) {
            $table = trim((string) $table);

            if ($table === '') {
                continue;
            }

            $publicExists = $this->tableExists('public', $table);
            $tenantExists = $this->tableExists($schemaName, $table);
            $hasTenantId = $publicExists && in_array('tenant_id', $this->tableColumns('public', $table), true);
            $publicCount = $hasTenantId
                ? (int) DB::table($table)->where('tenant_id', $tenant->getKey())->count()
                : null;
            $schemaCount = $tenantExists
                ? $this->tableCount($schemaName, $table)
                : null;

            $results[] = [
                'table' => $table,
                'public_exists' => $publicExists,
                'tenant_exists' => $tenantExists,
                'has_tenant_id' => $hasTenantId,
                'public_count' => $publicCount,
                'schema_count' => $schemaCount,
                'delta' => is_int($publicCount) && is_int($schemaCount) ? $schemaCount - $publicCount : null,
            ];
        }

        return [
            'tenant_uid' => $tenant->uid,
            'schema_name' => $schemaName,
            'tables' => $results,
        ];
    }

    public function tableExists(string $schemaName, string $table): bool
    {
        if (! $this->supportsSchemas()) {
            return false;
        }

        return DB::table('information_schema.tables')
            ->where('table_schema', $schemaName)
            ->where('table_name', $table)
            ->exists();
    }

    public function tableColumns(string $schemaName, string $table): array
    {
        if (! $this->supportsSchemas()) {
            return [];
        }

        return DB::table('information_schema.columns')
            ->where('table_schema', $schemaName)
            ->where('table_name', $table)
            ->orderBy('ordinal_position')
            ->pluck('column_name')
            ->all();
    }

    public function tenantSchemas(): array
    {
        if (! $this->supportsSchemas()) {
            return [];
        }

        return DB::table('information_schema.schemata')
            ->where('schema_name', 'like', config('tenancy.schema_prefix', 'tenant').'\_%')
            ->orderBy('schema_name')
            ->pluck('schema_name')
            ->all();
    }

    private function copyableColumns(string $schemaName, string $table): array
    {
        $sourceColumns = $this->tableColumns('public', $table);
        $tenantColumns = $this->tableColumns($schemaName, $table);

        if ($sourceColumns === [] || $tenantColumns === []) {
            return [];
        }

        $tenantColumnLookup = array_flip($tenantColumns);

        return array_values(array_filter(
            $sourceColumns,
            fn ($column) => isset($tenantColumnLookup[$column])
        ));
    }

    private function supportsSchemas(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    private function resetTableSequence(string $schemaName, string $table): ?array
    {
        $columns = $this->tableColumns($schemaName, $table);

        if ($columns === [] || ! in_array('id', $columns, true)) {
            return null;
        }

        $qualifiedName = $schemaName.'.'.$table;
        $sequence = DB::selectOne("select pg_get_serial_sequence(?, 'id') as sequence_name", [$qualifiedName])?->sequence_name;

        if (! $sequence) {
            return null;
        }

        $stats = DB::selectOne(
            'select coalesce(max('.$this->quoteIdentifier('id').'), 0) as max_id, count(*) as total from '.$this->qualifiedTable($schemaName, $table)
        );
        $maxId = max(1, (int) ($stats->max_id ?? 0));
        $total = (int) ($stats->total ?? 0);

        DB::statement('select setval(?::regclass, ?, ?)', [$sequence, $maxId, $total > 0]);

        return [
            'sequence' => $sequence,
            'max_id' => $maxId,
            'total' => $total,
        ];
    }

    private function tableCount(string $schemaName, string $table): int
    {
        return (int) DB::table(DB::raw($this->qualifiedTable($schemaName, $table)))->count();
    }

    private function schemaNameIsTaken(string $schemaName, Tenant $tenant): bool
    {
        $query = Tenant::query()->where('schema_name', $schemaName);

        if ($tenant->exists) {
            $query->whereKeyNot($tenant->getKey());
        }

        if ($query->exists()) {
            return true;
        }

        if (! $this->supportsSchemas()) {
            return false;
        }

        return DB::table('information_schema.schemata')
            ->where('schema_name', $schemaName)
            ->exists()
            && $tenant->schema_name !== $schemaName;
    }

    private function ensureSchemaName(Tenant $tenant): string
    {
        $schemaName = $tenant->schema_name ?: $this->generateSchemaName($tenant);

        if ($tenant->schema_name !== $schemaName) {
            $tenant->forceFill(['schema_name' => $schemaName])->save();
        }

        return $schemaName;
    }

    private function qualifiedTable(string $schemaName, string $table): string
    {
        return $this->quoteIdentifier($schemaName).'.'.$this->quoteIdentifier($table);
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
}
