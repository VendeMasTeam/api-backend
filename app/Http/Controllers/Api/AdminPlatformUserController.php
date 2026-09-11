<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AdminPlatformUserService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AdminPlatformUserController extends Controller
{
    public function __construct(
        private readonly AdminPlatformUserService $service
    ) {}

    public function index(Request $request)
    {
        return $this->successResponse($this->service->list($request->query()));
    }

    public function show(string $uid)
    {
        return $this->successResponse($this->service->findByUid($uid));
    }

    public function store(Request $request)
    {
        try {
            return $this->successResponse($this->service->create($request->all()), 201, 'Superadmin creado');
        } catch (ValidationException $e) {
            return $this->errorResponse('Validation error', 422, $e->errors());
        }
    }

    public function update(Request $request, string $uid)
    {
        try {
            return $this->successResponse($this->service->update($uid, $request->all()), 200, 'Superadmin actualizado');
        } catch (ValidationException $e) {
            return $this->errorResponse('Validation error', 422, $e->errors());
        }
    }

    public function assignRole(Request $request, string $uid)
    {
        try {
            $validated = $request->validate(['role_uid' => 'required|uuid|exists:admin_roles,uid']);
            return $this->successResponse(
                $this->service->assignRole($uid, $validated['role_uid']),
                200,
                'Rol asignado'
            );
        } catch (ValidationException $e) {
            return $this->errorResponse('Validation error', 422, $e->errors());
        }
    }

    public function removeRole(string $uid, string $roleUid)
    {
        try {
            return $this->successResponse(
                $this->service->removeRole($uid, $roleUid),
                200,
                'Rol removido'
            );
        } catch (ValidationException $e) {
            return $this->errorResponse('Validation error', 422, $e->errors());
        }
    }

    public function resetTwoFactor(string $uid)
    {
        return $this->successResponse([
            'uid' => $this->service->resetTwoFactor($uid)->uid,
            'two_factor_enabled' => false,
        ], 200, '2FA reseteado correctamente');
    }

    public function lock(Request $request, string $uid)
    {
        try {
            return $this->successResponse(
                $this->service->lock($uid, $request->user()),
                200,
                'Usuario de plataforma desactivado'
            );
        } catch (ValidationException $e) {
            return $this->errorResponse('Validation error', 422, $e->errors());
        }
    }

    public function unlock(string $uid)
    {
        try {
            return $this->successResponse(
                $this->service->unlock($uid),
                200,
                'Usuario de plataforma activado'
            );
        } catch (ValidationException $e) {
            return $this->errorResponse('Validation error', 422, $e->errors());
        }
    }

    public function purge(Request $request, string $uid)
    {
        try {
            return $this->successResponse(
                $this->service->purge($uid, $request->user(), $request->all()),
                200,
                'Usuario de plataforma eliminado definitivamente'
            );
        } catch (ValidationException $e) {
            return $this->errorResponse('Validation error', 422, $e->errors());
        }
    }
}
