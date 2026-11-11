<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsPayPalStep;
use App\Models\Store;
use App\Services\Marketplace\MerchantVerificationService;
use App\Services\PayPal\SellerStatus;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class PayPalLinkStore extends Command
{
    use RunsPayPalStep;

    protected $signature = 'paypal:link-store
        {store : slug de la tienda en LaU Marketplace}
        {tracking_id : tracking_id con el que el comercio ya conectó su cuenta en PayPal}';

    protected $description = 'Vincula una tienda verificada con su cuenta de PayPal ya conectada y guarda el estado que reporta PayPal';

    public function handle(MerchantVerificationService $merchants): int
    {
        $store = Store::where('slug', $this->argument('store'))->first();
        if (! $store) {
            $this->error('ERROR: no existe una tienda con ese slug.');

            return self::FAILURE;
        }

        return $this->runPayPalStep(function () use ($merchants, $store) {
            try {
                $status = $merchants->linkTrackingId($store, (string) $this->argument('tracking_id'));
            } catch (ValidationException $e) {
                $this->error('ERROR: '.collect($e->errors())->flatten()->first());

                return self::FAILURE;
            }

            foreach ($status->checks as $label => $ok) {
                $this->line(($ok ? '  [OK] ' : '  [NO] ').$label);
            }

            if ($status->state !== SellerStatus::ENABLED) {
                $this->warn("{$store->name}: PayPal todavía no habilita el comercio.");
                foreach ($status->reasons as $reason) {
                    $this->line('  - '.$reason);
                }

                return self::FAILURE;
            }

            $this->info("OK: {$store->name} cobra con PayPal".($store->verification_status === 'approved' ? '.' : ' (falta aprobar la verificación en el panel).'));

            return self::SUCCESS;
        });
    }
}
