<?php

namespace App\Http\Controllers\Platform\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationController extends Controller
{
    /**
     * Organization creation page: the administrator sends an email invitation,
     * and the invited person fills in the organization details after accepting it.
     */
    public function create(): Response
    {
        return Inertia::render('platform/admin/organizations/create');
    }

    public function index(): Response
    {
        return Inertia::render('platform/admin/organizations/index', [
            // Invitations to create new organizations, not yet accepted
            'newOrganizationInvitations' => OrganizationInvitation::query()
                ->whereNull('organization_id')
                ->where('is_active', true)
                ->whereNull('accepted_at')
                ->where('expires_at', '>', now())
                ->latest()
                ->get()
                ->map(fn (OrganizationInvitation $invitation) => $this->invitationData($invitation))
                ->values(),

            'organizations' => Organization::query()
                ->with([
                    'invitations' => fn ($query) => $query
                        ->where('is_active', true)
                        ->whereNull('accepted_at')
                        ->where('expires_at', '>', now())
                        ->latest(),
                ])
                ->orderBy('name')
                ->get()
                ->map(fn (Organization $organization) => [
                    'id' => $organization->id,
                    'name' => $organization->name,
                    'NIP' => $organization->NIP,
                    'barcode' => $organization->barcode,
                    'invitations' => $organization->invitations
                        ->map(fn (OrganizationInvitation $invitation) => $this->invitationData($invitation))
                        ->values(),
                ])->values(),
        ]);
    }

    /**
     * @return array{id: int, email: string, expires_at: string, locked: bool}
     */
    private function invitationData(OrganizationInvitation $invitation): array
    {
        return [
            'id' => $invitation->id,
            'email' => $invitation->email,
            'expires_at' => $invitation->expires_at->format('d.m.Y H:i'),
            'locked' => $invitation->isLocked(),
        ];
    }
}
