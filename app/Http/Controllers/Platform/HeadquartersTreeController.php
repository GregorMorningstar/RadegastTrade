<?php

namespace App\Http\Controllers\Platform;

use App\Enums\HeadquarterType;
use App\Http\Controllers\Controller;
use App\Services\Contracts\OrganizationMembersServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tree of all shop or warehouse headquarters with their branches and people
 * (administrator, manager and employee only).
 */
class HeadquartersTreeController extends Controller
{
    public function __construct(private readonly OrganizationMembersServiceInterface $members)
    {
        //
    }

    public function __invoke(Request $request, string $type): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        return response()->json($this->members->headquarters($request->user(), HeadquarterType::from($type), $validated['q'] ?? null));
    }
}
