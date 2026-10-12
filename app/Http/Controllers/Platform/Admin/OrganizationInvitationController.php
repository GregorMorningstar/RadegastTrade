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
     * Zaproszenie do założenia NOWEJ organizacji: nazwę i dane podaje zapraszana osoba po przyjęciu.
     */
    public function storeNew(StoreOrganizationInvitationRequest $request): RedirectResponse
    {
        return $this->send($request, null);
    }

    /**
     * Zaproszenie kolejnego właściciela do ISTNIEJĄCEJ organizacji.
     */
    public function store(StoreOrganizationInvitationRequest $request, Organization $organization): RedirectResponse
    {
        return $this->send($request, $organization);
    }

    /**
     * Anuluje zaproszenie.
     */
    public function destroy(Request $request, OrganizationInvitation $invitation): RedirectResponse
    {
        // Zaproszenie zostaje w bazie jako wycofane: widać kiedy i przez kogo
        $invitation->revoke($request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Zaproszenie zostało anulowane.']);

        return to_route('platform.admin.organizations.index');
    }

    /**
     * Wysyła mailem link i 6-cyfrowy klucz. Nowe zaproszenie wycofuje poprzednie, nieprzyjęte,
     * na ten sam adres i tę samą organizację.
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
