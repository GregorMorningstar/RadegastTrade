<?php

namespace App\Http\Controllers\Trade;

use App\Http\Controllers\Controller;
use App\Models\TradeInvitation;
use App\Models\TradeRelationship;
use App\Services\ColleagueInvitationService;
use App\Services\TradeRelationshipService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Trade cooperation: the partners, the invitations sent and received, the scope of units, suspending and ending.
 * Whatever comes from the form is only a claim; the service checks it against the signed-in user.
 */
class PartnerController extends Controller
{
    public function __construct(private readonly TradeRelationshipService $service, private readonly ColleagueInvitationService $colleagues)
    {
        //
    }

    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('trade/partners/index', [...$this->service->overview($user), 'colleagues' => $this->colleagues->overview($user)]);
    }

    public function invite(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'inviter_role' => ['required', 'in:supplier,buyer'],
            'invitee_email' => ['required_without_all:target_unit,target_units', 'nullable', 'email:rfc', 'max:255'],
            'target_unit' => ['nullable', 'string', 'max:40'],
            'target_units' => ['nullable', 'array', 'max:300'],
            'target_units.*' => ['string', 'max:40'],
            'units' => ['nullable', 'array', 'max:200'],
            'units.*' => ['string', 'max:40'],
            'all_current' => ['nullable', 'boolean'],
            'message' => ['nullable', 'string', 'max:500'],
        ]);

        isset($data['target_units']) ? $this->service->inviteMany($request->user(), $data) : $this->service->invite($request->user(), $data);

        // the same answer whether or not the address belongs to an organization in the system
        return back()->with('status', 'invitation-sent');
    }

    public function showInvitation(Request $request, string $token): Response
    {
        $invitation = $this->service->findByToken($token);

        abort_if($invitation === null, 404);

        return Inertia::render('trade/invitations/show', [
            'base' => '/trade/invitations/'.$token,
            'invitation' => $this->service->invitationView($request->user(), $invitation),
        ]);
    }

    public function dismissAlert(Request $request, int $row): RedirectResponse
    {
        $this->service->dismissAlert($request->user(), $row);

        return back();
    }

    /** An invitation addressed to the person's organization, opened by its number inside the panel. */
    public function showInvitationById(Request $request, int $invitation): Response
    {
        $model = $this->addressedToMe($request, $invitation);

        return Inertia::render('trade/invitations/show', [
            'base' => '/trade/invitations/id/'.$model->id,
            'invitation' => $this->service->invitationView($request->user(), $model),
        ]);
    }

    public function acceptById(Request $request, int $invitation): RedirectResponse
    {
        $data = $request->validate([
            'units' => ['nullable', 'array', 'max:200'],
            'units.*' => ['string', 'max:40'],
            'all_current' => ['nullable', 'boolean'],
        ]);

        $this->service->accept($request->user(), $this->addressedToMe($request, $invitation)->id, $data);

        return to_route('trade.partners.index')->with('status', 'invitation-accepted');
    }

    public function rejectById(Request $request, int $invitation): RedirectResponse
    {
        $this->service->reject($request->user(), $this->addressedToMe($request, $invitation)->id);

        return to_route('trade.partners.index');
    }

    private function addressedToMe(Request $request, int $id): TradeInvitation
    {
        return TradeInvitation::query()->where('invitee_organization_id', $request->user()->organization_id)->findOrFail($id);
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        $data = $request->validate([
            'units' => ['nullable', 'array', 'max:200'],
            'units.*' => ['string', 'max:40'],
            'all_current' => ['nullable', 'boolean'],
        ]);

        $this->service->accept($request->user(), $token, $data);

        return to_route('trade.partners.index')->with('status', 'invitation-accepted');
    }

    public function reject(Request $request, string $token): RedirectResponse
    {
        $this->service->reject($request->user(), $token);

        return to_route('trade.partners.index');
    }

    public function cancel(Request $request, int $invitation): RedirectResponse
    {
        $model = TradeInvitation::query()->where('inviter_organization_id', $request->user()->organization_id)->findOrFail($invitation);

        $this->service->cancel($request->user(), $model);

        return back();
    }

    public function suspend(Request $request, int $relationship): RedirectResponse
    {
        $this->service->suspend($request->user(), $this->relationship($request, $relationship), $request->validate(['reason' => ['nullable', 'string', 'max:500']])['reason'] ?? null);

        return back();
    }

    public function resume(Request $request, int $relationship): RedirectResponse
    {
        $this->service->resume($request->user(), $this->relationship($request, $relationship));

        return back();
    }

    public function end(Request $request, int $relationship): RedirectResponse
    {
        $this->service->end($request->user(), $this->relationship($request, $relationship), $request->validate(['reason' => ['nullable', 'string', 'max:500']])['reason'] ?? null);

        return back();
    }

    public function revokeUnit(Request $request, int $relationship): RedirectResponse
    {
        $data = $request->validate(['unit' => ['required', 'string', 'max:40']]);

        $this->service->revokeUnit($request->user(), $this->relationship($request, $relationship), $data['unit']);

        return back();
    }

    /** Only cooperations the person's organization is a party to exist for them. */
    private function relationship(Request $request, int $id): TradeRelationship
    {
        $organizationId = $request->user()->organization_id;

        return TradeRelationship::query()
            ->where(fn ($query) => $query->where('supplier_organization_id', $organizationId)->orWhere('buyer_organization_id', $organizationId))
            ->findOrFail($id);
    }
}
