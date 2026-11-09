<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsPayPalStep;
use App\Services\PayPal\PayPalClient;
use Illuminate\Console\Command;

class PayPalOnboardLink extends Command
{
    use RunsPayPalStep;

    protected $signature = 'paypal:onboard-link
        {tracking_id : Identificador del comercio en PayPal, p. ej. lau-store-12-k3x9}';

    protected $aliases = ['paypal:sandbox-onboard-link'];

    protected $description = 'Paso 2: genera el enlace de PayPal (Partner Referrals) para vincular un comercio';

    public function handle(PayPalClient $paypal): int
    {
        $trackingId = (string) $this->argument('tracking_id');

        return $this->runPayPalStep(function () use ($paypal, $trackingId) {
            $actionUrl = $paypal->createSellerReferral($trackingId);

            $this->info("OK: enlace de vinculación generado para tracking_id \"{$trackingId}\".");
            $this->line('Ábrelo en una ventana privada e inicia sesión con la cuenta Business del comercio:');
            $this->newLine();
            $this->line($actionUrl);
            $this->newLine();
            $this->line('El enlace es de un solo uso: no lo guardes ni lo compartas. Si caduca, vuelve a ejecutar este comando.');
            $this->line("Al terminar en PayPal ejecuta: php artisan paypal:seller-status {$trackingId}");

            return self::SUCCESS;
        });
    }
}
