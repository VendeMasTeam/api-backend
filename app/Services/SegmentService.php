<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Contact;
use App\Models\CrmEntity;
use App\Models\Segment;
use App\Support\ApiIndex;
use Illuminate\Support\Facades\Validator;

class SegmentService
{
    public function __construct(private readonly ConditionEvaluator $conditionEvaluator) {}

    public function list(array $filters = [])
    {
        return ApiIndex::paginateOrGet(Segment::query()->latest(), $filters, 'segments_page');
    }

    public function get(string $uid): Segment
    {
        return Segment::query()->where('uid', $uid)->firstOrFail();
    }

    public function create(array $data): Segment
    {
        return Segment::query()->create($this->validate($data))->fresh();
    }

    public function update(string $uid, array $data): Segment
    {
        $segment = $this->get($uid);
        $segment->update($this->validate($data, true));

        return $segment->fresh();
    }

    public function delete(string $uid): void
    {
        $this->get($uid)->delete();
    }

    public function run(string $uid): array
    {
        $segment = $this->get($uid);
        $rows = $this->queryFor($segment->entity_type)
            ->with('tags')
            ->get()
            ->filter(fn ($row) => $this->conditionEvaluator->matches($segment->rules ?? [], $row, $segment->logic))
            ->values();

        $segment->forceFill([
            'execution_count' => (int) $segment->execution_count + 1,
            'last_run_at' => now(),
        ])->save();

        return [
            'segment' => $segment->fresh(),
            'count' => $rows->count(),
            'data' => $rows,
        ];
    }

    private function validate(array $data, bool $partial = false): array
    {
        return Validator::make($data, [
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'description' => 'nullable|string',
            'entity_type' => 'sometimes|string|in:account,contact,crm_entity',
            'rules' => 'sometimes|array',
            'rules.*.field' => 'required_with:rules|string|max:255',
            'rules.*.operator' => 'required_with:rules|string|in:equals,not_equals,contains,greater_than,less_than,in,not_in',
            'rules.*.value' => 'nullable',
            'logic' => 'sometimes|string|in:AND,OR',
        ])->validate();
    }

    private function queryFor(string $entityType)
    {
        return match ($entityType) {
            'account' => Account::query(),
            'crm_entity' => CrmEntity::query(),
            default => Contact::query()->with('account'),
        };
    }
}
