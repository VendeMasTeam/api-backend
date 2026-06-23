<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Tenant schema mode
    |--------------------------------------------------------------------------
    |
    | shared: comportamiento actual, todas las tablas viven en public con tenant_id.
    | hybrid: solo tenants con schema_migrated_at usan schema propio.
    | schema: todos los tenants operan sobre su propio schema PostgreSQL.
    |
    */
    'mode' => env('TENANCY_MODE', 'shared'),

    'schema_prefix' => env('TENANT_SCHEMA_PREFIX', 'tenant'),

    /*
    |--------------------------------------------------------------------------
    | Table ownership
    |--------------------------------------------------------------------------
    |
    | global_tables se mantienen en public. tenant_tables son candidatas a vivir
    | dentro de cada schema del tenant. Mantener este manifiesto explicito evita
    | mover tablas administrativas por accidente durante la migracion gradual.
    |
    */
    'global_tables' => [
        'tenants',
        'plans',
        'plan_features',
        'plan_permissions',
        'users',
        'roles',
        'permissions',
        'admin_users',
        'admin_roles',
        'admin_permissions',
        'personal_access_tokens',
        'jobs',
        'failed_jobs',
        'cache',
        'cache_locks',
        'sessions',
        'currencies',
        'countries',
        'cities',
        'migrations',
    ],

    'tenant_tables' => [
        'accounts',
        'contacts',
        'crm_entities',
        'relations',
        'opportunity_stages',
        'opportunities',
        'activities',
        'tasks',
        'inventory_categories',
        'warehouses',
        'inventory_products',
        'products',
        'product_versions',
        'product_dependencies',
        'price_books',
        'price_book_items',
        'inventory_stocks',
        'inventory_reservations',
        'inventory_movements',
        'quotations',
        'quotation_items',
        'invoices',
        'payments',
        'credit_profiles',
        'exchange_rates',
        'financial_records',
        'credit_rules',
        'projects',
        'project_milestones',
        'project_assignments',
        'documents',
        'document_types',
        'alert_rules',
        'document_alerts',
        'document_versions',
        'custom_fields',
        'custom_field_values',
        'tags',
        'taggables',
        'expenses',
        'expense_categories',
        'suppliers',
        'cost_centers',
        'purchase_orders',
        'purchase_order_items',
        'purchase_order_receipts',
        'purchase_order_receipt_items',
        'purchase_order_payments',
        'commission_plans',
        'commission_plan_role',
        'commission_rules',
        'commission_assignments',
        'commission_entries',
        'commission_runs',
        'commission_run_items',
        'commission_targets',
        'partners',
        'partner_opportunities',
        'opportunity_conflicts',
        'partner_resources',
        'partner_access',
        'competitors',
        'battlecards',
        'lost_reasons',
        'segments',
        'teams',
        'team_user',
        'automation_rules',
        'automation_assignment_rules',
    ],
];
