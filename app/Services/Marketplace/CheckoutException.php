<?php

namespace App\Services\Marketplace;

use RuntimeException;

/**
 * Rechazo de negocio del checkout con PayPal (comercio no habilitado, orden sin aprobar,
 * captura duplicada, monto inesperado...). El mensaje es apto para el usuario final.
 */
class CheckoutException extends RuntimeException
{
    /**
     * @param  string  $errorCode  código estable para el frontend (p. ej. not_approved)
     * @param  int  $httpStatus  estado HTTP con el que responde la API
     * @param  list<string>  $details  detalles técnicos para consola y logs (nunca datos sensibles)
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 409,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
