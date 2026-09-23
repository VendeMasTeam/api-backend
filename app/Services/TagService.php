<?php

namespace App\Services;

use App\Models\Tag;
use App\Support\ApiIndex;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TagService
{
    public function getAll(array $filters = [])
    {
        $validated = Validator::make($filters, [
            'search' => 'nullable|string|max:255',
        ])->validate();

        return ApiIndex::paginateOrGet(
            Tag::query()
                ->when(! empty($validated['search']), function ($query) use ($validated) {
                    $search = '%'.mb_strtolower($validated['search']).'%';

                    $query->whereRaw('LOWER(name) LIKE ?', [$search]);
                })
                ->orderBy('category')
                ->orderBy('name'),
            $filters,
            'tags_page'
        );
    }

    public function create(array $data): Tag
    {
        $data['key'] = $data['key'] ?? Str::slug($data['name']);
        $this->ensureUniqueKey($data['key']);

        return Tag::query()->create([
            'name' => $data['name'],
            'key' => $data['key'],
            'color' => $this->normalizeColor($data['color']),
            'category' => $data['category'] ?? 'general',
            'entity_types' => $this->normalizeEntityTypes($data['entity_types'] ?? []),
        ]);
    }

    public function update(string $uid, array $data): Tag
    {
        $tag = $this->findTag($uid);
        $data['key'] = $data['key'] ?? (isset($data['name']) ? Str::slug($data['name']) : $tag->key);

        if (isset($data['key']) && $data['key'] !== $tag->key) {
            $this->ensureUniqueKey($data['key'], $tag->getKey());
        }

        $tag->update([
            'name' => $data['name'] ?? $tag->name,
            'key' => $data['key'] ?? $tag->key,
            'color' => array_key_exists('color', $data) ? $this->normalizeColor($data['color']) : $tag->color,
            'category' => $data['category'] ?? $tag->category,
            'entity_types' => array_key_exists('entity_types', $data) ? $this->normalizeEntityTypes($data['entity_types'] ?? []) : $tag->entity_types,
        ]);

        return $tag->fresh();
    }

    public function delete(string $uid): void
    {
        $this->findTag($uid)->delete();
    }

    public function assignToEntity(string $tagUid, string $entityType, string $entityUid): array
    {
        $tag = $this->findTag($tagUid);
        $entity = $this->findEntity($entityType, $entityUid);

        $normalizedType = $this->normalizeAssignmentEntityType($entityType);
        if ($tag->entity_types !== [] && ! in_array($normalizedType, $tag->entity_types, true)) {
            throw ValidationException::withMessages([
                'entity_type' => ['La etiqueta no esta habilitada para este tipo de entidad'],
            ]);
        }

        $entity->tags()->syncWithoutDetaching([$tag->getKey()]);

        return [
            'tag' => $tag->fresh(),
            'entity_uid' => $entity->uid,
            'entity_type' => $normalizedType,
        ];
    }

    public function removeFromEntity(string $tagUid, string $entityType, string $entityUid): array
    {
        $tag = $this->findTag($tagUid);
        $entity = $this->findEntity($entityType, $entityUid);

        $entity->tags()->detach($tag->getKey());

        return [
            'tag' => $tag->fresh(),
            'entity_uid' => $entity->uid,
            'entity_type' => get_class($entity),
        ];
    }

    private function findTag(string $uid): Tag
    {
        $tag = Tag::withoutGlobalScopes()
            ->where('tenant_id', auth()->user()->tenant_id)
            ->where('uid', $uid)
            ->first();

        if (! $tag) {
            throw ValidationException::withMessages([
                'tag_uid' => ['La etiqueta no existe o no pertenece a este tenant'],
            ]);
        }

        return $tag;
    }

    private function ensureUniqueKey(string $key, ?int $ignoreId = null): void
    {
        $exists = Tag::withoutGlobalScopes()
            ->where('tenant_id', auth()->user()->tenant_id)
            ->where('key', $key)
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'key' => ['Ya existe una etiqueta con esta clave en el tenant'],
            ]);
        }
    }

    private function findEntity(string $entityType, string $entityUid)
    {
        $entity = find_entity_by_uid($entityType, $entityUid);

        if (! $entity) {
            throw ValidationException::withMessages([
                'entity_uid' => ['La entidad no existe o no es visible para este usuario'],
            ]);
        }

        return $entity;
    }

    private function normalizeColor(string $color): string
    {
        $map = [
            'blue' => '#2563eb',
            'red' => '#dc2626',
            'green' => '#16a34a',
            'yellow' => '#ca8a04',
            'orange' => '#ea580c',
            'purple' => '#9333ea',
            'pink' => '#db2777',
            'gray' => '#6b7280',
            'grey' => '#6b7280',
            'default' => '#6b7280',
        ];

        $normalized = strtolower(trim($color));

        return $map[$normalized] ?? $color;
    }

    private function normalizeEntityTypes(array $entityTypes): array
    {
        return collect($entityTypes)
            ->map(fn (string $type) => strtoupper($type))
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeAssignmentEntityType(string $entityType): string
    {
        return match (strtolower($entityType)) {
            'contact', 'contacts' => 'CONTACT',
            'account', 'accounts', 'company', 'companies' => 'COMPANY',
            'lead', 'leads' => 'LEAD',
            'deal', 'deals', 'opportunity', 'opportunities', 'pipeline' => 'DEAL',
            default => strtoupper($entityType),
        };
    }
}
