<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('permission_role')
            ->whereIn('permission_id', function ($query) {
                $query->select('id')->from('permissions')->where('scope', 'platform');
            })
            ->delete();

        DB::table('permission_user')
            ->whereIn('permission_id', function ($query) {
                $query->select('id')->from('permissions')->where('scope', 'platform');
            })
            ->whereIn('user_id', function ($query) {
                $query->select('id')->from('users')->whereNotNull('tenant_id');
            })
            ->delete();

        DB::table('permission_user')
            ->whereIn('permission_id', function ($query) {
                $query->select('id')->from('permissions')->where('scope', 'tenant');
            })
            ->whereIn('user_id', function ($query) {
                $query->select('id')->from('users')->whereNull('tenant_id')->where('is_platform_admin', true);
            })
            ->delete();
    }

    public function down(): void
    {
        // Removed cross-scope grants cannot be reconstructed safely.
    }
};
