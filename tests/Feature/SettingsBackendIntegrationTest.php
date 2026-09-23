<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Contact;
use App\Models\Currency;
use App\Models\CustomField;
use App\Models\DocumentType;
use App\Models\Opportunity;
use App\Models\OpportunityStage;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\Tag;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SettingsBackendIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_tags_normalize_color_and_store_entity_types(): void
    {
        $this->authenticateWithPermissions(['tags.manage']);

        $response = $this->postJson('/api/tags', [
            'name' => 'VIP',
            'key' => 'vip',
            'color' => 'green',
            'category' => 'general',
            'entity_types' => ['CONTACT', 'COMPANY'],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.color', '#16a34a')
            ->assertJsonPath('data.entity_types.0', 'CONTACT')
            ->assertJsonPath('data.entity_types.1', 'COMPANY');

        $uid = $response->json('data.uid');

        $this->putJson('/api/tags/'.$uid, [
            'color' => 'blue',
            'entity_types' => ['DEAL'],
        ])
            ->assertOk()
            ->assertJsonPath('data.color', '#2563eb')
            ->assertJsonPath('data.entity_types.0', 'DEAL');
    }

    public function test_tag_assignment_accepts_official_entity_type_and_returns_tags_with_contact(): void
    {
        $user = $this->authenticateWithPermissions(['tags.manage', 'contacts.read']);
        $contact = Contact::query()->create([
            'tenant_id' => $user->tenant_id,
            'owner_user_id' => $user->getKey(),
            'first_name' => 'Cliente',
            'last_name' => 'Etiquetado',
            'email' => 'tagged-contact@example.test',
        ]);
        $tag = Tag::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'VIP',
            'key' => 'vip',
            'color' => '#2563eb',
            'entity_types' => ['CONTACT'],
        ]);

        $this->postJson('/api/tags/assign', [
            'tag_uid' => $tag->uid,
            'entity_type' => 'CONTACT',
            'entity_uid' => $contact->uid,
        ])
            ->assertOk()
            ->assertJsonPath('data.entity_type', 'CONTACT');

        $this->getJson('/api/contacts/'.$contact->uid)
            ->assertOk()
            ->assertJsonPath('data.tags.0.uid', $tag->uid);
    }

    public function test_settings_endpoints_accept_search_query(): void
    {
        $user = $this->authenticateWithPermissions([
            'tags.manage',
            'documents.read',
            'custom-fields.manage',
            'users.manage',
        ]);

        Tag::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Cliente VIP',
            'key' => 'cliente-vip',
            'color' => '#16a34a',
        ]);
        Tag::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Prospecto Frio',
            'key' => 'prospecto-frio',
            'color' => '#2563eb',
        ]);

        DocumentType::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Contrato Marco',
            'is_active' => true,
        ]);
        DocumentType::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Factura Cliente',
            'is_active' => true,
        ]);

        CustomField::query()->create([
            'tenant_id' => $user->tenant_id,
            'entity_type' => Account::class,
            'name' => 'Codigo de Licitacion',
            'key' => 'codigo_licitacion',
            'type' => 'text',
        ]);
        CustomField::query()->create([
            'tenant_id' => $user->tenant_id,
            'entity_type' => Account::class,
            'name' => 'Region Comercial',
            'key' => 'region_comercial',
            'type' => 'text',
        ]);

        Role::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Administrador Comercial',
            'key' => 'admin-comercial',
        ]);
        Role::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Soporte Operativo',
            'key' => 'soporte-operativo',
        ]);

        $this->getJson('/api/tags?search=vip')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Cliente VIP');

        $this->getJson('/api/document-types?search=contrato')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Contrato Marco');

        $this->getJson('/api/custom-fields?search=licitacion')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.label', 'Codigo de Licitacion')
            ->assertJsonPath('meta.totals.companies', 2);

        $this->getJson('/api/rbac/roles?search=comercial')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Administrador Comercial');
    }

    public function test_roles_index_supports_pagination_meta(): void
    {
        $user = $this->authenticateWithPermissions(['users.manage']);

        Role::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Administrador Comercial',
            'key' => 'admin-comercial',
        ]);

        Role::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Soporte Operativo',
            'key' => 'soporte-operativo',
        ]);

        $this->getJson('/api/rbac/roles?page=1&per_page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.pagination.current_page', 1)
            ->assertJsonPath('meta.pagination.per_page', 1)
            ->assertJsonPath('meta.pagination.total', 2);
    }

    public function test_users_index_returns_aggregated_summary_with_pagination(): void
    {
        $user = $this->authenticateWithPermissions(['users.manage']);

        User::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Activo Operativo',
            'email' => 'activo+'.uniqid().'@example.test',
            'password' => bcrypt('secret123'),
        ]);
        User::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Bloqueado Operativo',
            'email' => 'bloqueado+'.uniqid().'@example.test',
            'password' => bcrypt('secret123'),
            'locked_until' => now()->addYear(),
        ]);

        $this->getJson('/api/users?page=1&per_page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.pagination.total', 3)
            ->assertJsonPath('summary.total_users', 3)
            ->assertJsonPath('summary.active_users', 2)
            ->assertJsonPath('summary.inactive_users', 1);
    }

    public function test_roles_generate_key_from_name_when_missing(): void
    {
        $this->authenticateWithPermissions(['users.manage']);

        $this->postJson('/api/rbac/roles', [
            'name' => 'Administrador Comercial',
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Administrador Comercial')
            ->assertJsonPath('data.key', 'administrador_comercial');

        $this->postJson('/api/rbac/roles', [
            'name' => 'Administrador Comercial',
        ])
            ->assertCreated()
            ->assertJsonPath('data.key', 'administrador_comercial_2');
    }

    public function test_teams_member_endpoints_and_delete_guard_match_settings_contract(): void
    {
        $owner = $this->authenticateWithPermissions(['teams.read', 'teams.manage']);
        $leader = User::query()->create([
            'tenant_id' => $owner->tenant_id,
            'name' => 'Carlos Mendoza',
            'email' => 'leader-settings+'.uniqid().'@example.test',
            'password' => bcrypt('secret123'),
        ]);
        $member = User::query()->create([
            'tenant_id' => $owner->tenant_id,
            'name' => 'Laura Rios',
            'email' => 'member-settings+'.uniqid().'@example.test',
            'password' => bcrypt('secret123'),
        ]);
        Account::query()->create([
            'tenant_id' => $owner->tenant_id,
            'owner_user_id' => $member->getKey(),
            'name' => 'Cliente Asignado',
            'document' => 'SET-'.uniqid(),
        ]);

        $team = $this->postJson('/api/teams', [
            'name' => 'Equipo Norte',
            'leader_uid' => $leader->uid,
        ]);

        $team->assertCreated()
            ->assertJsonPath('data.leader_uid', $leader->uid)
            ->assertJsonPath('data.leader_name', 'Carlos Mendoza')
            ->assertJsonPath('data.members_count', 0);

        $uid = $team->json('data.uid');

        $this->postJson('/api/teams/'.$uid.'/members', [
            'user_uid' => $member->uid,
        ])
            ->assertOk()
            ->assertJsonPath('data.members_count', 1)
            ->assertJsonPath('data.members.0.user_uid', $member->uid)
            ->assertJsonPath('data.members.0.user_name', 'Laura Rios')
            ->assertJsonPath('data.members.0.assigned_clients', 1);

        $this->deleteJson('/api/teams/'.$uid)
            ->assertUnprocessable();

        $this->deleteJson('/api/teams/'.$uid.'/members/'.$member->uid)
            ->assertOk()
            ->assertJsonPath('message', 'Member removed');

        $this->deleteJson('/api/teams/'.$uid)
            ->assertOk()
            ->assertJsonPath('message', 'Team deleted');
    }

    public function test_teams_index_filters_by_search(): void
    {
        $this->authenticateWithPermissions(['teams.read', 'teams.manage']);

        $this->postJson('/api/teams', [
            'name' => 'Equipo Norte',
        ])->assertCreated();

        $this->postJson('/api/teams', [
            'name' => 'Soporte Sur',
        ])->assertCreated();

        $this->getJson('/api/teams?search=norte')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Equipo Norte');
    }

    public function test_teams_index_supports_pagination_meta(): void
    {
        $this->authenticateWithPermissions(['teams.read', 'teams.manage']);

        $this->postJson('/api/teams', [
            'name' => 'Equipo Norte',
        ])->assertCreated();

        $this->postJson('/api/teams', [
            'name' => 'Soporte Sur',
        ])->assertCreated();

        $this->getJson('/api/teams?page=1&per_page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.pagination.current_page', 1)
            ->assertJsonPath('meta.pagination.per_page', 1)
            ->assertJsonPath('meta.pagination.total', 2);
    }

    public function test_custom_fields_and_localization_accept_settings_frontend_aliases(): void
    {
        $this->authenticateWithPermissions(['custom-fields.manage', 'settings.manage']);
        Currency::query()->create([
            'code' => 'USD',
            'name' => 'US Dollar',
            'symbol' => 'US$',
        ]);

        $field = $this->postJson('/api/custom-fields', [
            'module' => 'contacts',
            'label' => 'Codigo de Licitacion',
            'key' => 'codigo_licitacion',
            'type' => 'text',
            'required' => false,
        ]);

        $field->assertCreated()
            ->assertJsonPath('data.label', 'Codigo de Licitacion')
            ->assertJsonPath('data.module', 'contacts')
            ->assertJsonPath('data.required', false);

        $uid = $field->json('data.uid');

        $this->putJson('/api/custom-fields/'.$uid, [
            'label' => 'Codigo actualizado',
            'required' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.label', 'Codigo actualizado')
            ->assertJsonPath('data.required', true);

        $this->putJson('/api/settings/localization', [
            'currency' => 'USD',
            'locale' => 'en-US',
            'timezone' => 'America/Bogota',
            'date_format' => 'Y-m-d',
        ])
            ->assertOk()
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.currency_symbol', 'US$')
            ->assertJsonPath('data.locale', 'en-US')
            ->assertJsonPath('data.timezone', 'America/Bogota')
            ->assertJsonPath('data.date_format', 'YYYY-MM-DD');
    }

    public function test_custom_field_modules_endpoint_returns_supported_frontend_slugs(): void
    {
        $this->authenticateWithPermissions(['custom-fields.manage']);

        $this->getJson('/api/custom-fields/modules')
            ->assertOk()
            ->assertJsonPath('data.0.value', 'contacts')
            ->assertJsonPath('data.0.label', 'Contactos')
            ->assertJsonPath('data.1.value', 'companies')
            ->assertJsonPath('data.1.label', 'Empresas')
            ->assertJsonPath('data.2.value', 'opportunities')
            ->assertJsonPath('data.2.label', 'Oportunidades')
            ->assertJsonPath('data.3.value', 'products')
            ->assertJsonPath('data.3.label', 'Productos');
    }

    public function test_localization_options_endpoint_returns_selector_options_from_backend(): void
    {
        $this->authenticateWithPermissions([]);
        Currency::query()->create([
            'code' => 'COP',
            'name' => 'Peso colombiano',
            'symbol' => '$',
        ]);
        Currency::query()->create([
            'code' => 'USD',
            'name' => 'US Dollar',
            'symbol' => 'US$',
        ]);

        $this->getJson('/api/settings/localization/options')
            ->assertOk()
            ->assertJsonPath('data.timezones.0', 'America/Bogota')
            ->assertJsonPath('data.currencies.0.code', 'COP')
            ->assertJsonPath('data.currencies.0.label', 'Peso colombiano')
            ->assertJsonPath('data.currencies.0.symbol', '$')
            ->assertJsonPath('data.currencies.1.code', 'USD')
            ->assertJsonPath('data.date_formats', ['DD/MM/YYYY', 'MM/DD/YYYY', 'YYYY-MM-DD'])
            ->assertJsonPath('data.locales.0.value', 'es-CO')
            ->assertJsonPath('data.locales.0.label', 'Español (Colombia)');
    }

    public function test_localization_options_returns_default_currencies_when_table_is_empty(): void
    {
        $this->authenticateWithPermissions([]);

        $this->getJson('/api/settings/localization/options')
            ->assertOk()
            ->assertJsonPath('data.currencies.0.code', 'COP')
            ->assertJsonPath('data.currencies.0.label', 'Peso colombiano')
            ->assertJsonPath('data.currencies.1.code', 'USD')
            ->assertJsonPath('data.currencies.2.code', 'EUR');
    }

    public function test_settings_countries_and_cities_endpoints_feed_contact_selects(): void
    {
        $this->authenticateWithPermissions([]);

        $this->getJson('/api/settings/countries')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Argentina')
            ->assertJsonFragment(['name' => 'Colombia'])
            ->assertJsonFragment(['name' => 'Mexico']);

        $this->getJson('/api/settings/cities?country=Colombia&search=Bog')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Bogota');
    }

    public function test_static_status_and_priority_endpoints_return_supported_values(): void
    {
        $this->authenticateWithPermissions([
            'projects.read',
            'finance.read',
            'quotations.read',
            'tasks.read',
            'users.manage',
        ]);

        $this->getJson('/api/projects/statuses')
            ->assertOk()
            ->assertJsonPath('data.0.value', 'pending')
            ->assertJsonPath('data.7.value', 'cancelled');

        $this->getJson('/api/milestones/statuses')
            ->assertOk()
            ->assertJsonPath('data.0.value', 'pending')
            ->assertJsonPath('data.3.value', 'completed');

        $this->getJson('/api/invoices/statuses')
            ->assertOk()
            ->assertJsonPath('data.0.value', 'draft')
            ->assertJsonPath('data.4.value', 'overdue');

        $this->getJson('/api/quotations/statuses')
            ->assertOk()
            ->assertJsonPath('data.0.value', 'draft')
            ->assertJsonPath('data.3.value', 'invoiced')
            ->assertJsonPath('data.5.value', 'cancelled');

        $this->getJson('/api/tasks/statuses')
            ->assertOk()
            ->assertJsonPath('data.0.value', 'pending')
            ->assertJsonPath('data.3.value', 'cancelled');

        $this->getJson('/api/tasks/priorities')
            ->assertOk()
            ->assertJsonPath('data.0.value', 'low')
            ->assertJsonPath('data.3.value', 'urgent');

        $this->getJson('/api/users/statuses')
            ->assertOk()
            ->assertJsonPath('data.0.value', 'ACTIVO')
            ->assertJsonPath('data.1.value', 'INACTIVO');
    }

    public function test_custom_fields_index_returns_totals_by_module(): void
    {
        $this->authenticateWithPermissions(['custom-fields.manage']);

        $this->postJson('/api/custom-fields', [
            'module' => 'contacts',
            'label' => 'Cumpleanos',
            'key' => 'cumpleanos',
            'type' => 'date',
        ])->assertCreated();

        $this->postJson('/api/custom-fields', [
            'module' => 'products',
            'label' => 'Familia producto',
            'key' => 'familia_producto',
            'type' => 'text',
        ])->assertCreated()
            ->assertJsonPath('data.module', 'products');

        $this->getJson('/api/custom-fields')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.totals.contacts', 1)
            ->assertJsonPath('meta.totals.companies', 0)
            ->assertJsonPath('meta.totals.opportunities', 0)
            ->assertJsonPath('meta.totals.products', 1);
    }

    public function test_custom_fields_can_be_filtered_by_frontend_module(): void
    {
        $user = $this->authenticateWithPermissions([
            'custom-fields.manage',
            'products.read',
            'opportunities.read',
        ]);

        $productField = $this->postJson('/api/custom-fields', [
            'entity_type' => 'product',
            'label' => 'Talla comercial',
            'key' => 'talla_comercial',
            'type' => 'text',
        ]);

        $productField->assertCreated()
            ->assertJsonPath('data.entity_type', 'product')
            ->assertJsonPath('data.module', 'products');

        $opportunityField = $this->postJson('/api/custom-fields', [
            'entity_type' => 'opportunity',
            'label' => 'Probabilidad externa',
            'key' => 'probabilidad_externa',
            'type' => 'number',
        ]);

        $opportunityField->assertCreated()
            ->assertJsonPath('data.entity_type', 'opportunity')
            ->assertJsonPath('data.module', 'opportunities');

        $this->getJson('/api/custom-fields?module=products')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.uid', $productField->json('data.uid'))
            ->assertJsonPath('data.0.entity_type', 'product')
            ->assertJsonPath('meta.totals.products', 1)
            ->assertJsonPath('meta.totals.opportunities', 1);

        $this->getJson('/api/custom-fields?entity_type=products')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.uid', $productField->json('data.uid'));

        $product = Product::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Camisa',
            'type' => 'product',
            'sku' => 'CAM-'.uniqid(),
            'status' => 'active',
        ]);

        $this->postJson('/api/custom-fields/value', [
            'entity_type' => 'products',
            'entity_uid' => $product->uid,
            'custom_field_uid' => $productField->json('data.uid'),
            'value' => 'XL',
        ])
            ->assertOk()
            ->assertJsonPath('data.entity_uid', $product->uid)
            ->assertJsonPath('data.custom_field_uid', $productField->json('data.uid'))
            ->assertJsonPath('data.value', 'XL');

        $this->getJson('/api/products/'.$product->uid)
            ->assertOk()
            ->assertJsonPath('data.custom_fields.0.custom_field_uid', $productField->json('data.uid'))
            ->assertJsonPath('data.custom_fields.0.value', 'XL');

        $stage = OpportunityStage::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Leads',
            'key' => 'leads-'.uniqid(),
            'position' => 1,
            'probability_percent' => 10,
        ]);

        $opportunity = Opportunity::query()->create([
            'tenant_id' => $user->tenant_id,
            'owner_user_id' => $user->getKey(),
            'stage_id' => $stage->getKey(),
            'title' => 'Venta enterprise',
            'amount' => 1000,
            'currency' => 'COP',
        ]);

        $this->postJson('/api/custom-fields/value', [
            'entity_type' => 'opportunities',
            'entity_uid' => $opportunity->uid,
            'custom_field_uid' => $opportunityField->json('data.uid'),
            'value' => 80,
        ])
            ->assertOk()
            ->assertJsonPath('data.entity_uid', $opportunity->uid)
            ->assertJsonPath('data.custom_field_uid', $opportunityField->json('data.uid'))
            ->assertJsonPath('data.value', 80);

        $this->getJson('/api/opportunities/'.$opportunity->uid)
            ->assertOk()
            ->assertJsonPath('data.custom_fields.0.custom_field_uid', $opportunityField->json('data.uid'))
            ->assertJsonPath('data.custom_fields.0.value', 80);
    }

    public function test_custom_field_key_is_generated_from_label_when_missing(): void
    {
        $this->authenticateWithPermissions(['custom-fields.manage']);

        $first = $this->postJson('/api/custom-fields', [
            'module' => 'products',
            'label' => 'Fecha de garantia',
            'type' => 'date',
        ]);

        $first->assertCreated()
            ->assertJsonPath('data.key', 'fecha_de_garantia');

        $this->postJson('/api/custom-fields', [
            'module' => 'products',
            'label' => 'Fecha de garantia',
            'type' => 'date',
        ])
            ->assertCreated()
            ->assertJsonPath('data.key', 'fecha_de_garantia_2');
    }

    public function test_pipeline_is_accepted_as_custom_field_alias_for_opportunities(): void
    {
        $user = $this->authenticateWithPermissions(['custom-fields.manage', 'opportunities.read']);

        $field = $this->postJson('/api/custom-fields', [
            'module' => 'pipeline',
            'label' => 'Origen campaña',
            'type' => 'text',
        ]);

        $field->assertCreated()
            ->assertJsonPath('data.entity_type', 'opportunity')
            ->assertJsonPath('data.module', 'opportunities');

        $stage = OpportunityStage::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Leads',
            'key' => 'leads-'.uniqid(),
            'position' => 1,
            'probability_percent' => 10,
        ]);

        $opportunity = Opportunity::query()->create([
            'tenant_id' => $user->tenant_id,
            'owner_user_id' => $user->getKey(),
            'stage_id' => $stage->getKey(),
            'title' => 'Venta desde pipeline',
            'amount' => 1000,
            'currency' => 'COP',
        ]);

        $this->postJson('/api/custom-fields/value', [
            'entity_type' => 'pipeline',
            'entity_uid' => $opportunity->uid,
            'custom_field_uid' => $field->json('data.uid'),
            'value' => 'LinkedIn Ads',
        ])
            ->assertOk()
            ->assertJsonPath('data.value', 'LinkedIn Ads');

        $this->getJson('/api/opportunities/'.$opportunity->uid)
            ->assertOk()
            ->assertJsonPath('data.custom_fields.0.custom_field_uid', $field->json('data.uid'))
            ->assertJsonPath('data.custom_fields.0.value', 'LinkedIn Ads');

        $this->getJson('/api/custom-fields?module=pipeline')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.uid', $field->json('data.uid'));
    }

    public function test_opportunity_create_accepts_pipeline_custom_fields(): void
    {
        $user = $this->authenticateWithPermissions([
            'custom-fields.manage',
            'opportunities.read',
            'opportunities.manage',
        ]);

        $field = $this->postJson('/api/custom-fields', [
            'module' => 'pipeline',
            'label' => 'Campaña origen',
            'type' => 'text',
        ])->assertCreated();

        $stage = OpportunityStage::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Leads',
            'key' => 'leads-'.uniqid(),
            'position' => 1,
            'probability_percent' => 10,
        ]);

        $this->postJson('/api/opportunities', [
            'stage_uid' => $stage->uid,
            'title' => 'Lead con campo custom',
            'amount' => 250000,
            'currency' => 'COP',
            'custom_fields' => [
                [
                    'custom_field_uid' => $field->json('data.uid'),
                    'value' => 'Expo SaaS',
                ],
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('data.custom_fields.0.custom_field_uid', $field->json('data.uid'))
            ->assertJsonPath('data.custom_fields.0.value', 'Expo SaaS');
    }

    private function authenticateWithPermissions(array $permissionKeys): User
    {
        $tenant = Tenant::query()->create([
            'name' => 'Tenant Settings',
            'status' => 'active',
            'is_active' => true,
        ]);

        foreach ($permissionKeys as $key) {
            Permission::query()->firstOrCreate(
                ['key' => $key],
                [
                    'module' => str_contains($key, '.') ? explode('.', $key)[0] : 'settings',
                    'action' => $key,
                    'description' => $key,
                ]
            );
        }

        $user = User::query()->create([
            'tenant_id' => $tenant->getKey(),
            'name' => 'Settings Owner',
            'email' => 'settings-owner+'.uniqid().'@example.test',
            'password' => bcrypt('secret123'),
        ]);

        $permissionIds = Permission::query()->whereIn('key', $permissionKeys)->pluck('id')->all();
        $user->permissions()->sync($permissionIds);

        Sanctum::actingAs($user, ['access:full', 'tenant:'.$tenant->uid]);

        return $user;
    }
}
