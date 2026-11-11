<?php

namespace Database\Seeders;

use App\Models\su_ad;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Crea el superusuario del panel de LaU. Las credenciales vienen del .env (nunca del código):
 * SUPERUSER_EMAIL, SUPERUSER_PASSWORD y SUPERUSER_VERIFY_PASSWORD.
 * Para cambiar la contraseña de un superusuario existente: php artisan lau:admin-password
 */
class SuperUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('SUPERUSER_EMAIL');
        $password = env('SUPERUSER_PASSWORD');
        $verify = env('SUPERUSER_VERIFY_PASSWORD');

        if (! $email || ! $password || ! $verify) {
            $this->command->warn('Definí SUPERUSER_EMAIL, SUPERUSER_PASSWORD y SUPERUSER_VERIFY_PASSWORD en el .env para crear el superusuario.');

            return;
        }

        if (strlen($password) < 12 || strlen($verify) < 12) {
            $this->command->error('Las contraseñas del superusuario deben tener al menos 12 caracteres.');

            return;
        }

        if (su_ad::where('email', $email)->exists()) {
            $this->command->info('El superusuario ya existe; no se modificó. Usá php artisan lau:admin-password para cambiar su contraseña.');

            return;
        }

        su_ad::create([
            'name' => 'Administrador',
            'email' => $email,
            'password' => Hash::make($password),
            'password_verific_modify' => Hash::make($verify),
            'is_admin' => true,
            'imagen' => 'img/usuario.svg',
            'profession' => 'Administrador del Sistema',
            'last_login' => now(),
        ]);

        $this->command->info('Superusuario creado.');
    }
}
