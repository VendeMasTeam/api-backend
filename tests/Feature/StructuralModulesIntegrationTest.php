<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Activity;
use App\Models\AutomationRule;
use App\Models\Contact;
use App\Models\CrmEntity;
use App\Models\Permission;
use App\Models\Tag;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AutomationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StructuralModulesIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_segments_crud_and_run_evaluate_backend_rules(): void
    {
        $user = $this->authenticateWithPermissions(['segments.read', 'segments.manage']);
        $account = Account::query()->create([
            'tenant_id' => $user->tenant_id,
            'owner_user_id' => $user->getKey(),
            'name' => 'B2B Activo',
            'document' => 'DOC-'.uniqid(),
            'industry' => 'software',
        ]);
        Contact::query()->create([
            'tenant_id' => $user->tenant_id,
            'owner_user_id' => $user->getKey(),
            'account_id' => $account->getKey(),
            'first_name' => 'Carlos',
            'last_name' => 'Segmentado',
            'email' => 'segmentado@example.test',
            'position' => 'buyer',
        ]);
        Contact::query()->create([
            'tenant_id' => $user->tenant_id,
            'owner_user_id' => $user->getKey(),
            'first_name' => 'Ana',
            'last_name' => 'No Match',
            'email' => 'no-match@example.test',
            'position' => 'other',
        ]);

        $response = $this->postJson('/api/segments', [
            'name' => 'Compradores',
            'entity_type' => 'contact',
            'logic' => 'AND',
            'rules' => [
                ['field' => 'position', 'operator' => 'equals', 'value' => 'buyer'],
            ],
        ]);

        $response->assertCreated()->assertJsonPath('data.name', 'Compradores');
        $uid = $response->json('data.uid');

        $this->postJson('/api/segments/'.$uid.'/run')
            ->assertOk()
            ->assertJsonPath('data.count', 1)
            ->assertJsonPath('data.data.0.email', 'segmentado@example.test')
            ->assertJsonPath('data.segment.execution_count', 1);
    }

    public function test_segment_can_match_contacts_by_tag_uid(): void
    {
        $user = $this->authenticateWithPermissions(['segments.read', 'segments.manage']);
        $tag = Tag::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'VIP',
            'key' => 'vip',
            'color' => '#2563eb',
            'entity_types' => ['CONTACT'],
        ]);
        $contact = Contact::query()->create([
            'tenant_id' => $user->tenant_id,
            'owner_user_id' => $user->getKey(),
            'first_name' => 'Cliente',
            'last_name' => 'VIP',
            'email' => 'vip-segment@example.test',
        ]);
        $contact->tags()->attach($tag->getKey());

        $segment = $this->postJson('/api/segments', [
            'name' => 'Contactos VIP',
            'entity_type' => 'contact',
            'rules' => [
                ['field' => 'tags', 'operator' => 'contains', 'value' => $tag->uid],
            ],
        ])->assertCreated();

        $this->postJson('/api/segments/'.$segment->json('data.uid').'/run')
            ->assertOk()
            ->assertJsonPath('data.count', 1)
            ->assertJsonPath('data.data.0.uid', $contact->uid)
            ->assertJsonPath('data.data.0.tags.0.uid', $tag->uid);
    }

    public function test_teams_crud_manages_manager_and_members(): void
    {
        $owner = $this->authenticateWithPermissions(['teams.read', 'teams.manage']);
        $manager = User::query()->create([
            'tenant_id' => $owner->tenant_id,
            'name' => 'Manager',
            'email' => 'manager-team@example.test',
            'password' => bcrypt('secret123'),
        ]);
        $member = User::query()->create([
            'tenant_id' => $owner->tenant_id,
            'name' => 'Member',
            'email' => 'member-team@example.test',
            'password' => bcrypt('secret123'),
        ]);

        $response = $this->postJson('/api/teams', [
            'name' => 'Ventas Norte',
            'description' => 'Equipo comercial',
            'manager_uid' => $manager->uid,
            'member_uids' => [$member->uid],
            'is_active' => true,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.manager_uid', $manager->uid)
            ->assertJsonPath('data.member_uids.0', $member->uid);

        $uid = $response->json('data.uid');

        $this->putJson('/api/teams/'.$uid, ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
    }

    public function test_automation_rules_assignment_rules_and_engine_are_available(): void
    {
        Mail::fake();
        Http::fake();

        $owner = $this->authenticateWithPermissions([
            'automation.read',
            'automation.create',
            'automation.update',
            'automation.delete',
        ]);
        $assigned = User::query()->create([
            'tenant_id' => $owner->tenant_id,
            'name' => 'Asignado',
            'email' => 'asignado-auto@example.test',
            'password' => bcrypt('secret123'),
        ]);

        $rule = $this->postJson('/api/automation/rules', [
            'name' => 'Lead web',
            'trigger_source' => 'lead_created',
            'conditions' => [
                ['field' => 'source', 'operator' => 'equals', 'value' => 'web_form'],
            ],
            'actions' => [
                ['type' => 'create_task', 'config' => ['title' => 'Contactar lead', 'priority' => 'high']],
            ],
            'logic' => 'AND',
            'is_active' => true,
        ]);

        $rule->assertCreated()->assertJsonPath('data.execution_count', 0);

        $assignment = $this->postJson('/api/automation/assignment-rules', [
            'name' => 'Pais Colombia',
            'conditions' => [
                ['field' => 'country', 'operator' => 'equals', 'value' => 'Colombia'],
            ],
            'assigned_to_uid' => $assigned->uid,
            'logic' => 'AND',
            'is_active' => true,
        ]);

        $assignment
            ->assertCreated()
            ->assertJsonPath('data.assigned_to_uid', $assigned->uid)
            ->assertJsonPath('data.assigned_to_name', 'Asignado');

        $result = app(AutomationService::class)->execute('lead_created', ['source' => 'web_form']);

        $this->assertSame(1, $result['executed']);
        $this->assertSame(1, Activity::query()->where('title', 'Contactar lead')->count());
        $this->assertSame(1, AutomationRule::query()->first()->execution_count);
    }

    public function test_automation_accepts_frontend_trigger_source_event_and_assignment_user_ids(): void
    {
        $owner = $this->authenticateWithPermissions([
            'automation.read',
            'automation.create',
        ]);
        $first = User::query()->create([
            'tenant_id' => $owner->tenant_id,
            'name' => 'Vendedor Uno',
            'email' => 'vendedor-uno-auto@example.test',
            'password' => bcrypt('secret123'),
        ]);
        $second = User::query()->create([
            'tenant_id' => $owner->tenant_id,
            'name' => 'Vendedor Dos',
            'email' => 'vendedor-dos-auto@example.test',
            'password' => bcrypt('secret123'),
        ]);

        $this->postJson('/api/automation/rules', [
            'name' => 'Lead CRM',
            'trigger_source' => 'crm',
            'trigger_event' => 'lead_created',
            'actions' => [
                ['type' => 'create_task', 'config' => ['title' => 'Gestionar lead CRM']],
            ],
            'is_active' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('data.trigger_source', 'crm')
            ->assertJsonPath('data.trigger_event', 'lead_created');

        $this->postJson('/api/automation/assignment-rules', [
            'name' => 'Asignacion multiple',
            'user_ids' => [$first->uid, $second->uid],
            'conditions' => [
                ['field' => 'source', 'operator' => 'equals', 'value' => 'crm'],
            ],
            'is_active' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('data.assigned_to_uid', $first->uid)
            ->assertJsonPath('data.user_ids.0', $first->uid)
            ->assertJsonPath('data.user_ids.1', $second->uid);

        $result = app(AutomationService::class)->execute('lead_created', ['source' => 'crm']);

        $this->assertSame(1, $result['executed']);
        $this->assertSame(1, Activity::query()->where('title', 'Gestionar lead CRM')->count());
    }

    public function test_automation_actions_endpoint_returns_supported_action_options(): void
    {
        $this->authenticateWithPermissions(['automation.read']);

        $this->getJson('/api/automation/trigger-events')
            ->assertOk()
            ->assertJsonPath('data.sources.crm', 'CRM')
            ->assertJsonPath('data.sources.linkedin', 'LinkedIn')
            ->assertJsonPath('data.events.lead_created', 'Lead Created')
            ->assertJsonPath('data.mappings.crm.0', 'lead_created')
            ->assertJsonPath('data.mappings.time.0', 'stage_duration_exceeded');

        $this->getJson('/api/automation/actions')
            ->assertOk()
            ->assertJsonFragment(['value' => 'send_email', 'label' => 'Send Email'])
            ->assertJsonFragment(['value' => 'create_lead', 'label' => 'Create Lead'])
            ->assertJsonFragment(['value' => 'assign_owner', 'label' => 'Assign Owner'])
            ->assertJsonFragment(['value' => 'create_activity', 'label' => 'Create Activity'])
            ->assertJsonFragment(['value' => 'apply_tag', 'label' => 'Apply Tag'])
            ->assertJsonFragment(['value' => 'send_notification', 'label' => 'Send Notification']);
    }

    public function test_automation_supports_frontend_action_types(): void
    {
        $owner = $this->authenticateWithPermissions([
            'automation.read',
            'automation.create',
        ]);
        $assigned = User::query()->create([
            'tenant_id' => $owner->tenant_id,
            'name' => 'Vendedor Automation',
            'email' => 'vendedor-actions-auto@example.test',
            'password' => bcrypt('secret123'),
        ]);
        $account = Account::query()->create([
            'tenant_id' => $owner->tenant_id,
            'owner_user_id' => $owner->getKey(),
            'name' => 'Cuenta Automation',
            'document' => 'AUTO-'.uniqid(),
        ]);
        $tag = Tag::query()->create([
            'tenant_id' => $owner->tenant_id,
            'name' => 'Prioritario',
            'key' => 'priority',
            'color' => '#ff0000',
            'category' => 'automation',
        ]);

        $this->postJson('/api/automation/assignment-rules', [
            'name' => 'Lead origen CRM',
            'user_ids' => [$assigned->uid],
            'conditions' => [
                ['field' => 'source', 'operator' => 'equals', 'value' => 'crm'],
            ],
            'is_active' => true,
        ])->assertCreated();

        $this->postJson('/api/automation/rules', [
            'name' => 'Acciones frontend',
            'trigger_source' => 'crm',
            'trigger_event' => 'lead_created',
            'conditions' => [
                ['field' => 'source', 'operator' => 'equals', 'value' => 'crm'],
            ],
            'actions' => [
                ['type' => 'create_lead', 'config' => ['company_name' => 'Lead creado por automation']],
                ['type' => 'assign_owner', 'config' => ['entity_type' => 'account', 'entity_uid' => $account->uid]],
                ['type' => 'create_activity', 'config' => [
                    'entity_type' => 'account',
                    'entity_uid' => $account->uid,
                    'assigned_to_uid' => $assigned->uid,
                    'type' => 'call',
                    'title' => 'Seguimiento automatico',
                ]],
                ['type' => 'apply_tag', 'config' => [
                    'entity_type' => 'account',
                    'entity_uid' => $account->uid,
                    'tag_uid' => $tag->uid,
                ]],
                ['type' => 'send_notification', 'config' => [
                    'entity_type' => 'account',
                    'entity_uid' => $account->uid,
                    'to_user_uid' => $assigned->uid,
                    'title' => 'Notificacion interna',
                    'message' => 'Hay un lead listo para gestionar',
                ]],
            ],
            'logic' => 'AND',
            'is_active' => true,
        ])->assertCreated();

        $result = app(AutomationService::class)->execute('lead_created', ['source' => 'crm']);

        $this->assertSame(1, $result['executed']);
        $this->assertSame(
            ['create_lead', 'assign_owner', 'create_activity', 'apply_tag', 'send_notification'],
            collect($result['results']->first()['actions'])->pluck('type')->all()
        );
        $this->assertSame(
            [true, true, true, true, true],
            collect($result['results']->first()['actions'])
                ->map(fn (array $action) => $action['success'] ? true : ($action['message'] ?? false))
                ->all()
        );
        $this->assertDatabaseHas('crm_entities', [
            'tenant_id' => $owner->tenant_id,
            'owner_user_id' => $assigned->getKey(),
        ]);
        $this->assertSame($assigned->getKey(), $account->fresh()->owner_user_id);
        $this->assertTrue($account->fresh()->tags()->where('tags.uid', $tag->uid)->exists());
        $this->assertSame(1, Activity::withoutGlobalScope('row_level_security')->where('title', 'Seguimiento automatico')->count());
        $this->assertSame(1, Activity::withoutGlobalScope('row_level_security')->where('title', 'Notificacion interna')->where('assigned_user_id', $assigned->getKey())->count());
        $this->assertSame('Lead creado por automation', CrmEntity::withoutGlobalScope('row_level_security')->first()->profile_data['company_name']);
    }

    private function authenticateWithPermissions(array $permissionKeys): User
    {
        $tenant = Tenant::query()->create([
            'name' => 'Tenant Structural',
            'status' => 'active',
            'is_active' => true,
        ]);

        foreach ($permissionKeys as $key) {
            Permission::query()->firstOrCreate(
                ['key' => $key],
                [
                    'module' => str_contains($key, '.') ? explode('.', $key)[0] : 'structural',
                    'action' => $key,
                    'description' => $key,
                ]
            );
        }

        $user = User::query()->create([
            'tenant_id' => $tenant->getKey(),
            'name' => 'Structural Owner',
            'email' => 'structural-owner+'.uniqid().'@example.test',
            'password' => bcrypt('secret123'),
        ]);

        $permissionIds = Permission::query()->whereIn('key', $permissionKeys)->pluck('id')->all();
        $user->permissions()->sync($permissionIds);

        Sanctum::actingAs($user, ['access:full', 'tenant:'.$tenant->uid]);

        return $user;
    }
}
