<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_versions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('version');
            $table->date('release_date')->nullable();
            $table->string('status', 20)->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'version']);
        });

        Schema::create('product_dependencies', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('depends_on_product_id')->constrained('products')->cascadeOnDelete();
            $table->string('dependency_type', 20);
            $table->text('message')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'depends_on_product_id', 'dependency_type'], 'product_dependency_unique');
        });

        Schema::create('custom_fields', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->nullable()->unique('custom_fields_uid_unique');
            $table->unsignedBigInteger('tenant_id');
            $table->string('entity_type');
            $table->string('name');
            $table->string('key');
            $table->string('type');
            $table->json('options')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'entity_type', 'key'], 'custom_fields_tenant_entity_key_unique');
        });

        Schema::create('custom_field_values', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->nullable()->unique('custom_field_values_uid_unique');
            $table->unsignedBigInteger('tenant_id');
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id');
            $table->foreignId('custom_field_id')->constrained('custom_fields')->cascadeOnDelete();
            $table->text('value')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'entity_type', 'entity_id'], 'custom_field_values_entity_idx');
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->string('name');
            $table->string('key');
            $table->string('color', 20);
            $table->string('category')->default('general');
            $table->json('entity_types')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'key']);
        });

        Schema::create('taggables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tag_id')->constrained('tags')->cascadeOnDelete();
            $table->morphs('taggable');
            $table->timestamps();

            $table->unique(['tag_id', 'taggable_id', 'taggable_type'], 'taggables_unique_assignment');
        });

        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('disk');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type');
            $table->unsignedBigInteger('size');
            $table->nullableMorphs('documentable');
            $table->date('issue_date')->nullable();
            $table->date('expiration_date')->nullable();
            $table->string('status')->default('valid');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version_number')->default(1);
            $table->timestamp('replaced_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'mime_type']);
        });

        Schema::create('document_types', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('validity_days')->nullable();
            $table->boolean('is_required')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
            $table->index(['tenant_id', 'is_required', 'is_active']);
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->foreignId('document_type_id')->nullable()->after('account_id')->constrained('document_types')->nullOnDelete();
            $table->unique(['tenant_id', 'account_id', 'document_type_id'], 'documents_account_type_unique');
            $table->index(['tenant_id', 'status', 'expiration_date']);
        });

        Schema::create('document_versions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('disk');
            $table->string('file_path');
            $table->string('original_name');
            $table->string('mime_type');
            $table->unsignedBigInteger('size');
            $table->date('issue_date')->nullable();
            $table->date('expiration_date')->nullable();
            $table->string('status')->default('valid');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['document_id', 'version_number']);
            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('alert_rules', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->foreignId('document_type_id')->constrained('document_types')->cascadeOnDelete();
            $table->unsignedInteger('days_before');
            $table->string('notification_channel')->default('system');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'document_type_id', 'days_before', 'notification_channel'], 'alert_rules_unique');
        });

        Schema::create('document_alerts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignId('alert_rule_id')->nullable()->constrained('alert_rules')->nullOnDelete();
            $table->date('alert_date');
            $table->string('notification_channel')->default('system');
            $table->string('status')->default('pending');
            $table->text('message')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'alert_date']);
            $table->index(['tenant_id', 'document_id']);
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('opportunity_id')->nullable()->constrained('opportunities')->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status')->default('pending');
            $table->string('priority')->default('medium');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->decimal('estimated_hours', 10, 2)->default(0);
            $table->decimal('actual_hours', 10, 2)->default(0);
            $table->timestamps();

            $table->unique('opportunity_id');
            $table->unique('invoice_id');
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('project_milestones', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->date('due_date')->nullable();
            $table->string('status')->default('pending');
            $table->unsignedInteger('order')->default(1);
            $table->timestamps();

            $table->index(['project_id', 'order']);
        });

        Schema::create('project_assignments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role');
            $table->decimal('hours_allocated', 10, 2)->default(0);
            $table->timestamps();

            $table->unique(['project_id', 'user_id']);
        });

        Schema::create('segments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('entity_type')->default('contact');
            $table->json('rules')->nullable();
            $table->string('logic')->default('AND');
            $table->unsignedInteger('execution_count')->default(0);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'entity_type']);
        });

        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->foreignId('manager_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('team_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['team_id', 'user_id']);
        });

        Schema::create('automation_rules', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('trigger_source');
            $table->string('trigger_event')->nullable();
            $table->json('trigger_config')->nullable();
            $table->json('conditions')->nullable();
            $table->json('actions');
            $table->string('logic')->default('AND');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('execution_count')->default(0);
            $table->timestamp('last_executed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'trigger_source', 'is_active']);
            $table->index(['tenant_id', 'trigger_event', 'is_active']);
        });

        Schema::create('automation_assignment_rules', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->foreignId('assigned_to_user_id')->constrained('users')->cascadeOnDelete();
            $table->json('assigned_user_ids')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('conditions')->nullable();
            $table->string('logic')->default('AND');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX tenant_crm_entities_profile_data_gin ON crm_entities USING gin (profile_data jsonb_path_ops)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS tenant_crm_entities_profile_data_gin');
        }

        Schema::dropIfExists('automation_assignment_rules');
        Schema::dropIfExists('automation_rules');
        Schema::dropIfExists('team_user');
        Schema::dropIfExists('teams');
        Schema::dropIfExists('segments');
        Schema::dropIfExists('project_assignments');
        Schema::dropIfExists('project_milestones');
        Schema::dropIfExists('projects');
        Schema::dropIfExists('document_alerts');
        Schema::dropIfExists('alert_rules');
        Schema::dropIfExists('document_versions');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('document_types');
        Schema::dropIfExists('taggables');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('custom_field_values');
        Schema::dropIfExists('custom_fields');
        Schema::dropIfExists('product_dependencies');
        Schema::dropIfExists('product_versions');
    }
};
