<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Activity;
use App\Models\Competitor;
use App\Models\Contact;
use App\Models\CrmEntity;
use App\Models\CustomField;
use App\Models\Invoice;
use App\Models\LostReason;
use App\Models\Opportunity;
use App\Models\OpportunityStage;
use App\Models\Project;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\Tenant;
use App\Models\User;
use App\Support\ApiIndex;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use ZipArchive;

class OpportunityService
{
    public function __construct(
        private readonly ProjectService $projectService,
        private readonly ExportService $exportService,
        private readonly CompetitiveIntelligenceService $competitiveIntelligenceService,
        private readonly ActivityService $activityService,
        private readonly CustomFieldService $customFieldService
    ) {}

    public function stages()
    {
        return OpportunityStage::query()->orderBy('position')->get();
    }

    public function createStage(array $data): OpportunityStage
    {
        $validated = $this->validateStage($data);

        return OpportunityStage::query()->create($validated);
    }

    public function updateStage(string $uid, array $data): OpportunityStage
    {
        $stage = OpportunityStage::query()->where('uid', $uid)->firstOrFail();
        $validated = $this->validateStage($data, true);
        $stage->update($validated);

        return $stage->fresh();
    }

    public function deleteStage(string $uid): void
    {
        $stage = OpportunityStage::query()->where('uid', $uid)->firstOrFail();

        if ($stage->opportunities()->exists()) {
            throw ValidationException::withMessages([
                'stage' => ['No puedes eliminar una etapa con oportunidades asociadas'],
            ]);
        }

        $stage->delete();
    }

    public function opportunities(array $filters = [])
    {
        $validated = Validator::make($filters, [
            'stage_uid' => 'nullable|uuid',
            'owner_user_uid' => 'nullable|uuid',
            'search' => 'nullable|string|max:255',
        ])->validate();

        $query = Opportunity::query()
            ->with(['stage', 'owner', 'customFieldValues.customField'])
            ->when(! empty($validated['stage_uid']), function ($query) use ($validated) {
                $stage = OpportunityStage::query()->where('uid', $validated['stage_uid'])->first();

                if ($stage && $this->isWonClosingStage($stage)) {
                    $this->applyClosedOpportunityFilter($query);

                    return;
                }

                if ($stage && $this->isLostStage($stage)) {
                    $this->applyLostOpportunityFilter($query);

                    return;
                }

                $query->where('stage_id', $stage?->getKey() ?: 0)
                    ->whereNull('won_at')
                    ->whereNull('lost_at');
            })
            ->when(! empty($validated['owner_user_uid']), function ($query) use ($validated) {
                $ownerId = User::query()->where('uid', $validated['owner_user_uid'])->value('id');
                $query->where('owner_user_id', $ownerId ?: 0);
            })
            ->when(! empty($validated['search']), fn ($query) => $this->applyOpportunitySearch($query, $validated['search']))
            ->orderByDesc('created_at');

        $result = ApiIndex::paginateOrGet($query, $filters, 'opportunities_page');

        return $this->mapOpportunityIndexResult($result);
    }

    public function history(array $filters = [])
    {
        $validated = Validator::make($filters, [
            'stage_uid' => 'nullable|uuid',
            'owner_user_uid' => 'nullable|uuid',
            'search' => 'nullable|string|max:255',
            'origin' => 'nullable|string|max:255',
            'status' => 'nullable|string|in:active,open,closed,won,lost',
            'created_from' => 'nullable|date',
            'created_to' => 'nullable|date',
            'closed_from' => 'nullable|date',
            'closed_to' => 'nullable|date',
        ])->validate();

        $query = Opportunity::query()
            ->with(['stage', 'owner', 'customFieldValues.customField']);

        if (! empty($validated['stage_uid'])) {
            $stage = OpportunityStage::query()->where('uid', $validated['stage_uid'])->first();
            $query->where('stage_id', $stage?->getKey() ?: 0);
        }

        if (! empty($validated['owner_user_uid'])) {
            $ownerId = User::query()->where('uid', $validated['owner_user_uid'])->value('id');
            $query->where('owner_user_id', $ownerId ?: 0);
        }

        if (! empty($validated['search'])) {
            $this->applyOpportunitySearch($query, $validated['search']);
        }

        if (! empty($validated['origin'])) {
            $this->applyOpportunityOriginFilter($query, $validated['origin']);
        }

        match ($validated['status'] ?? null) {
            'active', 'open' => $query->whereNull('won_at')->whereNull('lost_at'),
            'closed' => $this->applyClosedOpportunityFilter($query),
            'won' => $query->whereNotNull('won_at')->whereNull('lost_at'),
            'lost' => $query->whereNotNull('lost_at'),
            default => null,
        };

        if (! empty($validated['created_from'])) {
            $query->whereDate('created_at', '>=', $validated['created_from']);
        }

        if (! empty($validated['created_to'])) {
            $query->whereDate('created_at', '<=', $validated['created_to']);
        }

        if (! empty($validated['closed_from'])) {
            $query->where(function ($closedQuery) use ($validated) {
                $closedQuery
                    ->whereDate('won_at', '>=', $validated['closed_from'])
                    ->orWhereDate('lost_at', '>=', $validated['closed_from']);
            });
        }

        if (! empty($validated['closed_to'])) {
            $query->where(function ($closedQuery) use ($validated) {
                $closedQuery
                    ->whereDate('won_at', '<=', $validated['closed_to'])
                    ->orWhereDate('lost_at', '<=', $validated['closed_to']);
            });
        }

        $result = ApiIndex::paginateOrGet(
            $query->orderByRaw('COALESCE(won_at, lost_at, created_at) DESC')->orderByDesc('id'),
            $filters + ['page' => 1, 'per_page' => ApiIndex::perPage($filters)],
            'opportunities_history_page'
        );

        return $this->mapOpportunityIndexResult($result);
    }

    public function getOpportunity(string $uid): Opportunity
    {
        return Opportunity::query()
            ->with([
                'stage',
                'owner',
                'opportunityable',
                'customFieldValues.customField',
                'project',
                'lostReasons.competitor',
                'activities.owner',
                'activities.assignedUser',
                'quotations.items.product',
                'quotations.items.catalogProduct',
                'quotations.items.warehouse',
            ])
            ->where('uid', $uid)
            ->firstOrFail();
    }

    public function getOpportunityDetail(string $uid): array
    {
        return $this->serializeOpportunityDetail($this->getOpportunity($uid));
    }

    public function createOpportunity(array $data): Opportunity
    {
        $validated = $this->validateOpportunity($data);

        return DB::transaction(function () use ($validated) {
            $stage = $this->resolveStage($validated['stage_uid']);
            $entity = $this->resolveEntity($validated['entity_type'] ?? null, $validated['entity_uid'] ?? null);

            $payload = [
                'owner_user_id' => $this->resolveOwnerUserId($validated['owner_user_uid'] ?? null, $entity?->owner_user_id),
                'stage_id' => $stage->getKey(),
                'opportunityable_type' => $entity ? get_class($entity) : null,
                'opportunityable_id' => $entity?->getKey(),
                'title' => $validated['title'],
                'email' => $validated['email'] ?? null,
                'lead_origin' => $validated['lead_origin'] ?? null,
                'amount' => $validated['amount'] ?? 0,
                'currency' => $validated['currency'] ?? null,
                'expected_close_date' => $validated['expected_close_date'] ?? null,
                'description' => $validated['description'] ?? null,
                'won_at' => $this->isWonClosingStage($stage) ? now() : null,
                'lost_at' => $stage->is_lost ? now() : null,
            ];

            if ($this->supportsKanbanPosition()) {
                $payload['kanban_position'] = $this->nextKanbanPosition($stage->getKey());
            }

            $opportunity = Opportunity::query()->create($payload);

            $this->assignCustomFieldValues($opportunity, $validated['custom_fields'] ?? []);

            if ($this->isWonClosingStage($stage)) {
                $this->projectService->createFromOpportunityModel($opportunity->fresh(['opportunityable']), quietIfNoAccount: true);
            }

            return $opportunity->fresh(['stage', 'owner', 'opportunityable', 'customFieldValues.customField']);
        });
    }

    public function updateOpportunity(string $uid, array $data): Opportunity
    {
        $opportunity = Opportunity::query()->with(['stage', 'owner', 'opportunityable'])->where('uid', $uid)->firstOrFail();
        $validated = $this->validateOpportunity($data, true);

        return DB::transaction(function () use ($opportunity, $validated) {
            $payload = [];

            foreach (['title', 'email', 'lead_origin', 'amount', 'currency', 'expected_close_date', 'description'] as $field) {
                if (array_key_exists($field, $validated)) {
                    $payload[$field] = $validated[$field];
                }
            }

            if (array_key_exists('stage_uid', $validated)) {
                $stage = $this->resolveStage($validated['stage_uid']);
                $payload['stage_id'] = $stage->getKey();
                $payload['won_at'] = $this->isWonClosingStage($stage) ? now() : null;
                $payload['lost_at'] = $stage->is_lost ? now() : null;

                if ($this->supportsKanbanPosition()) {
                    $payload['kanban_position'] = $this->nextKanbanPosition($stage->getKey());
                }
            }

            if (array_key_exists('owner_user_uid', $validated)) {
                $payload['owner_user_id'] = $this->resolveOwnerUserId($validated['owner_user_uid']);
            }

            if (array_key_exists('entity_type', $validated) || array_key_exists('entity_uid', $validated)) {
                $entity = $this->resolveEntity($validated['entity_type'] ?? null, $validated['entity_uid'] ?? null);
                $payload['opportunityable_type'] = $entity ? get_class($entity) : null;
                $payload['opportunityable_id'] = $entity?->getKey();
            }

            $opportunity->update($payload);

            $this->assignCustomFieldValues($opportunity, $validated['custom_fields'] ?? []);

            $opportunity = $opportunity->fresh(['stage', 'owner', 'opportunityable', 'customFieldValues.customField']);

            if ($opportunity->stage && $this->isWonClosingStage($opportunity->stage)) {
                $this->projectService->createFromOpportunityModel($opportunity, quietIfNoAccount: true);
            }

            return $opportunity;
        });
    }

    public function markWon(string $uid, array $data = []): array
    {
        $validated = Validator::make($data, [
            'notes' => 'nullable|string|max:2000',
            'comment' => 'nullable|string|max:2000',
        ])->validate();

        return DB::transaction(function () use ($uid, $validated) {
            $opportunity = Opportunity::query()->where('uid', $uid)->firstOrFail();
            $stage = $this->resolveOutcomeStage($opportunity, 'won');
            $payload = [
                'stage_id' => $stage?->getKey() ?? $opportunity->stage_id,
                'won_at' => now(),
                'lost_at' => null,
            ];

            if ($this->supportsKanbanPosition()) {
                $payload['kanban_position'] = $this->nextKanbanPosition($stage?->getKey() ?? $opportunity->stage_id);
            }

            $opportunity->update($payload);

            $project = $this->projectService->createFromOpportunityModel($opportunity->fresh(['opportunityable']), quietIfNoAccount: true);
            $note = $validated['notes'] ?? $validated['comment'] ?? null;

            if ($note) {
                $this->activityService->create([
                    'type' => 'note',
                    'title' => 'Oportunidad marcada como ganada',
                    'description' => $note,
                    'status' => 'completed',
                    'scheduled_at' => now()->toDateTimeString(),
                    'entity_type' => 'opportunity',
                    'entity_uid' => $opportunity->uid,
                ]);
            }

            return [
                'opportunity' => $this->getOpportunityDetail($opportunity->uid),
                'project' => $project ? $this->serializeProject($project->fresh(['account', 'assignedUser'])) : null,
            ];
        });
    }

    public function markLost(string $uid, array $data = []): array
    {
        $validated = Validator::make($data, [
            'lost_reasons' => 'nullable|array',
            'lost_reasons.*.category' => 'nullable|string|max:255',
            'lost_reasons.*.reason_type' => 'nullable|string',
            'lost_reasons.*.competitor_uid' => 'nullable|uuid',
            'lost_reasons.*.competitor' => 'nullable|array',
            'lost_reasons.*.competitor.name' => 'required_with:lost_reasons.*.competitor|string|max:255',
            'lost_reasons.*.competitor.type' => 'nullable|string|max:100',
            'lost_reasons.*.competitor.description' => 'nullable|string',
            'lost_reasons.*.detail' => 'nullable|string',
            'lost_reasons.*.details' => 'nullable|string',
            'lost_reasons.*.notes' => 'nullable|string',
            'lost_reasons.*.summary' => 'nullable|string|max:255',
            'reasons' => 'nullable|array',
            'reasons.*.category' => 'nullable|string|max:255',
            'reasons.*.reason_type' => 'nullable|string',
            'reasons.*.competitor_uid' => 'nullable|uuid',
            'reasons.*.competitor' => 'nullable|array',
            'reasons.*.competitor.name' => 'required_with:reasons.*.competitor|string|max:255',
            'reasons.*.competitor.type' => 'nullable|string|max:100',
            'reasons.*.competitor.description' => 'nullable|string',
            'reasons.*.detail' => 'nullable|string',
            'reasons.*.details' => 'nullable|string',
            'reasons.*.notes' => 'nullable|string',
            'reasons.*.summary' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:2000',
            'comment' => 'nullable|string|max:2000',
        ])->validate();

        return DB::transaction(function () use ($uid, $validated) {
            $opportunity = Opportunity::query()->where('uid', $uid)->firstOrFail();

            if ($opportunity->lost_at || $opportunity->lostReasons()->exists()) {
                throw ValidationException::withMessages([
                    'opportunity' => ['La oportunidad ya tiene una perdida registrada'],
                ]);
            }

            $stage = $this->resolveOutcomeStage($opportunity, 'lost');
            $payload = [
                'stage_id' => $stage?->getKey() ?? $opportunity->stage_id,
                'won_at' => null,
                'lost_at' => now(),
            ];

            if ($this->supportsKanbanPosition()) {
                $payload['kanban_position'] = $this->nextKanbanPosition($stage?->getKey() ?? $opportunity->stage_id);
            }

            $opportunity->update($payload);

            $reasons = $validated['lost_reasons'] ?? $validated['reasons'] ?? [];
            $createdReasons = collect($reasons)
                ->map(fn (array $reason) => $this->competitiveIntelligenceService->createLostReason(
                    $this->lostReasonPayload($opportunity, $reason)
                ))
                ->values();

            $note = $validated['notes'] ?? $validated['comment'] ?? null;

            if ($note) {
                $this->activityService->create([
                    'type' => 'note',
                    'title' => 'Oportunidad marcada como perdida',
                    'description' => $note,
                    'status' => 'completed',
                    'scheduled_at' => now()->toDateTimeString(),
                    'entity_type' => 'opportunity',
                    'entity_uid' => $opportunity->uid,
                ]);
            }

            return [
                'opportunity' => $this->getOpportunityDetail($opportunity->uid),
                'lost_reasons' => $createdReasons
                    ->map(fn (LostReason $reason) => $this->serializeLostReason($reason->fresh(['competitor'])))
                    ->values(),
            ];
        });
    }

    private function lostReasonPayload(Opportunity $opportunity, array $reason): array
    {
        $details = $reason['detail'] ?? $reason['details'] ?? $reason['notes'] ?? null;
        $category = $reason['category'] ?? null;
        $competitorUid = $this->resolveLostReasonCompetitorUid($reason);

        $payload = [
            'opportunity_uid' => $opportunity->uid,
            'lost_reason_category' => $category,
            'competitor_uid' => $competitorUid,
            'lost_reason_detail' => $details,
            'summary' => $reason['summary'] ?? $details ?? $category ?? 'Oportunidad perdida',
            'lost_at' => now()->toDateString(),
            'estimated_value' => $opportunity->amount,
            'currency' => $opportunity->currency,
        ];

        if (! empty($reason['reason_type']) || ! $category) {
            $payload['reason_type'] = $reason['reason_type'] ?? 'other';
        }

        return array_filter($payload, fn ($value) => $value !== null && $value !== '');
    }

    private function resolveLostReasonCompetitorUid(array $reason): ?string
    {
        if (! empty($reason['competitor_uid'])) {
            return $reason['competitor_uid'];
        }

        $competitor = $reason['competitor'] ?? null;

        if (! is_array($competitor) || empty($competitor['name'])) {
            return null;
        }

        $key = str($competitor['name'])->lower()->slug('-')->toString();
        $existing = Competitor::withoutGlobalScopes()
            ->where('tenant_id', auth()->user()->tenant_id)
            ->where('key', $key)
            ->first();

        if ($existing) {
            return $existing->uid;
        }

        return $this->competitiveIntelligenceService->createCompetitor([
            'name' => $competitor['name'],
            'key' => $key,
            'description' => $competitor['description'] ?? null,
            'notes' => $competitor['description'] ?? null,
            'is_active' => true,
        ])->uid;
    }

    public function deleteOpportunity(string $uid): void
    {
        Opportunity::query()->where('uid', $uid)->firstOrFail()->delete();
    }

    public function import(array $data): array
    {
        $validated = Validator::make($data, [
            'file' => 'required|file|mimes:csv,txt,xlsx|max:10240',
            'stage_uid' => 'nullable|uuid',
        ])->validate();

        $rows = $this->readImportRows($validated['file']);
        $created = [];
        $errors = [];
        $defaultStageUid = $validated['stage_uid'] ?? $this->defaultStageUid();

        foreach ($rows as $index => $row) {
            $line = $index + 2;

            try {
                $payload = $this->importRowPayload($row, $defaultStageUid);

                if (empty($payload['title'])) {
                    continue;
                }

                $created[] = $this->createOpportunity($payload);
            } catch (\Throwable $e) {
                $errors[] = [
                    'row' => $line,
                    'message' => $e instanceof ValidationException
                        ? collect($e->errors())->flatten()->implode(' ')
                        : $e->getMessage(),
                ];
            }
        }

        return [
            'created_count' => count($created),
            'failed_count' => count($errors),
            'opportunities' => $created,
            'errors' => $errors,
        ];
    }

    public function template()
    {
        return $this->exportService->file('opportunities-import-template', [[
            'titulo' => 'TechNova S.A.',
            'monto' => 15000000,
            'moneda' => 'COP',
            'fecha_cierre_esperada' => '2026-06-30',
            'email' => 'contacto@technova.com',
            'origen_lead' => 'linkedin',
            'descripcion' => 'Cliente interesado en implementacion CRM',
        ]], [
            'format' => 'excel',
        ]);
    }

    public function board(array $filters = []): array
    {
        $validated = Validator::make($filters, [
            'search' => 'nullable|string|max:255',
            'origin' => 'nullable|string|max:255',
            'product' => 'nullable|string|max:255',
            'closed_days' => 'nullable|integer|min:1|max:365',
            'include_closed' => 'nullable|string|in:true,false,1,0',
        ])->validate();

        $stages = OpportunityStage::query()->orderBy('position')->get();
        $opportunityQuery = Opportunity::query()->with(['stage', 'owner']);
        $closedDays = (int) ($validated['closed_days'] ?? 7);
        $includeClosed = ! array_key_exists('include_closed', $validated)
            || filter_var($validated['include_closed'], FILTER_VALIDATE_BOOLEAN);

        $this->applyBoardClosedWindow($opportunityQuery, $closedDays, $includeClosed);

        if (! empty($validated['search'])) {
            $this->applyOpportunitySearch($opportunityQuery, $validated['search']);
        }

        if (! empty($validated['origin'])) {
            $this->applyOpportunityOriginFilter($opportunityQuery, $validated['origin']);
        }

        if (! empty($validated['product'])) {
            $this->applyOpportunityProductFilter($opportunityQuery, $validated['product']);
        }

        $opportunityQuery->orderBy('stage_id');

        if ($this->supportsKanbanPosition()) {
            $opportunityQuery->orderBy('kanban_position');
        }

        $result = ApiIndex::paginateOrGet(
            $opportunityQuery->orderByDesc('created_at'),
            $filters,
            'opportunities_board_page'
        );
        $closingStage = $this->resolveClosingStageFromCollection($stages);
        $lostStage = $this->resolveLostStageFromCollection($stages);
        $items = collect(method_exists($result, 'items') ? $result->items() : $result)
            ->map(fn (Opportunity $opportunity) => $this->serializeOpportunityForBoard($opportunity, $closingStage, $lostStage))
            ->filter(fn (array $opportunity) => $opportunity['board_stage_id'] !== null)
            ->values();
        $opportunities = $items->groupBy('board_stage_id');

        $payload = [
            'stages' => $stages->map(function (OpportunityStage $stage) use ($opportunities) {
                $items = $opportunities->get($stage->getKey(), collect());

                if ($this->isWonClosingStage($stage)) {
                    $items = $items->filter(fn (array $opportunity) => ! empty($opportunity['won_at']) || ! empty($opportunity['lost_at']));
                }

                if ($this->isLostStage($stage)) {
                    $items = $items->filter(fn (array $opportunity) => ! empty($opportunity['lost_at']));
                }

                $items = $items->values()
                    ->map(function (array $opportunity) {
                        unset($opportunity['board_stage_id']);

                        return $opportunity;
                    });

                return [
                    'stage' => $stage,
                    'summary' => [
                        'count' => $items->count(),
                        'amount' => round((float) $items->sum(fn (array $opportunity) => (float) $opportunity['amount']), 2),
                    ],
                    'items' => $items,
                ];
            })->values(),
        ];

        if (method_exists($result, 'currentPage')) {
            $payload['pagination'] = ApiIndex::meta($result)['pagination'];
        }

        return $payload;
    }

    public function reorderBoard(array $data): array
    {
        if (! $this->supportsKanbanPosition()) {
            throw ValidationException::withMessages([
                'kanban_position' => ['La base de datos tenant aun no tiene habilitado el orden del kanban. Ejecuta tenants:migrate.'],
            ]);
        }

        $validated = Validator::make($data, [
            'stage_uid' => 'required|uuid',
            'ordered_opportunity_uids' => 'required_without:opportunity_uids|array|min:1',
            'ordered_opportunity_uids.*' => 'uuid',
            'opportunity_uids' => 'required_without:ordered_opportunity_uids|array|min:1',
            'opportunity_uids.*' => 'uuid',
        ])->validate();

        $stage = $this->resolveStage($validated['stage_uid']);
        $orderedUids = array_values(array_unique($validated['ordered_opportunity_uids'] ?? $validated['opportunity_uids']));

        return DB::transaction(function () use ($stage, $orderedUids) {
            $requested = Opportunity::query()
                ->where('stage_id', $stage->getKey())
                ->whereIn('uid', $orderedUids)
                ->get(['id', 'uid']);

            if ($requested->count() !== count($orderedUids)) {
                throw ValidationException::withMessages([
                    'ordered_opportunity_uids' => ['Todas las oportunidades deben existir y pertenecer a la etapa enviada'],
                ]);
            }

            $remaining = Opportunity::query()
                ->where('stage_id', $stage->getKey())
                ->whereNotIn('uid', $orderedUids)
                ->orderBy('kanban_position')
                ->orderByDesc('created_at')
                ->pluck('uid')
                ->all();

            $finalOrder = array_values(array_merge($orderedUids, $remaining));

            foreach ($finalOrder as $position => $uid) {
                Opportunity::query()
                    ->where('uid', $uid)
                    ->update(['kanban_position' => $position + 1]);
            }

            return [
                'stage_uid' => $stage->uid,
                'ordered_opportunity_uids' => $finalOrder,
            ];
        });
    }

    private function mapOpportunityIndexResult($result)
    {
        if (method_exists($result, 'through')) {
            return $result->through(fn (Opportunity $opportunity) => $this->serializeOpportunityIndex($opportunity));
        }

        return collect($result)
            ->map(fn (Opportunity $opportunity) => $this->serializeOpportunityIndex($opportunity))
            ->values();
    }

    private function serializeOpportunityIndex(Opportunity $opportunity): array
    {
        return [
            'uid' => $opportunity->uid,
            'title' => $opportunity->title,
            'email' => $opportunity->email,
            'lead_origin' => $opportunity->lead_origin,
            'amount' => round((float) $opportunity->amount, 2),
            'currency' => $opportunity->currency,
            'expected_close_date' => $opportunity->expected_close_date,
            'description' => $opportunity->description,
            'stage_id' => $opportunity->stage_id,
            'stage_uid' => $opportunity->stage?->uid,
            'stage_name' => $opportunity->stage?->name,
            'stage' => $opportunity->stage ? [
                'uid' => $opportunity->stage->uid,
                'name' => $opportunity->stage->name,
                'key' => $opportunity->stage->key,
                'position' => $opportunity->stage->position,
                'probability' => $opportunity->stage->probability,
                'color' => $opportunity->stage->color,
                'is_won' => $opportunity->stage->is_won,
                'is_lost' => $opportunity->stage->is_lost,
            ] : null,
            'owner_user_uid' => $opportunity->owner?->uid,
            'owner' => $opportunity->owner ? [
                'uid' => $opportunity->owner->uid,
                'name' => $opportunity->owner->name,
                'email' => $opportunity->owner->email,
            ] : null,
            'opportunityable_type' => $opportunity->opportunityable_type,
            'opportunityable_uid' => $this->resolveMorphUid($opportunity->opportunityable_type, $opportunity->opportunityable_id),
            'custom_fields' => $opportunity->custom_fields,
            'kanban_position' => (int) ($opportunity->kanban_position ?? 0),
            'is_closed' => (bool) ($opportunity->won_at || $opportunity->lost_at),
            'closed_status' => $opportunity->lost_at ? 'lost' : ($opportunity->won_at ? 'won' : null),
            'closed_at' => $opportunity->lost_at ?? $opportunity->won_at,
            'won_at' => $opportunity->won_at,
            'lost_at' => $opportunity->lost_at,
            'created_at' => $opportunity->created_at,
            'updated_at' => $opportunity->updated_at,
        ];
    }

    private function serializeOpportunityForBoard(
        Opportunity $opportunity,
        ?OpportunityStage $closingStage,
        ?OpportunityStage $lostStage = null
    ): array
    {
        $payload = $this->serializeOpportunityIndex($opportunity);
        $payload['board_stage_id'] = $payload['stage_id'];

        if (! empty($payload['lost_at'])) {
            $targetStage = $lostStage ?? $closingStage;

            if (! $targetStage) {
                $payload['board_stage_id'] = null;

                return $payload;
            }

            $payload['board_stage_id'] = $targetStage->getKey();
            $payload['stage_id'] = $targetStage->getKey();
            $payload['stage_uid'] = $targetStage->uid;
            $payload['stage_name'] = $targetStage->name;
            $payload['stage'] = $this->serializeStageForBoard(
                $targetStage,
                isWon: $this->isWonClosingStage($targetStage),
                isLost: true
            );

            return $payload;
        }

        if ($closingStage && ! empty($payload['won_at']) && empty($payload['lost_at'])) {
            $payload['board_stage_id'] = $closingStage->getKey();
            $payload['stage_id'] = $closingStage->getKey();
            $payload['stage_uid'] = $closingStage->uid;
            $payload['stage_name'] = $closingStage->name;
            $payload['stage'] = $this->serializeStageForBoard($closingStage, isWon: true, isLost: $closingStage->is_lost);
        }

        return $payload;
    }

    private function serializeStageForBoard(OpportunityStage $stage, bool $isWon, bool $isLost): array
    {
        return [
            'uid' => $stage->uid,
            'name' => $stage->name,
            'key' => $stage->key,
            'position' => $stage->position,
            'probability' => $stage->probability,
            'color' => $stage->color,
            'is_won' => $isWon,
            'is_lost' => $isLost,
        ];
    }

    private function serializeOpportunityDetail(Opportunity $opportunity): array
    {
        return array_merge($this->serializeOpportunityIndex($opportunity), [
            'opportunityable_uid' => $this->resolveMorphUid($opportunity->opportunityable_type, $opportunity->opportunityable_id),
            'opportunityable_type' => $this->normalizeMorphType($opportunity->opportunityable_type),
            'opportunityable_label' => $this->resolveEntityLabel($opportunity->opportunityable),
            'project' => $opportunity->project ? $this->serializeProject($opportunity->project) : null,
            'lost_reasons' => $opportunity->lostReasons
                ->map(fn (LostReason $reason) => $this->serializeLostReason($reason))
                ->values(),
            'activities' => $opportunity->activities
                ->map(fn (Activity $activity) => $this->serializeActivity($activity))
                ->values(),
            'quotations' => $opportunity->quotations
                ->map(fn (Quotation $quotation) => $this->serializeQuotation($quotation))
                ->values(),
        ]);
    }

    private function serializeProject(Project $project): array
    {
        return [
            'uid' => $project->uid,
            'name' => $project->name,
            'description' => $project->description,
            'status' => $project->status,
            'priority' => $project->priority,
            'start_date' => $project->start_date,
            'end_date' => $project->end_date,
            'account_uid' => $project->account?->uid,
            'client_uid' => $project->account?->uid,
            'client_name' => $project->account?->name,
            'opportunity_uid' => $this->resolveMorphUid(Opportunity::class, $project->opportunity_id),
            'invoice_uid' => $this->resolveMorphUid(Invoice::class, $project->invoice_id),
            'assigned_to_uid' => $project->assignedUser?->uid,
            'assigned_to_name' => $project->assignedUser?->name,
            'estimated_hours' => round((float) $project->estimated_hours, 2),
            'actual_hours' => round((float) $project->actual_hours, 2),
            'created_at' => $project->created_at,
            'updated_at' => $project->updated_at,
        ];
    }

    private function serializeLostReason(LostReason $reason): array
    {
        return [
            'uid' => $reason->uid,
            'competitor_uid' => $reason->competitor?->uid,
            'competitor_name' => $reason->competitor?->name,
            'reason_type' => $reason->reason_type,
            'summary' => $reason->summary,
            'details' => $reason->details,
            'lost_at' => $reason->lost_at,
            'estimated_value' => round((float) $reason->estimated_value, 2),
            'currency' => $reason->currency,
            'lost_reason_category' => $reason->lost_reason_category,
            'lost_reason_detail' => $reason->lost_reason_detail,
            'created_at' => $reason->created_at,
            'updated_at' => $reason->updated_at,
        ];
    }

    private function serializeActivity(Activity $activity): array
    {
        return [
            'uid' => $activity->uid,
            'type' => $activity->type,
            'title' => $activity->title,
            'description' => $activity->description,
            'status' => $activity->status,
            'priority' => $activity->priority,
            'scheduled_at' => $activity->scheduled_at,
            'completed_at' => $activity->completed_at,
            'owner_user_uid' => $activity->owner?->uid,
            'owner_user_name' => $activity->owner?->name,
            'assigned_user_uid' => $activity->assignedUser?->uid,
            'assigned_to_uid' => $activity->assignedUser?->uid,
            'assigned_to_name' => $activity->assignedUser?->name,
            'created_at' => $activity->created_at,
            'updated_at' => $activity->updated_at,
        ];
    }

    private function serializeQuotation(Quotation $quotation): array
    {
        return [
            'uid' => $quotation->uid,
            'quote_number' => $quotation->quote_number,
            'title' => $quotation->title,
            'status' => $quotation->status,
            'currency' => $quotation->currency,
            'exchange_rate' => $quotation->exchange_rate,
            'local_currency' => $quotation->local_currency,
            'valid_until' => $quotation->valid_until,
            'notes' => $quotation->notes,
            'subtotal' => round((float) $quotation->subtotal, 2),
            'discount_total' => round((float) $quotation->discount_total, 2),
            'total' => round((float) $quotation->total, 2),
            'items' => $quotation->items
                ->map(fn (QuotationItem $item) => $this->serializeQuotationItem($item))
                ->values(),
            'created_at' => $quotation->created_at,
            'updated_at' => $quotation->updated_at,
        ];
    }

    private function serializeQuotationItem(QuotationItem $item): array
    {
        return [
            'uid' => $item->uid,
            'product_uid' => $item->product?->uid,
            'catalog_product_uid' => $item->catalogProduct?->uid,
            'warehouse_uid' => $item->warehouse?->uid,
            'sku' => $item->sku,
            'description' => $item->description,
            'quantity' => $item->quantity,
            'list_unit_price' => round((float) $item->list_unit_price, 2),
            'discount_percent' => round((float) $item->discount_percent, 2),
            'discount_amount' => round((float) $item->discount_amount, 2),
            'net_unit_price' => round((float) $item->net_unit_price, 2),
            'line_total' => round((float) $item->line_total, 2),
            'discount_total' => round((float) $item->discount_total, 2),
            'reserved_quantity' => $item->reserved_quantity,
            'reservation_indicator' => $item->reservation_indicator,
        ];
    }

    private function normalizeMorphType(?string $type): ?string
    {
        return match ($type) {
            Account::class => 'account',
            Contact::class => 'contact',
            CrmEntity::class => 'crm_entity',
            Opportunity::class => 'opportunity',
            Tenant::class => 'tenant',
            null, '' => null,
            default => str(class_basename($type))->snake()->toString(),
        };
    }

    private function resolveEntityLabel($entity): ?string
    {
        return $entity?->display_name
            ?? $entity?->name
            ?? $entity?->title
            ?? null;
    }

    private function resolveMorphUid(?string $class, ?int $id): ?string
    {
        if (! $class || ! $id || ! is_subclass_of($class, \Illuminate\Database\Eloquent\Model::class)) {
            return null;
        }

        return $class::withoutGlobalScopes()->whereKey($id)->value('uid');
    }

    private function applyOpportunitySearch($query, string $search): void
    {
        $query->where(function ($builder) use ($search) {
            $builder
                ->where('title', 'like', '%'.$search.'%')
                ->orWhere('email', 'like', '%'.$search.'%')
                ->orWhere('description', 'like', '%'.$search.'%')
                ->orWhereHasMorph('opportunityable', [Account::class], function ($entityQuery) use ($search) {
                    $entityQuery
                        ->where('name', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%')
                        ->orWhere('document', 'like', '%'.$search.'%');
                })
                ->orWhereHasMorph('opportunityable', [Contact::class], function ($entityQuery) use ($search) {
                    $entityQuery
                        ->where('first_name', 'like', '%'.$search.'%')
                        ->orWhere('last_name', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%');
                })
                ->orWhereHasMorph('opportunityable', [CrmEntity::class], function ($entityQuery) use ($search) {
                    $entityQuery->where('type', 'like', '%'.$search.'%');
                });
        });
    }

    private function applyOpportunityOriginFilter($query, string $origin): void
    {
        $query->where(function ($builder) use ($origin) {
            $builder
                ->where('lead_origin', $origin)
                ->orWhereHasMorph('opportunityable', [CrmEntity::class], function ($entityQuery) use ($origin) {
                    $entityQuery->where(function ($crmQuery) use ($origin) {
                        $crmQuery
                            ->where('profile_data->lead_origin', $origin)
                            ->orWhere('profile_data->origin', $origin);
                    });
                });
        });
    }

    private function applyOpportunityProductFilter($query, string $product): void
    {
        $search = '%'.mb_strtolower($product).'%';

        $query->where(function ($builder) use ($product, $search) {
            $builder
                ->whereRaw('LOWER(title) LIKE ?', [$search])
                ->orWhereRaw('LOWER(description) LIKE ?', [$search])
                ->orWhereHasMorph('opportunityable', [CrmEntity::class], function ($entityQuery) use ($product) {
                    $entityQuery->where(function ($crmQuery) use ($product) {
                        $crmQuery
                            ->where('profile_data->product', $product)
                            ->orWhere('profile_data->product_uid', $product)
                            ->orWhere('profile_data->main_product', $product)
                            ->orWhere('profile_data->primary_product', $product);
                    });
                });
        });
    }

    private function applyBoardClosedWindow($query, int $closedDays, bool $includeClosed): void
    {
        if (! $includeClosed) {
            $query->whereNull('won_at')->whereNull('lost_at');

            return;
        }

        $closedSince = now()->subDays($closedDays)->startOfDay();

        $query->where(function ($builder) use ($closedSince) {
            $builder
                ->where(function ($activeQuery) {
                    $activeQuery->whereNull('won_at')->whereNull('lost_at');
                })
                ->orWhere(function ($wonQuery) use ($closedSince) {
                    $wonQuery->whereNotNull('won_at')->where('won_at', '>=', $closedSince);
                })
                ->orWhere(function ($lostQuery) use ($closedSince) {
                    $lostQuery->whereNotNull('lost_at')->where('lost_at', '>=', $closedSince);
                });
        });
    }

    private function nextKanbanPosition(int $stageId): int
    {
        return ((int) Opportunity::query()
            ->where('stage_id', $stageId)
            ->max('kanban_position')) + 1;
    }

    private function supportsKanbanPosition(): bool
    {
        return Schema::hasColumn('opportunities', 'kanban_position');
    }

    public function summary(): array
    {
        $tenantId = auth()->user()?->tenant_id;

        $totals = Opportunity::query()
            ->withoutGlobalScope('tenant')
            ->where('opportunities.tenant_id', $tenantId)
            ->leftJoin('opportunity_stages', 'opportunities.stage_id', '=', 'opportunity_stages.id')
            ->selectRaw('COUNT(opportunities.id) as count')
            ->selectRaw('COALESCE(SUM(opportunities.amount), 0) as amount')
            ->selectRaw('SUM(CASE WHEN opportunities.won_at IS NOT NULL OR opportunity_stages.is_won = true THEN 1 ELSE 0 END) as won_count')
            ->selectRaw('SUM(CASE WHEN opportunities.lost_at IS NOT NULL OR opportunity_stages.is_lost = true THEN 1 ELSE 0 END) as lost_count')
            ->first();

        $stageTotals = Opportunity::query()
            ->withoutGlobalScope('tenant')
            ->where('opportunities.tenant_id', $tenantId)
            ->selectRaw('stage_id, COUNT(*) as count, COALESCE(SUM(amount), 0) as amount')
            ->groupBy('stage_id')
            ->get()
            ->keyBy('stage_id');

        return [
            'totals' => [
                'count' => (int) ($totals->count ?? 0),
                'amount' => round((float) ($totals->amount ?? 0), 2),
                'won_count' => (int) ($totals->won_count ?? 0),
                'lost_count' => (int) ($totals->lost_count ?? 0),
            ],
            'by_stage' => OpportunityStage::query()
                ->orderBy('position')
                ->get(['id', 'uid', 'name'])
                ->map(function (OpportunityStage $stage) use ($stageTotals) {
                    $summary = $stageTotals->get($stage->getKey());

                    return [
                        'stage_uid' => $stage->uid,
                        'stage_name' => $stage->name,
                        'count' => (int) ($summary->count ?? 0),
                        'amount' => round((float) ($summary->amount ?? 0), 2),
                    ];
                })
                ->values(),
        ];
    }

    private function importRowPayload(array $row, string $defaultStageUid): array
    {
        $stageUid = $row['stage_uid'] ?? null;

        if (! $stageUid && ! empty($row['stage_key'])) {
            $stageUid = OpportunityStage::query()->where('key', $row['stage_key'])->value('uid');
        }

        $payload = [
            'stage_uid' => $stageUid ?: $defaultStageUid,
            'owner_user_uid' => $row['owner_user_uid'] ?? null,
            'title' => $row['title'] ?? $row['titulo'] ?? $row['lead_name'] ?? $row['nombre'] ?? $row['name'] ?? null,
            'amount' => $row['amount'] ?? $row['monto'] ?? $row['valor'] ?? 0,
            'currency' => $row['currency'] ?? $row['moneda'] ?? null,
            'expected_close_date' => $row['expected_close_date'] ?? $row['fecha_cierre_esperada'] ?? $row['fecha_cierre'] ?? null,
            'email' => $row['email'] ?? $row['correo'] ?? $row['lead_email'] ?? null,
            'lead_origin' => $row['lead_origin'] ?? $row['origen_lead'] ?? $row['origin'] ?? $row['origen'] ?? null,
            'description' => $row['description'] ?? $row['descripcion'] ?? $row['notas'] ?? $row['notes'] ?? null,
        ];

        if (! empty($row['account_uid'])) {
            $payload['entity_type'] = 'account';
            $payload['entity_uid'] = $row['account_uid'];
        } elseif (! empty($row['contact_uid'])) {
            $payload['entity_type'] = 'contact';
            $payload['entity_uid'] = $row['contact_uid'];
        }

        return array_filter($payload, fn ($value) => $value !== null && $value !== '');
    }

    private function defaultStageUid(): string
    {
        return OpportunityStage::query()
            ->orderBy('position')
            ->value('uid')
            ?? throw ValidationException::withMessages([
                'stage_uid' => ['No hay etapas de oportunidad configuradas'],
            ]);
    }

    private function readImportRows(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());

        return $extension === 'xlsx'
            ? $this->readXlsxRows($file)
            : $this->readCsvRows($file);
    }

    private function readCsvRows(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'r');

        if (! $handle) {
            return [];
        }

        $headers = null;
        $rows = [];

        while (($data = fgetcsv($handle)) !== false) {
            if ($headers === null) {
                $headers = $this->normalizeImportHeaders($data);

                continue;
            }

            $rows[] = $this->combineImportRow($headers, $data);
        }

        fclose($handle);

        return $rows;
    }

    private function readXlsxRows(UploadedFile $file): array
    {
        $zip = new ZipArchive;

        if ($zip->open($file->getRealPath()) !== true) {
            throw ValidationException::withMessages([
                'file' => ['No fue posible leer el archivo XLSX'],
            ]);
        }

        $sharedStrings = $this->xlsxSharedStrings($zip);
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        if (! $sheetXml) {
            return [];
        }

        $xml = simplexml_load_string($sheetXml);
        $headers = null;
        $rows = [];

        foreach ($xml->sheetData->row as $row) {
            $values = [];

            foreach ($row->c as $cell) {
                $reference = (string) $cell['r'];
                $columnIndex = $this->xlsxColumnIndex(preg_replace('/\d+/', '', $reference));
                $type = (string) $cell['t'];
                $value = (string) $cell->v;

                if ($type === 's') {
                    $value = $sharedStrings[(int) $value] ?? '';
                } elseif ($type === 'inlineStr') {
                    $value = (string) $cell->is->t;
                }

                $values[$columnIndex] = $value;
            }

            ksort($values);
            $values = array_values($values);

            if ($headers === null) {
                $headers = $this->normalizeImportHeaders($values);

                continue;
            }

            $rows[] = $this->combineImportRow($headers, $values);
        }

        return $rows;
    }

    private function xlsxSharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');

        if (! $xml) {
            return [];
        }

        $shared = simplexml_load_string($xml);
        $strings = [];

        foreach ($shared->si as $item) {
            $strings[] = (string) ($item->t ?? collect($item->r)->map(fn ($run) => (string) $run->t)->implode(''));
        }

        return $strings;
    }

    private function normalizeImportHeaders(array $headers): array
    {
        return collect($headers)
            ->map(fn ($header) => str((string) $header)->lower()->snake()->toString())
            ->all();
    }

    private function combineImportRow(array $headers, array $values): array
    {
        $row = [];

        foreach ($headers as $index => $header) {
            $row[$header] = $values[$index] ?? null;
        }

        return $row;
    }

    private function xlsxColumnIndex(string $letters): int
    {
        $index = 0;

        foreach (str_split($letters) as $letter) {
            $index = $index * 26 + (ord(strtoupper($letter)) - 64);
        }

        return $index - 1;
    }

    private function validateStage(array $data, bool $partial = false): array
    {
        $validator = Validator::make($data, [
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'key' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'position' => 'sometimes|integer|min:1',
            'probability_percent' => 'sometimes|integer|min:0|max:100',
            'is_won' => 'sometimes|boolean',
            'is_lost' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $validator->validated();
    }

    private function validateOpportunity(array $data, bool $partial = false): array
    {
        $validator = Validator::make($data, [
            'stage_uid' => [$partial ? 'sometimes' : 'required', 'uuid'],
            'owner_user_uid' => 'nullable|uuid',
            'entity_type' => 'nullable|string',
            'entity_uid' => 'nullable|uuid',
            'title' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'email' => 'nullable|email|max:255',
            'lead_origin' => 'nullable|string|max:100',
            'origin' => 'nullable|string|max:100',
            'amount' => 'sometimes|numeric|min:0',
            'currency' => 'nullable|string|max:10',
            'expected_close_date' => 'nullable|date',
            'description' => 'nullable|string',
            'custom_fields' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $validated = $validator->validated();

        if (! array_key_exists('lead_origin', $validated) && array_key_exists('origin', $validated)) {
            $validated['lead_origin'] = $validated['origin'];
        }

        unset($validated['origin']);

        if (! empty($validated['entity_type']) xor ! empty($validated['entity_uid'])) {
            throw ValidationException::withMessages([
                'entity_uid' => ['Debes enviar entity_type y entity_uid juntos'],
            ]);
        }

        return $validated;
    }

    private function assignCustomFieldValues(Opportunity $opportunity, array $customFields): void
    {
        if ($customFields === []) {
            return;
        }

        foreach ($this->normalizeCustomFieldPayload($opportunity, $customFields) as $fieldValue) {
            $this->customFieldService->assignValue(
                'opportunities',
                $opportunity->uid,
                $fieldValue['custom_field_uid'],
                $fieldValue['value'] ?? null
            );
        }
    }

    private function normalizeCustomFieldPayload(Opportunity $opportunity, array $customFields): array
    {
        if (! array_is_list($customFields)) {
            return collect($customFields)
                ->map(function ($value, string $fieldKey) use ($opportunity) {
                    return [
                        'custom_field_uid' => $this->resolveOpportunityCustomFieldUid($opportunity, $fieldKey),
                        'value' => $value,
                    ];
                })
                ->values()
                ->all();
        }

        return collect($customFields)
            ->map(function ($fieldValue) use ($opportunity) {
                if (! is_array($fieldValue)) {
                    throw ValidationException::withMessages([
                        'custom_fields' => ['Cada campo personalizado debe ser un objeto con custom_field_uid y value'],
                    ]);
                }

                $fieldUid = $fieldValue['custom_field_uid']
                    ?? $fieldValue['field_uid']
                    ?? $fieldValue['uid']
                    ?? null;

                if (! $fieldUid && isset($fieldValue['key'])) {
                    $fieldUid = $this->resolveOpportunityCustomFieldUid($opportunity, (string) $fieldValue['key']);
                }

                if (! $fieldUid) {
                    throw ValidationException::withMessages([
                        'custom_fields' => ['Cada campo personalizado debe incluir custom_field_uid o key'],
                    ]);
                }

                return [
                    'custom_field_uid' => $fieldUid,
                    'value' => $fieldValue['value'] ?? null,
                ];
            })
            ->values()
            ->all();
    }

    private function resolveOpportunityCustomFieldUid(Opportunity $opportunity, string $fieldKeyOrUid): string
    {
        $field = CustomField::query()
            ->where('tenant_id', $opportunity->tenant_id)
            ->where('entity_type', Opportunity::class)
            ->where(function ($query) use ($fieldKeyOrUid) {
                $query->where('uid', $fieldKeyOrUid)
                    ->orWhere('key', $fieldKeyOrUid);
            })
            ->first();

        if (! $field) {
            throw ValidationException::withMessages([
                'custom_fields' => ["El campo personalizado {$fieldKeyOrUid} no existe para pipeline"],
            ]);
        }

        return $field->uid;
    }

    private function resolveStage(string $uid): OpportunityStage
    {
        return OpportunityStage::query()->where('uid', $uid)->firstOrFail();
    }

    private function resolveOutcomeStage(Opportunity $opportunity, string $outcome): ?OpportunityStage
    {
        $flag = $outcome === 'won' ? 'is_won' : 'is_lost';

        return OpportunityStage::query()
            ->where('tenant_id', $opportunity->tenant_id)
            ->where(function ($query) use ($flag, $outcome) {
                $query->where($flag, true);

                if ($outcome === 'won') {
                    $query
                        ->orWhereRaw('LOWER("key") = ?', ['cerrador'])
                        ->orWhereRaw('LOWER("name") = ?', ['cerrador']);
                }
            })
            ->orderByDesc('position')
            ->first()
            ?? OpportunityStage::query()
                ->where('tenant_id', $opportunity->tenant_id)
                ->orderByDesc('position')
                ->first();
    }

    private function isLostStage(OpportunityStage $stage): bool
    {
        return $stage->is_lost
            || in_array(mb_strtolower((string) $stage->key), ['perdido', 'perdida', 'lost'], true)
            || in_array(mb_strtolower((string) $stage->name), ['perdido', 'perdida', 'lost'], true);
    }

    private function isWonClosingStage(OpportunityStage $stage): bool
    {
        return $stage->is_won
            || mb_strtolower((string) $stage->key) === 'cerrador'
            || mb_strtolower((string) $stage->name) === 'cerrador';
    }

    private function resolveClosingStageFromCollection($stages): ?OpportunityStage
    {
        return $stages->first(fn (OpportunityStage $stage) => $this->isWonClosingStage($stage));
    }

    private function resolveLostStageFromCollection($stages): ?OpportunityStage
    {
        return $stages->first(fn (OpportunityStage $stage) => $this->isLostStage($stage));
    }

    private function applyClosedOpportunityFilter($query): void
    {
        $query->where(function ($closedQuery) {
            $closedQuery
                ->whereNotNull('won_at')
                ->orWhereNotNull('lost_at');
        });
    }

    private function applyLostOpportunityFilter($query): void
    {
        $query->whereNotNull('lost_at');
    }

    private function resolveEntity(?string $type, ?string $uid)
    {
        if (! $type && ! $uid) {
            return null;
        }

        $entity = find_entity_by_uid($type, $uid);

        if (! $entity) {
            throw ValidationException::withMessages([
                'entity_uid' => ['La entidad comercial no existe o no es visible'],
            ]);
        }

        return $entity;
    }

    private function resolveOwnerUserId(?string $uid, ?int $fallback = null): ?int
    {
        if (! $uid) {
            return $fallback ?? auth()->id();
        }

        $userId = User::query()->where('uid', $uid)->value('id');

        if (! $userId) {
            throw ValidationException::withMessages([
                'owner_user_uid' => ['El usuario no existe o no pertenece a este tenant'],
            ]);
        }

        return $userId;
    }
}
