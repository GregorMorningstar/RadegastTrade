<?php

namespace App\Http\Controllers;

use App\Http\Requests\Tasks\RescheduleTaskRequest;
use App\Http\Requests\Tasks\StoreTaskRequest;
use App\Http\Requests\Tasks\UpdateTaskRequest;
use App\Http\Requests\Tasks\SearchTasksRequest;
use App\Services\Contracts\TaskSearchServiceInterface;
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
    public function __construct(private readonly TaskServiceInterface $tasks, private readonly TaskSearchServiceInterface $search)
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

    /**
     * Searching the tasks the person sees (JSON, paged). What may be found follows from the account, not from the request.
     */
    public function search(SearchTasksRequest $request): JsonResponse
    {
        return response()->json($this->search->search($request->user(), $request->validated()))->header('Cache-Control', 'no-store, private');
    }

    public function store(StoreTaskRequest $request): JsonResponse
    {
        return response()->json($this->tasks->create($request->user(), $request->validated()), 201);
    }

    /**
     * Asks for the e-mail code that confirms finishing a task which requires it (the code goes to the person who finishes it).
     */
    public function completionCode(Request $request, int $task): JsonResponse
    {
        return response()->json($this->tasks->requestCompletionCode($request->user(), $task), 202);
    }

    /**
     * Finishes the task (the person's part, or a day of a standing task) with the code from the e-mail.
     */
    public function complete(Request $request, int $task): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'digits:6']]);

        return response()->json($this->tasks->completeWithCode($request->user(), $task, $data['code']));
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
