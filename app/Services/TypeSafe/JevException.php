<?php

namespace App\Services\TypeSafe;

use RuntimeException;

/**
 * Fallo al consultar a Jev. Nunca contiene la API key, encabezados ni el cuerpo de la respuesta.
 * Un fallo es un error, no una respuesta: el código nunca lo interpreta como "no".
 */
class JevException extends RuntimeException
{
    public const NOT_CONFIGURED = 'not_configured';

    public const UNAVAILABLE = 'unavailable';

    public const REJECTED = 'rejected';

    public const INVALID_RESPONSE = 'invalid_response';

    public function __construct(public readonly string $reason, string $message, public readonly ?int $httpStatus = null)
    {
        parent::__construct($message);
    }
}
