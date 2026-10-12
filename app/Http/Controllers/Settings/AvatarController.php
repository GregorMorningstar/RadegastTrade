<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

/**
 * User's profile photo (for every role). Without a photo we show the default avatar.
 */
class AvatarController extends Controller
{
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
        $old = $user->avatar_path;

        $user->forceFill(['avatar_path' => $request->file('avatar')->store('avatars', 'public')])->save();

        if ($old) {
            Storage::disk('public')->delete($old);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Zdjęcie profilowe zostało zapisane.']);

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
}
