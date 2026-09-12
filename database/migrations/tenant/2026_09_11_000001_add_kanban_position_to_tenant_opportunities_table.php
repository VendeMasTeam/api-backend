<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('opportunities', 'kanban_position')) {
            Schema::table('opportunities', function (Blueprint $table) {
                $table->unsignedInteger('kanban_position')->default(0)->after('lost_at');
                $table->index(['tenant_id', 'stage_id', 'kanban_position'], 'opportunities_tenant_stage_kanban_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('opportunities', 'kanban_position')) {
            Schema::table('opportunities', function (Blueprint $table) {
                $table->dropIndex('opportunities_tenant_stage_kanban_idx');
                $table->dropColumn('kanban_position');
            });
        }
    }
};
