<?php

namespace Database\Seeders;

use App\Actions\Teams\CreateTeam;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;

class RoleUsersSeeder extends Seeder
{
    /**
     * Tworzy po jednym użytkowniku na rolę: {rola}@example.pl, hasło: qwer1234.
     * Barcode generuje model User (zdarzenie "created"), więc nie wolno wyłączać zdarzeń modeli.
     */
    public function run(): void
    {
        foreach (UserRole::cases() as $role) {
            $email = $role->value.'@example.pl';

            if (User::where('email', $email)->exists()) {
                continue;
            }

            $user = User::create([
                'name' => $role->label(),
                'email' => $email,
                'password' => 'qwer1234', // haszowane przez cast "hashed"
                'role' => $role,
            ]);

            $user->forceFill(['email_verified_at' => now()])->save();

            app(CreateTeam::class)->handle($user, $user->name."'s Team", isPersonal: true);
        }
    }
}
