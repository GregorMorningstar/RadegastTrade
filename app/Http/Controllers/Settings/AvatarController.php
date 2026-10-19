<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Notifications\AvatarChangeCodeNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * User's profile photo (for every role). Without a photo we show the default avatar.
 * A new photo waits on the private disk until the person confirms it with a code from the e-mail;
 * only then it is moved to the public disk and its path is saved in the database.
 */
class AvatarController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    /**
     * Stores the chosen photo as pending and e-mails the confirmation code.
     */
    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'avatar' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:min_width=64,min_height=64,max_width=4000,max_height=4000'],
        ], [
            'avatar.image' => 'Plik musi być zdjęciem.',
            'avatar.mimes' => 'Dozwolone formaty: JPG, PNG, WEBP.',
            'avatar.max' => 'Zdjęcie może mieć najwyżej 2 MB.',
            'avatar.dimensions' => 'Zdjęcie musi mieć od 64 do 4000 px w każdym wymiarze.',
        ]);

        $user = $request->user();
        $this->discardPending($user->id);

        $file = $request->file('avatar');
        $path = $file->storeAs('avatars-pending', $user->id.'-'.Str::random(20).'.'.$file->guessExtension(), 'local');
        $code = (string) random_int(100000, 999999);

        Cache::put(self::cacheKey($user->id), [
            'hash' => ApiKey::hashOf($code),
            'path' => $path,
            'attempts' => 0,
        ], now()->addMinutes(AvatarChangeCodeNotification::VALID_MINUTES));

        try {
            $user->notify(new AvatarChangeCodeNotification($code));
        } catch (\Throwable $e) {
            $this->discardPending($user->id);

            throw $e;
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Kod potwierdzający wysłaliśmy na adres '.$user->email.'.']);

        return to_route('profile.edit');
    }

    /**
     * Confirms the pending photo with the code and saves it.
     */
    public function confirm(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'digits:6']]);
        $user = $request->user();
        $key = self::cacheKey($user->id);
        $pending = Cache::get($key);

        if (! is_array($pending) || ! Storage::disk('local')->exists($pending['path'])) {
            $this->discardPending($user->id);

            throw ValidationException::withMessages(['code' => 'Kod wygasł albo nie został wysłany. Wybierz zdjęcie ponownie.']);
        }

        if (! hash_equals($pending['hash'], ApiKey::hashOf(trim($data['code'])))) {
            $pending['attempts']++;

            if ($pending['attempts'] >= self::MAX_ATTEMPTS) {
                $this->discardPending($user->id);

                throw ValidationException::withMessages(['code' => 'Zbyt wiele błędnych kodów. Wybierz zdjęcie ponownie.']);
            }

            Cache::put($key, $pending, now()->addMinutes(AvatarChangeCodeNotification::VALID_MINUTES));

            throw ValidationException::withMessages(['code' => 'Nieprawidłowy kod.']);
        }

        $old = $user->avatar_path;
        $new = 'avatars/'.Str::random(40).'.'.pathinfo($pending['path'], PATHINFO_EXTENSION);

        Storage::disk('public')->put($new, Storage::disk('local')->get($pending['path']));
        $user->forceFill(['avatar_path' => $new])->save();

        $this->discardPending($user->id);

        if ($old) {
            Storage::disk('public')->delete($old);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Zdjęcie profilowe zostało zapisane.']);

        return to_route('profile.edit');
    }

    /**
     * Drops the pending photo (the person changed their mind).
     */
    public function cancel(Request $request): RedirectResponse
    {
        $this->discardPending($request->user()->id);

        return to_route('profile.edit');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
            $user->forceFill(['avatar_path' => null])->save();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Zdjęcie profilowe zostało usunięte.']);

        return to_route('profile.edit');
    }

    public static function hasPending(int $userId): bool
    {
        return Cache::has(self::cacheKey($userId));
    }

    private function discardPending(int $userId): void
    {
        $pending = Cache::pull(self::cacheKey($userId));

        if (is_array($pending) && isset($pending['path'])) {
            Storage::disk('local')->delete($pending['path']);
        }
    }

    private static function cacheKey(int $userId): string
    {
        return 'avatar-pending:'.$userId;
    }
}
