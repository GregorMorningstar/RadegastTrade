<?php

namespace App\Http\Controllers;

use App\Services\Contracts\OrganizationMembersServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Preview of the manager's own headquarters and its branches with their people
 * (shop and warehouse headquarters manager panel).
 */
class HeadquarterStructureController extends Controller
{
    public function __construct(private readonly OrganizationMembersServiceInterface $members)
    {
        //
    }

    public function show(Request $request): Response
    {
        return Inertia::render($request->user()->role->pageGroup().'/owner/structure/index');
    }

    public function data(Request $request): JsonResponse
    {
        return response()->json($this->members->ownHeadquarters($request->user()));
    }
}
