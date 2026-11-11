<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsPayPalStep;
use App\Services\PayPal\PayPalClient;
use Illuminate\Console\Command;

class PayPalCheck extends Command
{
    use RunsPayPalStep;

    protected $signature = 'paypal:check';

    protected $aliases = ['paypal:sandbox-check'];

    protected $description = 'Paso 1: comprueba las credenciales de la app Platform de PayPal (OAuth client_credentials)';

    public function handle(PayPalClient $paypal): int
    {
        return $this->runPayPalStep(function () use ($paypal) {
            $token = $paypal->checkCredentials();

            $this->info('OK: OAuth client_credentials aceptado por PayPal ('.$paypal->mode().').');
            $this->line(sprintf('  Token válido por ~%d min (no se muestra ni se guarda).', intdiv($token['expires_in'], 60)));

            $this->line('Permisos de la app para los siguientes pasos:');
            $this->scopeLine($token['scopes'], PayPalClient::SCOPE_PARTNER_REFERRALS, 'paso 2, generar enlace');
            $this->scopeLine($token['scopes'], PayPalClient::SCOPE_SELLER_STATUS, 'paso 3, estado del vendedor');

            $this->line('Configuración local:');
            $this->line('  PAYPAL_PLATFORM_ACCOUNT_ID: '.($paypal->hasPlatformAccountId() ? 'configurado' : 'FALTA (necesario para el paso 3)'));
            $this->line('  PAYPAL_BN_CODE: '.($paypal->hasBnCode() ? 'configurado' : 'vacío (opcional; configúralo si PayPal lo pide)'));

            return self::SUCCESS;
        });
    }

    private function scopeLine(array $scopes, string $scope, string $usedFor): void
    {
        if (in_array($scope, $scopes, true)) {
            $this->line("  [OK] {$scope} ({$usedFor})");

            return;
        }

        $this->warn("  [FALTA] {$scope} ({$usedFor})");
        $this->line('         Confirma en developer.paypal.com que la app es de tipo Platform y tiene activo el onboarding de vendedores.');
    }
}
