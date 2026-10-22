<?php

namespace App\Http\Controllers\Trade;

use App\Http\Controllers\Controller;
use App\Services\ColleagueInvitationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Inviting people of the same organization to the units of a cooperation, accepting with a code from the e-mail and the
 * consent of the one who manages a leader. The rights are decided by the service, never by the form.
 */
class ColleagueInvitationController extends Controller
{
    public function __construct(private readonly ColleagueInvitationService $service)
    {
        //
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'units' => ['required', 'array', 'min:1', 'max:200'],
            'units.*' => ['string', 'max:40'],
        ]);

        $this->service->invite($request->user(), (int) $data['user_id'], $data['units']);

        return back()->with('status', 'colleague-invited');
    }

    public function cancel(Request $request, int $invitation): RedirectResponse
    {
        $this->service->cancel($request->user(), $invitation);

        return back();
    }

    public function reject(Request $request, int $invitation): RedirectResponse
    {
        $this->service->reject($request->user(), $invitation);

        return back();
    }

    public function requestConsent(Request $request, int $invitation): RedirectResponse
    {
        $this->service->requestConsent($request->user(), $invitation);

        return back()->with('status', 'consent-requested');
    }

    public function consentCode(Request $request, int $invitation): RedirectResponse
    {
        $this->service->requestConsentCode($request->user(), $invitation);

        return back()->with('status', 'colleague-code-sent');
    }

    public function consent(Request $request, int $invitation): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'digits:6']]);

        $this->service->confirmConsent($request->user(), $invitation, $data['code']);

        return back()->with('status', 'consent-given');
    }

    public function acceptCode(Request $request, int $invitation): RedirectResponse
    {
        $this->service->requestAcceptCode($request->user(), $invitation);

        return back()->with('status', 'colleague-code-sent');
    }

    public function accept(Request $request, int $invitation): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'digits:6']]);

        $this->service->accept($request->user(), $invitation, $data['code']);

        return back()->with('status', 'colleague-accepted');
    }
}
