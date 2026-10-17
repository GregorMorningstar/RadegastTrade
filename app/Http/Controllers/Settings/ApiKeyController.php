<?php

namespace App\Http\Controllers\Settings;

use App\Enums\HeadquarterType;
use App\Enums\UnitLevel;
use App\Http\Controllers\Controller;
use App\Services\Contracts\ApiKeyServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * API keys of the connectors of the units (a shop or warehouse headquarters or branch): the owner of the organization
 * manages every unit, a headquarters manager theirs and its branches, a branch leader their branch. A key is generated
 * and e-mailed (only when the unit has none) and removed with a code from the e-mail. Who may do what with which unit
 * is decided by the service (policy).
 */
class ApiKeyController extends Controller
{
    public function __construct(private readonly ApiKeyServiceInterface $keys)
    {
        //
    }

    public function edit(Request $request): Response
    {
        $overview = $this->keys->overview($request->user());
        abort_unless($overview['allowed'], 403);

        return Inertia::render('settings/api-key', ['apiKeys' => $overview]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['nullable', 'string', 'max:100']]);
        [$type, $level, $unit] = $this->unit($request);

        $this->keys->issue($request->user(), $type, $level, $unit, (string) ($data['name'] ?? ''));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Klucz API został wygenerowany i wysłany na adres '.$request->user()->email.'.']);

        return back();
    }

    /**
     * Sends the code that confirms removing the key of the unit.
     */
    public function requestRemoval(Request $request): RedirectResponse
    {
        [$type, $level, $unit] = $this->unit($request);

        $this->keys->requestRemoval($request->user(), $type, $level, $unit);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Kod potwierdzający usunięcie klucza wysłaliśmy na adres '.$request->user()->email.'.']);

        return back();
    }

    /**
     * Shows the key of the unit again, after confirming with the password (JSON, never cached).
     */
    public function reveal(Request $request): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string', 'max:255']]);
        [$type, $level, $unit] = $this->unit($request);

        $key = $this->keys->reveal($request->user(), $type, $level, $unit, $data['password']);

        return response()->json(['key' => $key])->header('Cache-Control', 'no-store, private');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'digits:6']]);
        [$type, $level, $unit] = $this->unit($request);

        $this->keys->confirmRemoval($request->user(), $type, $level, $unit, $data['code']);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Klucz API został usunięty.']);

        return back();
    }

    /**
     * The unit from the URL: the type ("shops" / "warehouses"), the level ("headquarters" / "branches") and its id.
     *
     * @return array{0: HeadquarterType, 1: UnitLevel, 2: int}
     */
    private function unit(Request $request): array
    {
        $type = HeadquarterType::tryFrom((string) $request->route('type'));
        $level = UnitLevel::tryFrom((string) $request->route('level'));

        abort_if($type === null || $level === null, 404);

        return [$type, $level, (int) $request->route('unit')];
    }
}
