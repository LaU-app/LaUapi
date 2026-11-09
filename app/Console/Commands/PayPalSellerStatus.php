<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsPayPalStep;
use App\Services\PayPal\PayPalClient;
use App\Services\PayPal\SellerStatus;
use Illuminate\Console\Command;

class PayPalSellerStatus extends Command
{
    use RunsPayPalStep;

    // Código de salida para "pendiente" (0 = habilitado, 1 = error).
    public const PENDING = 3;

    protected $signature = 'paypal:seller-status
        {tracking_id : El mismo tracking_id usado en paypal:onboard-link}';

    protected $aliases = ['paypal:sandbox-seller-status'];

    protected $description = 'Paso 3: consulta a PayPal si el comercio quedó habilitado (0 habilitado, 3 pendiente, 1 error)';

    public function handle(PayPalClient $paypal): int
    {
        $trackingId = (string) $this->argument('tracking_id');

        return $this->runPayPalStep(function () use ($paypal, $trackingId) {
            $status = $paypal->sellerStatus($trackingId);

            $this->line("tracking_id: {$status->trackingId}");
            $this->line('Merchant ID en PayPal: '.($status->merchantId ?? '(todavía no asignado)'));
            foreach ($status->checks as $label => $ok) {
                $this->line(($ok ? '  [OK] ' : '  [NO] ').$label);
            }

            if ($status->state === SellerStatus::ENABLED) {
                $this->info('Estado: HABILITADO. PayPal confirma pagos, correo y permisos para esta plataforma.');

                return self::SUCCESS;
            }

            foreach ($status->reasons as $reason) {
                $this->line('  - '.$reason);
            }

            if ($status->state === SellerStatus::ERROR) {
                $this->error('Estado: ERROR. Crea otra tienda de prueba con un tracking_id nuevo.');

                return self::FAILURE;
            }

            $this->warn('Estado: PENDIENTE. Completa lo indicado en PayPal y vuelve a ejecutar este comando.');

            return self::PENDING;
        });
    }
}
