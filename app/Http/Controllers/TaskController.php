<?php

namespace App\Http\Controllers;

use App\Http\Requests\Tasks\RescheduleTaskRequest;
use App\Http\Requests\Tasks\StoreTaskRequest;
use App\Http\Requests\Tasks\UpdateTaskRequest;
use App\Services\Contracts\TaskServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tasks: the page of the role (its own view in the role's folder under pages/platform, e.g.
 * platform/shop/leader/tasks/index) and the JSON the page works with: the list, adding, changing, moving in time
 * (calendar, Gantt chart) and deleting. Who sees what and who may change what is decided by the service.
 */
class TaskController extends Controller
{
    public function __construct(private readonly TaskServiceInterface $tasks)
    {
        //
    }

    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render($user->role->pageGroup().'/'.$user->role->slug().'/tasks/index', $this->tasks->board($user));
    }

    public function data(Request $request): JsonResponse
    {
        $period = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);

        return response()->json($this->tasks->list($request->user(), $period['from'] ?? null, $period['to'] ?? null));
    }

    public function store(StoreTaskRequest $request): JsonResponse
    {
        return response()->json($this->tasks->create($request->user(), $request->validated()), 201);
    }

    public function update(UpdateTaskRequest $request, int $task): JsonResponse
    {
        return response()->json($this->tasks->update($request->user(), $task, $request->validated()));
    }

    public function reschedule(RescheduleTaskRequest $request, int $task): JsonResponse
    {
        return response()->json($this->tasks->reschedule($request->user(), $task, $request->validated()));
    }

    public function destroy(Request $request, int $task): JsonResponse
    {
        $this->tasks->delete($request->user(), $task);

        return response()->json(null, 204);
    }
}
