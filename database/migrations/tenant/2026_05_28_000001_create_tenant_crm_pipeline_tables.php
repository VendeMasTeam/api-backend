<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->nullable()->unique('accounts_uid_unique');
            $table->unsignedBigInteger('tenant_id');
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('document');
            $table->string('email')->nullable();
            $table->string('industry')->nullable();
            $table->string('website')->nullable();
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'document'], 'accounts_document_unique');
            $table->unique(['tenant_id', 'email'], 'accounts_email_unique');
        });

        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->nullable()->unique('contacts_uid_unique');
            $table->unsignedBigInteger('tenant_id');
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->string('first_name');
            $table->string('last_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('position')->nullable();
            $table->string('status')->default('active');
            $table->boolean('is_public_entity')->default(false);
            $table->timestamps();

            $table->unique(['tenant_id', 'email'], 'contacts_email_unique');
        });

        Schema::create('crm_entities', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->nullable()->unique('crm_entities_uid_unique');
            $table->unsignedBigInteger('tenant_id');
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('type', ['B2B', 'B2C', 'B2G']);
            $table->json('profile_data');
            $table->timestamps();

            $table->index(['tenant_id', 'type']);
        });

        Schema::create('relations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->nullable()->unique('relations_uid_unique');
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('from_type');
            $table->unsignedBigInteger('from_id');
            $table->string('to_type');
            $table->unsignedBigInteger('to_id');
            $table->string('relation_type');
            $table->timestamps();

            $table->index(['from_type', 'from_id']);
            $table->index(['to_type', 'to_id']);
            $table->unique([
                'tenant_id',
                'from_type',
                'from_id',
                'to_type',
                'to_id',
                'relation_type',
            ], 'unique_relation_per_tenant');
            $table->index(['tenant_id', 'from_type', 'from_id'], 'relations_tenant_from_idx');
            $table->index(['tenant_id', 'to_type', 'to_id'], 'relations_tenant_to_idx');
            $table->index(['tenant_id', 'relation_type'], 'relations_tenant_type_idx');
        });

        Schema::create('opportunity_stages', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->string('name');
            $table->string('key');
            $table->unsignedInteger('position')->default(1);
            $table->unsignedInteger('probability_percent')->default(0);
            $table->boolean('is_won')->default(false);
            $table->boolean('is_lost')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'key']);
        });

        Schema::create('opportunities', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('stage_id')->constrained('opportunity_stages')->cascadeOnDelete();
            $table->string('opportunityable_type')->nullable();
            $table->unsignedBigInteger('opportunityable_id')->nullable();
            $table->string('title');
            $table->string('email')->nullable();
            $table->string('lead_origin')->nullable();
            $table->decimal('amount', 14, 2)->default(0);
            $table->string('currency', 10)->nullable();
            $table->date('expected_close_date')->nullable();
            $table->text('description')->nullable();
            $table->timestamp('won_at')->nullable();
            $table->timestamp('lost_at')->nullable();
            $table->unsignedInteger('kanban_position')->default(0);
            $table->timestamps();

            $table->index(['opportunityable_type', 'opportunityable_id']);
            $table->index(['tenant_id', 'lead_origin'], 'opportunities_tenant_lead_origin_idx');
            $table->index(['tenant_id', 'stage_id', 'kanban_position'], 'opportunities_tenant_stage_kanban_idx');
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('pending');
            $table->string('priority')->default('medium');
            $table->date('due_date')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->nullableMorphs('taskable');
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'due_date']);
            $table->index(['tenant_id', 'created_at'], 'tasks_tenant_created_idx');
            $table->index(['tenant_id', 'taskable_type', 'taskable_id'], 'tasks_tenant_taskable_idx');
            $table->index(['tenant_id', 'assigned_user_id', 'status'], 'tasks_tenant_assigned_status_idx');
            $table->index(['tenant_id', 'owner_user_id', 'status'], 'tasks_tenant_owner_status_idx');
        });

        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('pending');
            $table->timestamp('scheduled_at');
            $table->timestamp('completed_at')->nullable();
            $table->nullableMorphs('activityable');
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'scheduled_at']);
            $table->index(['tenant_id', 'activityable_type', 'activityable_id'], 'activities_tenant_activityable_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE crm_entities ALTER COLUMN profile_data TYPE jsonb USING profile_data::jsonb');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('activities');
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('opportunities');
        Schema::dropIfExists('opportunity_stages');
        Schema::dropIfExists('relations');
        Schema::dropIfExists('crm_entities');
        Schema::dropIfExists('contacts');
        Schema::dropIfExists('accounts');
    }
};
