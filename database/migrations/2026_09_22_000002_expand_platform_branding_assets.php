<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('platform_settings')) {
            return;
        }

        $missing = collect(['assets_disk', 'logo_light_path', 'logo_dark_path', 'favicon_path'])
            ->reject(fn (string $column) => Schema::hasColumn('platform_settings', $column));

        if ($missing->isNotEmpty()) {
            Schema::table('platform_settings', function (Blueprint $table) use ($missing) {
                foreach ($missing as $column) {
                    $table->string($column)->nullable();
                }
            });
        }

        if (Schema::hasColumn('platform_settings', 'logo_path')) {
            $legacy = DB::table('platform_settings')
                ->whereNotNull('logo_path')
                ->whereNull('logo_light_path')
                ->first();

            if ($legacy) {
                DB::table('platform_settings')
                    ->where('id', $legacy->id)
                    ->update([
                        'assets_disk' => Schema::hasColumn('platform_settings', 'logo_disk') ? $legacy->logo_disk : 'public',
                        'logo_light_path' => $legacy->logo_path,
                    ]);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('platform_settings')) {
            return;
        }

        $columns = collect(['assets_disk', 'logo_light_path', 'logo_dark_path', 'favicon_path'])
            ->filter(fn (string $column) => Schema::hasColumn('platform_settings', $column))
            ->all();

        if ($columns !== []) {
            Schema::table('platform_settings', fn (Blueprint $table) => $table->dropColumn($columns));
        }
    }
};
