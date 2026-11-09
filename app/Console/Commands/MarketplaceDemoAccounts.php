<?php

namespace App\Console\Commands;

use App\Models\Store;
use App\Models\User;
use Database\Seeders\MarketplaceShowcaseSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Prepara las cuentas LaU de demostración (estudiante comprador y comercio de vitrina).
 * Las contraseñas se piden en la consola y nunca se escriben en archivos ni en logs.
 */
class MarketplaceDemoAccounts extends Command
{
    protected $signature = 'marketplace:demo-accounts';

    protected $description = 'Crea o actualiza las cuentas LaU de demostración (estudiante y comercio de vitrina)';

    public function handle(): int
    {
        $store = Store::where('slug', 'papeleria-campus-central')->with('user')->first();
        if (! $store) {
            $this->error('Primero ejecuta: php artisan db:seed --class=MarketplaceShowcaseSeeder');

            return self::FAILURE;
        }

        $password = $this->secret('Contraseña para ambas cuentas de demostración (mínimo 10 caracteres)');
        if (! is_string($password) || mb_strlen($password) < 10) {
            $this->error('La contraseña debe tener al menos 10 caracteres.');

            return self::FAILURE;
        }
        if ($this->secret('Repite la contraseña') !== $password) {
            $this->error('Las contraseñas no coinciden.');

            return self::FAILURE;
        }

        $student = User::firstOrNew(['email' => MarketplaceShowcaseSeeder::STUDENT_EMAIL]);
        $student->forceFill([
            'name' => $student->name ?: 'Estudiante Demo',
            'username' => $student->username ?: 'estudiante_demo',
            'password' => Hash::make($password),
        ])->save();

        $store->user->forceFill(['password' => Hash::make($password)])->save();

        $this->info('Cuentas de demostración listas:');
        $this->line('  Estudiante (compra):  '.$student->email);
        $this->line('  Comercio (vende):     '.$store->user->email.' · '.$store->name);
        $this->line('La contraseña es la que acabas de escribir; compártela solo con los jurados.');

        return self::SUCCESS;
    }
}
