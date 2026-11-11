<?php

namespace App\Console\Commands;

use App\Models\su_ad;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Cambia la contraseña de un superusuario del panel de LaU y cierra sus sesiones del panel web
 * y de la app. La contraseña se escribe de forma oculta: nunca queda en el historial de la terminal.
 */
class LauAdminPassword extends Command
{
    protected $signature = 'lau:admin-password {email : correo del superusuario} {--verify : cambiar también la contraseña de verificación}';

    protected $description = 'Cambia la contraseña de un superusuario del panel de LaU y revoca sus sesiones';

    public function handle(): int
    {
        $admin = su_ad::where('email', $this->argument('email'))->first();
        if (! $admin) {
            $this->error('No existe un superusuario con ese correo.');

            return self::FAILURE;
        }

        $password = $this->askNewPassword('Nueva contraseña');
        if ($password === null) {
            return self::FAILURE;
        }

        $changes = ['password' => Hash::make($password), 'remember_token' => null];

        if ($this->option('verify')) {
            $verify = $this->askNewPassword('Nueva contraseña de verificación');
            if ($verify === null) {
                return self::FAILURE;
            }
            $changes['password_verific_modify'] = Hash::make($verify);
        }

        $admin->forceFill($changes)->save();
        $revoked = $admin->tokens()->delete();

        $this->info('Contraseña actualizada.'.($revoked ? " Se cerraron {$revoked} sesiones de la app." : ''));

        return self::SUCCESS;
    }

    private function askNewPassword(string $label): ?string
    {
        $password = (string) $this->secret($label.' (mínimo 12 caracteres)');
        if (strlen($password) < 12) {
            $this->error('Debe tener al menos 12 caracteres.');

            return null;
        }

        if (! hash_equals($password, (string) $this->secret('Repetila'))) {
            $this->error('Las contraseñas no coinciden.');

            return null;
        }

        return $password;
    }
}
