<?php

namespace App\Http\Controllers\Platform\Organization;

use App\Enums\HeadquarterType;
use App\Http\Controllers\Controller;
use App\Services\Contracts\OrganizationMembersServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tree of the owner's own shop or warehouse headquarters with their branches and people (organization owner panel).
 */
class HeadquartersTreeController extends Controller
{
    public function __construct(private readonly OrganizationMembersServiceInterface $members)
    {
        //
    }

    public function __invoke(Request $request, HeadquarterType $type): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        return response()->json($this->members->organizationHeadquarters($request->user(), $type, $validated['q'] ?? null));
    }
}
