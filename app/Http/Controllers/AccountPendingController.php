<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The page of an account that has no panel: only the roles of the invitation chain have one, so the manager and the
 * employee wait here for an invitation that gives the account a role.
 */
class AccountPendingController extends Controller
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if ($user->role->hasPanel()) {
            return redirect()->route($user->role->dashboardRoute());
        }

        return Inertia::render('account/pending', ['email' => $user->email]);
    }
}
