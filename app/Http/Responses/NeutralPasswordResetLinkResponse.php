<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The answer to a password reset request for an address that is not in the system (or that asked too often): the same
 * "we have e-mailed the link" as for a known address, so the form cannot be used to find out who has an account.
 */
class NeutralPasswordResetLinkResponse implements FailedPasswordResetLinkRequestResponse
{
    public function __construct(protected string $status)
    {
        //
    }

    public function toResponse($request): Response
    {
        return $request->wantsJson()
            ? new JsonResponse(['message' => trans(Password::RESET_LINK_SENT)], 200)
            : back()->with('status', trans(Password::RESET_LINK_SENT));
    }
}
