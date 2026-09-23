<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('permissions', function (Blueprint $table) {
            $table->string('scope', 20)->default('tenant')->after('description')->index();
        });

        DB::table('permissions')
            ->where('key', 'like', 'admin.%')
            ->orWhere('key', 'plans.manage')
            ->update(['scope' => 'platform']);

        DB::table('admin_role_permission')
            ->whereIn('permission_id', function ($query) {
                $query->select('id')->from('permissions')->where('scope', 'tenant');
            })
            ->delete();
    }

    public function down(): void
    {
        Schema::table('permissions', function (Blueprint $table) {
            $table->dropIndex(['scope']);
            $table->dropColumn('scope');
        });
    }
};
