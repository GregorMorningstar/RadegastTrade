<?php

namespace App\Http\Controllers\Trade;

use App\Http\Controllers\Controller;
use App\Models\UnitOrderPermission;
use App\Services\OrderPermissionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Who may place orders for a unit: the people authorised by the owner or by the manager of the unit.
 */
class UnitOrderPermissionController extends Controller
{
    public function __construct(private readonly OrderPermissionService $permissions)
    {
        //
    }

    public function index(Request $request): Response
    {
        return Inertia::render('trade/permissions/index', $this->permissions->overview($request->user()));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'unit' => ['required', 'string', 'max:40'],
            'user_id' => ['required', 'integer'],
        ]);

        // step one: a code goes to the e-mail of the person who grants; nothing is saved yet
        $this->permissions->requestGrant($request->user(), $data['unit'], (int) $data['user_id']);

        return back()->with('status', 'permission-code-sent');
    }

    public function confirm(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'digits:6']]);

        $this->permissions->confirmGrant($request->user(), $data['code']);

        return back()->with('status', 'permission-granted');
    }

    public function cancel(Request $request): RedirectResponse
    {
        $this->permissions->cancelPending($request->user());

        return back();
    }

    public function destroy(Request $request, int $permission): RedirectResponse
    {
        $model = UnitOrderPermission::query()->where('organization_id', $request->user()->organization_id)->findOrFail($permission);

        $this->permissions->revoke($request->user(), $model);

        return back();
    }
}
