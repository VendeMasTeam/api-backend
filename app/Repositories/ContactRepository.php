<?php

namespace App\Repositories;

use App\Models\Contact;
use App\Support\ApiIndex;

class ContactRepository
{
    public function all(array $filters = [])
    {
        $query = Contact::query()
            ->with(['account.owner', 'owner', 'tags'])
            ->orderBy('first_name')
            ->orderBy('last_name');

        if (! empty($filters['search'])) {
            $search = '%'.mb_strtolower($filters['search']).'%';
            $query->where(function ($builder) use ($search) {
                $builder
                    ->whereRaw('LOWER(first_name) LIKE ?', [$search])
                    ->orWhereRaw('LOWER(last_name) LIKE ?', [$search])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$search])
                    ->orWhereRaw('LOWER(phone) LIKE ?', [$search])
                    ->orWhereHas('account', fn ($accountQuery) => $accountQuery->whereRaw('LOWER(name) LIKE ?', [$search]));
            });
        }

        if (! empty($filters['account_uid']) || ! empty($filters['company_uid'])) {
            $accountUid = $filters['account_uid'] ?? $filters['company_uid'];
            $query->whereHas('account', fn ($accountQuery) => $accountQuery->where('uid', $accountUid));
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['type'])) {
            $query->where('is_public_entity', $filters['type'] === 'government');
        }

        return ApiIndex::paginateOrGet(
            $query,
            $filters,
            'contacts_page'
        );
    }

    public function findByUid(string $uid)
    {
        return Contact::with(['account.owner', 'owner', 'tags'])->where('uid', $uid)->firstOrFail();
    }

    public function create(array $data)
    {
        if ($this->existsByEmail($data)) {
            throw new \Exception('El contacto ya existe en esta empresa');
        }

        return Contact::create($data);
    }

    public function update(string $uid, array $data)
    {
        $contact = $this->findByUid($uid);

        if ($this->existsByEmail($data, $contact->id)) {
            throw new \Exception('El contacto ya existe en esta empresa');
        }

        $contact->update($data);

        return $contact->fresh(['account', 'tags']);
    }

    public function delete(string $uid)
    {
        $contact = $this->findByUid($uid);

        return $contact->delete();
    }

    public function emailExists(?string $email, ?string $excludeUid = null): bool
    {
        if (! $email) {
            return false;
        }

        return Contact::where('tenant_id', auth()->user()->tenant_id)
            ->where('email', $email)
            ->when($excludeUid, fn ($q) => $q->where('uid', '!=', $excludeUid))
            ->exists();
    }

    private function existsByEmail(array $data, ?int $ignoreId = null): bool
    {
        if (empty($data['email'])) {
            return false;
        }

        return Contact::where('tenant_id', auth()->user()->tenant_id)
            ->where('email', $data['email'])
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();
    }
}
