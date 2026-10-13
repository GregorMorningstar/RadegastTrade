<?php

namespace App\Http\Controllers\Platform;

use App\Enums\HeadquarterType;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\Contracts\OrganizationMembersServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Breakdown of an organization into units and the people in them (administrator and manager only).
 */
class OrganizationMembersController extends Controller
{
    public function __construct(private readonly OrganizationMembersServiceInterface $members)
    {
        //
    }

    public function __invoke(Request $request, Organization $organization): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['nullable', 'in:shops,warehouses'],
            'page' => ['nullable', 'integer', 'min:1'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $type = HeadquarterType::from($validated['type'] ?? HeadquarterType::Shop->value);

        return response()->json($this->members->structure($request->user(), $organization, $type, $validated['q'] ?? null));
    }
}
