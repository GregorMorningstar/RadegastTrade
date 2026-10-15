<?php

namespace App\Http\Controllers;

use App\Services\Contracts\UnitMembershipServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Switching a person's account on or off (the organization owner for the people of the organization, a branch
 * leader for the workers of their branch; the rules are in the service).
 */
class UnitMembershipController extends Controller
{
    public function __construct(private readonly UnitMembershipServiceInterface $memberships)
    {
        //
    }

    public function active(Request $request, int $user): JsonResponse
    {
        $data = $request->validate(['active' => ['required', 'boolean']]);

        $this->memberships->setActive($request->user(), $user, (bool) $data['active']);

        return response()->json(['user_id' => $user, 'is_active' => (bool) $data['active']]);
    }
}
