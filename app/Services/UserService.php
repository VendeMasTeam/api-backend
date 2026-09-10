<?php

namespace App\Services;

use App\Repositories\UserRepository;

class UserService
{
    protected $repo;
    protected $twoFactorService;

    public function __construct(UserRepository $repo, TwoFactorService $twoFactorService)
    {
        $this->repo = $repo;
        $this->twoFactorService = $twoFactorService;
    }

    public function getAll(array $filters = [])
    {
        return $this->repo->getAll($filters);
    }

    public function findByUid(string $uid)
    {
        return $this->repo->findByUid($uid);
    }

    public function create(array $data)
    {
        return $this->repo->create($data);
    }

    public function update(string $uid, array $data)
    {
        return $this->repo->update($uid, $data);
    }

    public function delete(string $uid)
    {
        return $this->repo->delete($uid);
    }

    public function resetTwoFactor(string $uid)
    {
        $user = $this->repo->findByUid($uid);

        if (!$user) {
            return null;
        }

        return $this->twoFactorService->disableForUser($user)->fresh(['roles', 'permissions']);
    }
}
