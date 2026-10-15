<?php

namespace App\Http\Controllers\Platform\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\StoreOrganizationInvitationRequest;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Notifications\OrganizationInvitationNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;

class OrganizationInvitationController extends Controller
{
    /**
     * Invitation to create a NEW organization: the invited person provides the name and details after accepting.
     */
    public function storeNew(StoreOrganizationInvitationRequest $request): RedirectResponse
    {
        return $this->send($request, null);
    }

    /**
     * Cancels the invitation.
     */
    public function destroy(Request $request, OrganizationInvitation $invitation): RedirectResponse
    {
        // The invitation stays in the database as revoked: it shows when and by whom
        $invitation->revoke($request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Zaproszenie zostało anulowane.']);

        return to_route('platform.admin.organizations.index');
    }

    /**
     * Emails the link and a 6-digit key. A new invitation revokes previous, unaccepted ones
     * for the same address and the same organization.
     */
    private function send(StoreOrganizationInvitationRequest $request, ?Organization $organization): RedirectResponse
    {
        $email = strtolower($request->validated('email'));

        OrganizationInvitation::query()
            ->where('organization_id', $organization?->id)
            ->when($organization === null, fn ($query) => $query->whereNull('organization_id'))
            ->where('email', $email)
            ->where('is_active', true)
            ->whereNull('accepted_at')
            ->get()
            ->each(fn (OrganizationInvitation $previous) => $previous->revoke($request->user()));

        [$invitation, $verificationCode] = OrganizationInvitation::issue($organization, $email, $request->user());

        Notification::route('mail', $email)
            ->notify(new OrganizationInvitationNotification($invitation, $verificationCode));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Zaproszenie zostało wysłane.']);

        return to_route('platform.admin.organizations.index');
    }
}
