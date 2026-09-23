<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\AutomationAssignmentRule;
use App\Models\AutomationRule;
use App\Models\CrmEntity;
use App\Models\Tag;
use App\Models\User;
use App\Support\ApiIndex;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AutomationService
{
    private const TRIGGERS = 'lead_created,lead_updated,lead_stage_changed,lead_assigned,lead_lost,lead_won,lead_stalled,opportunity_created,opportunity_stage_changed,deal_won,deal_lost,contact_updated,task_completed,linkedin_message,linkedin_reply,linkedin_connection,facebook_comment,facebook_message,facebook_like,stage_duration_exceeded,inactivity_days';

    private const TRIGGER_SOURCES = 'crm,linkedin,facebook,time';

    private const ACTIONS = 'send_email,update_field,create_task,send_webhook,create_lead,assign_owner,create_activity,apply_tag,send_notification';

    public function __construct(private readonly ConditionEvaluator $conditionEvaluator) {}

    public function listRules(array $filters = [])
    {
        return ApiIndex::paginateOrGet(AutomationRule::query()->latest(), $filters, 'automation_rules_page');
    }

    public function getRule(string $uid): AutomationRule
    {
        return AutomationRule::query()->where('uid', $uid)->firstOrFail();
    }

    public function createRule(array $data): AutomationRule
    {
        return AutomationRule::query()->create($this->validateRule($data))->fresh();
    }

    public function updateRule(string $uid, array $data): AutomationRule
    {
        $rule = $this->getRule($uid);
        $rule->update($this->validateRule($data, true));

        return $rule->fresh();
    }

    public function deleteRule(string $uid): void
    {
        $this->getRule($uid)->delete();
    }

    public function toggleRule(string $uid): AutomationRule
    {
        $rule = $this->getRule($uid);
        $rule->update(['is_active' => ! $rule->is_active]);

        return $rule->fresh();
    }

    public function listAssignmentRules(array $filters = [])
    {
        return ApiIndex::paginateOrGet(
            AutomationAssignmentRule::query()->with('assignedTo')->latest(),
            $filters,
            'automation_assignment_rules_page'
        );
    }

    public function createAssignmentRule(array $data): AutomationAssignmentRule
    {
        return AutomationAssignmentRule::query()->create($this->validateAssignmentRule($data))->fresh('assignedTo');
    }

    public function updateAssignmentRule(string $uid, array $data): AutomationAssignmentRule
    {
        $rule = AutomationAssignmentRule::query()->with('assignedTo')->where('uid', $uid)->firstOrFail();
        $rule->update($this->validateAssignmentRule($data, true));

        return $rule->fresh('assignedTo');
    }

    public function deleteAssignmentRule(string $uid): void
    {
        AutomationAssignmentRule::query()->where('uid', $uid)->firstOrFail()->delete();
    }

    public function execute(string $triggerSource, array $payload = []): array
    {
        $triggerSources = $this->equivalentTriggerSources($triggerSource);

        $results = AutomationRule::query()
            ->where('is_active', true)
            ->where(function ($query) use ($triggerSources) {
                $query->whereIn('trigger_event', $triggerSources)
                    ->orWhere(function ($legacyQuery) use ($triggerSources) {
                        $legacyQuery
                            ->whereNull('trigger_event')
                            ->whereIn('trigger_source', $triggerSources);
                    });
            })
            ->get()
            ->map(function (AutomationRule $rule) use ($payload) {
                $matched = $this->conditionEvaluator->matches($rule->conditions ?? [], $payload, $rule->logic);
                $actions = [];

                if ($matched) {
                    foreach ($rule->actions ?? [] as $action) {
                        $actions[] = $this->executeAction($action, $payload);
                    }

                    $rule->forceFill([
                        'execution_count' => (int) $rule->execution_count + 1,
                        'last_executed_at' => now(),
                    ])->save();
                }

                return [
                    'uid' => $rule->uid,
                    'name' => $rule->name,
                    'matched' => $matched,
                    'actions' => $actions,
                ];
            })
            ->values();

        return [
            'trigger_source' => $triggerSource,
            'evaluated' => $results->count(),
            'executed' => $results->where('matched', true)->count(),
            'results' => $results,
        ];
    }

    public function triggerEvents(): array
    {
        $sources = [
            'crm' => 'CRM',
            'linkedin' => 'LinkedIn',
            'facebook' => 'Facebook',
            'time' => 'Tiempo',
        ];
        $events = collect(explode(',', self::TRIGGERS))
            ->mapWithKeys(fn (string $trigger) => [$trigger => str($trigger)->replace('_', ' ')->title()->toString()])
            ->all();

        return [
            'sources' => $sources,
            'events' => $events,
            'mappings' => [
                'crm' => [
                    'lead_created',
                    'lead_updated',
                    'lead_stage_changed',
                    'lead_assigned',
                    'lead_lost',
                    'lead_won',
                    'lead_stalled',
                    'opportunity_created',
                    'opportunity_stage_changed',
                    'deal_won',
                    'deal_lost',
                    'contact_updated',
                    'task_completed',
                ],
                'linkedin' => [
                    'linkedin_message',
                    'linkedin_reply',
                    'linkedin_connection',
                ],
                'facebook' => [
                    'facebook_comment',
                    'facebook_message',
                    'facebook_like',
                ],
                'time' => [
                    'stage_duration_exceeded',
                    'inactivity_days',
                ],
            ],
        ];
    }

    public function actions(): array
    {
        return collect(explode(',', self::ACTIONS))
            ->map(fn (string $action) => [
                'value' => $action,
                'label' => str($action)->replace('_', ' ')->title()->toString(),
            ])
            ->values()
            ->all();
    }

    public function resolveAssignment(array $payload): ?AutomationAssignmentRule
    {
        return AutomationAssignmentRule::query()
            ->with('assignedTo')
            ->where('is_active', true)
            ->get()
            ->first(fn (AutomationAssignmentRule $rule) => $this->conditionEvaluator->matches($rule->conditions ?? [], $payload, $rule->logic));
    }

    private function validateRule(array $data, bool $partial = false): array
    {
        $validated = Validator::make($data, [
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'description' => 'nullable|string',
            'trigger_source' => [$partial ? 'sometimes' : 'required', 'string', 'in:'.self::TRIGGERS.','.self::TRIGGER_SOURCES],
            'trigger_event' => 'sometimes|string|in:'.self::TRIGGERS,
            'trigger_config' => 'sometimes|array',
            'conditions' => 'sometimes|array',
            // nested conditions (frontend contract)
            'conditions.*.uid' => 'nullable|string',
            'conditions.*.logic' => 'nullable|string|in:AND,OR',
            'conditions.*.conditions' => 'nullable|array',
            'conditions.*.conditions.*.uid' => 'nullable|string',
            'conditions.*.conditions.*.field' => 'required_with:conditions.*.conditions|string|max:255',
            'conditions.*.conditions.*.operator' => 'required_with:conditions.*.conditions|string|in:equals,not_equals,contains,not_contains,gt,gte,lt,lte,exists,not_exists',
            'conditions.*.conditions.*.value' => 'nullable',
            // flat conditions (legacy support)
            'conditions.*.field' => 'nullable|string|max:255',
            'conditions.*.operator' => 'nullable|string|in:equals,not_equals,contains,not_contains,gt,gte,lt,lte,exists,not_exists',
            'conditions.*.value' => 'nullable',
            'actions' => [$partial ? 'sometimes' : 'required', 'array', 'min:1'],
            'actions.*.uid' => 'nullable|string',
            'actions.*.sequence' => 'nullable|integer',
            'actions.*.type' => 'required_with:actions|string|in:'.self::ACTIONS,
            'actions.*.config' => 'sometimes|array',
            'logic' => 'sometimes|string|in:AND,OR',
            'is_active' => 'sometimes|boolean',
        ])->validate();

        return $this->normalizeTriggerContract($validated, $partial);
    }

    private function validateAssignmentRule(array $data, bool $partial = false): array
    {
        $validated = Validator::make($data, [
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'description' => 'nullable|string',
            'conditions' => 'sometimes|array',
            'conditions.*.field' => 'required_with:conditions|string|max:255',
            'conditions.*.operator' => 'required_with:conditions|string|in:equals,not_equals,contains,not_contains,gt,gte,lt,lte,exists,not_exists',
            'conditions.*.value' => 'nullable',
            'assigned_to_uid' => [$partial ? 'sometimes' : 'required_without:user_ids', 'uuid'],
            'assigned_to_name' => 'sometimes|string|max:255',
            'user_ids' => 'sometimes|array|min:1',
            'user_ids.*' => 'uuid',
            'logic' => 'sometimes|string|in:AND,OR',
            'is_active' => 'sometimes|boolean',
        ])->validate();

        if (array_key_exists('user_ids', $validated)) {
            $userIds = $this->resolveUserIds($validated['user_ids']);
            $validated['assigned_user_ids'] = $userIds;
            $validated['assigned_to_user_id'] = $userIds[0];
            unset($validated['user_ids'], $validated['assigned_to_uid'], $validated['assigned_to_name']);

            return $validated;
        }

        if (array_key_exists('assigned_to_uid', $validated)) {
            $userId = $this->resolveUserId($validated['assigned_to_uid']);
            $validated['assigned_to_user_id'] = $userId;
            $validated['assigned_user_ids'] = [$userId];
            unset($validated['assigned_to_uid'], $validated['assigned_to_name']);
        }

        return $validated;
    }

    private function normalizeTriggerContract(array $validated, bool $partial): array
    {
        if (! array_key_exists('trigger_source', $validated)) {
            return $validated;
        }

        $source = $validated['trigger_source'];
        $events = explode(',', self::TRIGGERS);
        $sources = explode(',', self::TRIGGER_SOURCES);

        if (in_array($source, $sources, true)) {
            if (empty($validated['trigger_event'])) {
                throw ValidationException::withMessages([
                    'trigger_event' => ['El evento es requerido cuando trigger_source es una fuente'],
                ]);
            }

            return $validated;
        }

        if (in_array($source, $events, true) && ! array_key_exists('trigger_event', $validated)) {
            $validated['trigger_event'] = $source;
        }

        return $validated;
    }

    private function executeAction(array $action, array $payload): array
    {
        try {
            return match ($action['type'] ?? null) {
                'send_email' => $this->sendEmail($action['config'] ?? []),
                'update_field' => $this->updateField($action['config'] ?? [], $payload),
                'create_task' => $this->createTask($action['config'] ?? [], $payload),
                'send_webhook' => $this->sendWebhook($action['config'] ?? [], $payload),
                'create_lead' => $this->createLead($action['config'] ?? [], $payload),
                'assign_owner' => $this->assignOwner($action['config'] ?? [], $payload),
                'create_activity' => $this->createActivity($action['config'] ?? [], $payload),
                'apply_tag' => $this->applyTag($action['config'] ?? [], $payload),
                'send_notification' => $this->sendNotification($action['config'] ?? [], $payload),
                default => ['type' => $action['type'] ?? null, 'success' => false],
            };
        } catch (\Throwable $e) {
            Log::warning('Automation action failed', ['error' => $e->getMessage()]);

            return ['type' => $action['type'] ?? null, 'success' => false, 'message' => $e->getMessage()];
        }
    }

    private function equivalentTriggerSources(string $triggerSource): array
    {
        $groups = [
            ['lead_created', 'opportunity_created'],
            ['lead_stage_changed', 'opportunity_stage_changed', 'deal_won', 'deal_lost'],
            ['lead_updated', 'contact_updated'],
            ['lead_assigned'],
            ['task_completed'],
        ];

        foreach ($groups as $group) {
            if (in_array($triggerSource, $group, true)) {
                return $group;
            }
        }

        return [$triggerSource];
    }

    private function sendEmail(array $config): array
    {
        if (empty($config['to'])) {
            return ['type' => 'send_email', 'success' => false, 'message' => 'Missing recipient'];
        }

        Mail::raw($config['body'] ?? 'Automation triggered: '.($config['template'] ?? 'default'), function ($message) use ($config) {
            $message->to($config['to'])->subject($config['subject'] ?? 'Automation notification');
        });

        return ['type' => 'send_email', 'success' => true];
    }

    private function updateField(array $config, array $payload): array
    {
        $entity = $this->resolveAutomationEntity($config, $payload);

        $field = $config['field'] ?? $config['field_name'] ?? null;
        $value = $config['value'] ?? $config['field_value'] ?? null;

        if (! $entity || ! $field || ! in_array($field, $entity->getFillable(), true)) {
            return ['type' => 'update_field', 'success' => false];
        }

        $entity->update([$field => $value]);

        return ['type' => 'update_field', 'success' => true];
    }

    private function createTask(array $config, array $payload): array
    {
        $config['type'] = 'task';

        return array_merge(['type' => 'create_task'], collect($this->createActivity($config, $payload))->except('type')->all());
    }

    private function createLead(array $config, array $payload): array
    {
        $leadType = $config['lead_type'] ?? $config['type'] ?? 'B2B';
        $leadType = in_array($leadType, ['B2B', 'B2C', 'B2G'], true) ? $leadType : 'B2B';

        $profileData = array_merge(
            [
                'company_name' => $config['company_name'] ?? data_get($payload, 'company_name') ?? data_get($payload, 'name'),
                'first_name' => $config['first_name'] ?? data_get($payload, 'first_name'),
                'last_name' => $config['last_name'] ?? data_get($payload, 'last_name'),
                'email' => $config['email'] ?? data_get($payload, 'email'),
                'phone' => $config['phone'] ?? data_get($payload, 'phone'),
                'source' => $config['source'] ?? data_get($payload, 'source'),
            ],
            $config['profile_data'] ?? []
        );

        $lead = CrmEntity::query()->create([
            'tenant_id' => auth()->user()?->tenant_id,
            'owner_user_id' => $this->resolveActionUserId($config, $payload, true) ?? auth()->id(),
            'type' => $leadType,
            'profile_data' => collect($profileData)->filter(fn ($value) => $value !== null && $value !== '')->all(),
        ]);

        return ['type' => 'create_lead', 'success' => true, 'lead_uid' => $lead->uid];
    }

    private function assignOwner(array $config, array $payload): array
    {
        $entity = $this->resolveAutomationEntity($config, $payload);

        if (! $entity || ! in_array('owner_user_id', $entity->getFillable(), true)) {
            return ['type' => 'assign_owner', 'success' => false, 'message' => 'Entity cannot be assigned'];
        }

        $userId = $this->resolveActionUserId($config, $payload, true);

        if (! $userId) {
            return ['type' => 'assign_owner', 'success' => false, 'message' => 'No assignment matched'];
        }

        $entity->update(['owner_user_id' => $userId]);

        return ['type' => 'assign_owner', 'success' => true, 'entity_uid' => $entity->uid];
    }

    private function createActivity(array $config, array $payload): array
    {
        $entity = $this->resolveAutomationEntity($config, $payload);
        $activity = Activity::query()->create([
            'assigned_user_id' => $this->resolveActionUserId($config, $payload),
            'activityable_type' => $entity ? get_class($entity) : null,
            'activityable_id' => $entity?->getKey(),
            'owner_user_id' => $entity->owner_user_id ?? auth()->id(),
            'type' => $config['type'] ?? 'task',
            'title' => $config['title'] ?? 'Automation activity',
            'description' => $config['description'] ?? null,
            'status' => $config['status'] ?? 'pending',
            'priority' => $config['priority'] ?? 'medium',
            'scheduled_at' => $config['scheduled_at'] ?? now(),
        ]);

        return ['type' => 'create_activity', 'success' => true, 'activity_uid' => $activity->uid];
    }

    private function applyTag(array $config, array $payload): array
    {
        $entity = $this->resolveAutomationEntity($config, $payload);
        $tag = $this->resolveTag($config);

        if (! $entity) {
            return ['type' => 'apply_tag', 'success' => false, 'message' => 'Entity not found'];
        }

        if (! $tag) {
            return ['type' => 'apply_tag', 'success' => false, 'message' => 'Tag not found'];
        }

        if (! method_exists($entity, 'tags')) {
            return ['type' => 'apply_tag', 'success' => false, 'message' => 'Entity does not support tags'];
        }

        $entity->tags()->syncWithoutDetaching([$tag->getKey()]);

        return ['type' => 'apply_tag', 'success' => true, 'tag_uid' => $tag->uid, 'entity_uid' => $entity->uid];
    }

    private function sendNotification(array $config, array $payload): array
    {
        $entity = $this->resolveAutomationEntity($config, $payload);
        $assignedUserId = $this->resolveActionUserId($config, $payload, true) ?? auth()->id();

        $activity = Activity::query()->create([
            'assigned_user_id' => $assignedUserId,
            'activityable_type' => $entity ? get_class($entity) : null,
            'activityable_id' => $entity?->getKey(),
            'owner_user_id' => auth()->id(),
            'type' => 'note',
            'title' => $config['title'] ?? 'Automation notification',
            'description' => $config['message'] ?? $config['description'] ?? null,
            'status' => 'pending',
            'priority' => $config['priority'] ?? 'medium',
            'scheduled_at' => now(),
        ]);

        return ['type' => 'send_notification', 'success' => true, 'notification_uid' => $activity->uid];
    }

    private function resolveActionUserId(array $config, array $payload = [], bool $allowAssignmentRule = false): ?int
    {
        foreach (['assigned_to_uid', 'owner_uid', 'user_uid', 'to_user_uid'] as $key) {
            if (! empty($config[$key])) {
                return $this->resolveUserId($config[$key]);
            }
        }

        if ($allowAssignmentRule) {
            return $this->resolveAssignment($payload)?->assigned_to_user_id;
        }

        return null;
    }

    private function sendWebhook(array $config, array $payload): array
    {
        if (empty($config['url'])) {
            return ['type' => 'send_webhook', 'success' => false, 'message' => 'Missing URL'];
        }

        Http::timeout(5)->post($config['url'], ['payload' => $payload, 'config' => $config]);

        return ['type' => 'send_webhook', 'success' => true];
    }

    private function resolveAutomationEntity(array $config, array $payload)
    {
        $entityType = $config['entity_type'] ?? data_get($payload, 'entity_type');
        $entityUid = $config['entity_uid']
            ?? data_get($payload, 'entity_uid')
            ?? data_get($payload, 'lead_uid')
            ?? data_get($payload, 'opportunity_uid');

        if (! $entityType && data_get($payload, 'opportunity_uid')) {
            $entityType = 'opportunity';
        }

        if (! $entityType || ! $entityUid) {
            return null;
        }

        $modelClass = crm_entity_model_class($entityType);

        if (! $modelClass) {
            return null;
        }

        return $modelClass::query()
            ->withoutGlobalScope('row_level_security')
            ->where('uid', $entityUid)
            ->first();
    }

    private function resolveTag(array $config): ?Tag
    {
        if (! empty($config['tag_uid'])) {
            return Tag::query()->where('uid', $config['tag_uid'])->first();
        }

        if (! empty($config['tag_key'])) {
            return Tag::query()->where('key', $config['tag_key'])->first();
        }

        if (! empty($config['tag_name'])) {
            return Tag::query()->where('name', $config['tag_name'])->first();
        }

        if (! empty($config['tag'])) {
            return Tag::query()
                ->where('uid', $config['tag'])
                ->orWhere('key', $config['tag'])
                ->orWhere('name', $config['tag'])
                ->first();
        }

        return null;
    }

    private function resolveUserId(?string $uid): ?int
    {
        if (! $uid) {
            return null;
        }

        $id = User::query()->where('uid', $uid)->value('id');

        if (! $id) {
            throw ValidationException::withMessages([
                'assigned_to_uid' => ['El usuario asignado no existe o no pertenece a este tenant'],
            ]);
        }

        return $id;
    }

    private function resolveUserIds(array $uids): array
    {
        $uniqueUids = array_values(array_unique($uids));
        $users = User::query()
            ->whereIn('uid', $uniqueUids)
            ->get(['id', 'uid']);

        if ($users->count() !== count($uniqueUids)) {
            throw ValidationException::withMessages([
                'user_ids' => ['Uno o mas usuarios asignados no existen o no pertenecen a este tenant'],
            ]);
        }

        return collect($uniqueUids)
            ->map(fn (string $uid) => (int) $users->firstWhere('uid', $uid)->id)
            ->all();
    }
}
