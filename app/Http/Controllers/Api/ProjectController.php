<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AssignmentService;
use App\Services\MilestoneService;
use App\Services\ProjectService;
use App\Support\ApiIndex;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ProjectController extends Controller
{
    public function __construct(
        private readonly ProjectService $projectService,
        private readonly MilestoneService $milestoneService,
        private readonly AssignmentService $assignmentService
    ) {
    }

    public function index(Request $request)
    {
        $payload = $this->projectService->getProjects($request->query());
        $data = $payload['data'];
        $meta = null;

        if ($data instanceof LengthAwarePaginator) {
            $meta = ApiIndex::meta($data);
            $data = $data->items();
        }

        return response()->json([
            'success' => true,
            'message' => null,
            'data' => $data,
            'summary' => $payload['summary'],
            'meta' => $meta,
            'errors' => null,
        ]);
    }

    public function show(string $uid)
    {
        return $this->successResponse($this->projectService->showProject($uid));
    }

    public function store(Request $request)
    {
        return $this->wrap(fn () => $this->projectService->createProject($request->all()), 'Proyecto creado', 201);
    }

    public function update(Request $request, string $uid)
    {
        return $this->wrap(fn () => $this->projectService->updateProject($uid, $request->all()), 'Proyecto actualizado');
    }

    public function storeMilestone(Request $request, string $uid)
    {
        return $this->wrap(fn () => $this->milestoneService->createMilestone($uid, $request->all()), 'Hito creado', 201);
    }

    public function updateMilestone(Request $request, string $uid)
    {
        return $this->wrap(fn () => $this->milestoneService->updateMilestone($uid, $request->all()), 'Hito actualizado');
    }

    public function storeAssignment(Request $request, string $uid)
    {
        return $this->wrap(fn () => $this->assignmentService->assignUser($uid, $request->all()), 'Recurso asignado', 201);
    }

    public function assignments(string $uid)
    {
        return $this->successResponse($this->assignmentService->getProjectAssignments($uid));
    }

    public function destroyAssignment(string $uid, string $assignmentUid)
    {
        return $this->wrap(function () use ($uid, $assignmentUid) {
            $this->assignmentService->removeProjectAssignment($uid, $assignmentUid);

            return null;
        }, 'Recurso eliminado');
    }

    public function resourceRoles()
    {
        return $this->successResponse($this->assignmentService->roles());
    }

    public function team(string $uid)
    {
        return $this->successResponse($this->assignmentService->getProjectTeam($uid));
    }

    public function progress(string $uid)
    {
        return $this->successResponse($this->projectService->progress($uid));
    }

    private function wrap(\Closure $callback, ?string $message = null, int $status = 200)
    {
        try {
            return $this->successResponse($callback(), $status, $message);
        } catch (ValidationException $e) {
            return $this->errorResponse('Validation error', 422, $e->errors());
        } catch (\Throwable $e) {
            return $this->errorResponse('Server error', 500, ['server' => [$e->getMessage()]]);
        }
    }
}
