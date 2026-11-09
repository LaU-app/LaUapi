<?php

namespace App\Console\Commands\Concerns;

use App\Services\PayPal\PayPalException;
use Closure;
use Throwable;

/**
 * Ejecuta un paso de PayPal y muestra errores sanitizados.
 * Ninguna excepción se relanza: así su traza (que podría contener credenciales
 * como argumentos) no llega a la consola ni a storage/logs.
 */
trait RunsPayPalStep
{
    protected function runPayPalStep(Closure $step): int
    {
        try {
            return $step();
        } catch (PayPalException $e) {
            $this->error('ERROR: '.$e->getMessage());
            if ($e->hint) {
                $this->line('Cómo comprobarlo: '.$e->hint);
            }
        } catch (Throwable $e) {
            $this->error('ERROR inesperado ('.class_basename($e).'). No se muestran detalles para no exponer credenciales.');
            $this->line('Cómo comprobarlo: ejecuta php artisan config:clear y luego php artisan paypal:check.');
        }

        return self::FAILURE;
    }
}
