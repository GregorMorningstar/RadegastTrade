<?php

namespace App\Http\Controllers;

use App\Services\Contracts\TaskServiceInterface;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tasks: one controller for every role, each role has its own view in its folder under pages/platform
 * (e.g. platform/admin/tasks/index, platform/shop/leader/tasks/index). The controller is only wired to its service
 * through the constructor for now.
 */
class TaskController extends Controller
{
    public function __construct(private readonly TaskServiceInterface $tasks)
    {
        //
    }

    public function index(Request $request): Response
    {
        $role = $request->user()->role;

        return Inertia::render($role->pageGroup().'/'.$role->slug().'/tasks/index');
    }
}
