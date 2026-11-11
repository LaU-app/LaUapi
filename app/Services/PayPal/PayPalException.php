<?php

namespace App\Services\PayPal;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Error de PayPal con mensaje ya sanitizado para mostrar en consola.
 * Solo incluye estado HTTP, nombre del error, códigos de "issue", debug_id y una pista.
 * Nunca incluye tokens, credenciales, encabezados ni el cuerpo completo de la respuesta.
 */
class PayPalException extends RuntimeException
{
    /**
     * @param  ?int  $httpStatus  estado HTTP de PayPal (null si el error es local: configuración o conexión)
     * @param  string  $paypalName  nombre del error de PayPal (p. ej. UNPROCESSABLE_ENTITY)
     * @param  list<string>  $issues  códigos "issue" de PayPal (p. ej. ORDER_NOT_APPROVED)
     */
    public function __construct(
        string $message,
        public readonly ?string $hint = null,
        public readonly ?int $httpStatus = null,
        public readonly string $paypalName = '',
        public readonly array $issues = [],
    ) {
        parent::__construct($message);
    }

    public function hasIssue(string $issue): bool
    {
        return in_array($issue, $this->issues, true);
    }

    public static function missingConfig(string $envVar, string $howToGet): self
    {
        return new self("Falta {$envVar} en .env.", $howToGet.' Luego ejecuta: php artisan config:clear');
    }

    public static function connection(string $step): self
    {
        return new self(
            "{$step}: no se pudo conectar con PayPal.",
            'Revisa tu conexión a internet o si un proxy/firewall bloquea HTTPS saliente, y reintenta.'
        );
    }

    public static function unexpected(string $step, string $detail): self
    {
        return new self("{$step}: respuesta inesperada de PayPal ({$detail}).", 'Reintenta; si persiste, revisa el estado de PayPal (www.paypal-status.com).');
    }

    public static function fromResponse(string $step, Response $response, string $context): self
    {
        $status = $response->status();
        $name = self::clean($response->json('name') ?? $response->json('error'));
        // Solo la descripción estándar de OAuth (p. ej. "Client Authentication failed"); el texto libre
        // "message" de las demás APIs no se copia para no arrastrar datos inesperados a consola o logs.
        $description = self::clean($response->json('error_description'));
        $issueList = collect((array) $response->json('details', []))
            ->pluck('issue')
            ->filter(fn ($issue) => is_string($issue))
            ->map(fn (string $issue) => self::clean($issue))
            ->unique()
            ->take(5)
            ->values()
            ->all();
        $issues = implode(', ', $issueList);
        $debugId = self::clean($response->json('debug_id') ?? $response->header('PayPal-Debug-Id'));

        $parts = ["{$step}: PayPal respondió HTTP {$status}"];
        if ($name !== '') {
            $parts[] = $name;
        }
        $message = implode(' ', $parts).'.';
        if ($description !== '') {
            $message .= " Detalle: {$description}.";
        }
        if ($issues !== '') {
            $message .= " Issues: {$issues}.";
        }
        if ($debugId !== '') {
            $message .= " debug_id: {$debugId}.";
        }

        return new self($message, self::hintFor($context, $status, $name, $issues), $status, $name, $issueList);
    }

    private static function hintFor(string $context, int $status, string $name, string $issues): string
    {
        $credentials = 'Comprueba en developer.paypal.com > Apps & Credentials, en el entorno de PAYPAL_MODE, que '
            .'PAYPAL_CLIENT_ID y PAYPAL_CLIENT_SECRET son de la app Platform de ese entorno y ejecuta php artisan config:clear.';

        if ($status === 401 || $name === 'invalid_client') {
            return 'PayPal rechazó las credenciales. '.$credentials;
        }

        if ($status === 429) {
            return 'PayPal limitó las solicitudes. Espera un minuto y reintenta.';
        }

        if ($status >= 500) {
            return 'Error temporal de PayPal. Reintenta en unos minutos; si persiste, guarda el debug_id.';
        }

        if ($context === 'oauth') {
            return $credentials;
        }

        if ($context === 'referral') {
            if ($status === 403 || in_array($name, ['NOT_AUTHORIZED', 'PERMISSION_DENIED'], true)) {
                return 'La app no tiene permiso para Partner Referrals. Ejecuta php artisan paypal:check y revisa el scope '
                    .'partner-referrals; la app debe ser de tipo Platform.';
            }

            $hint = 'Revisa que las features PAYMENT y REFUND estén activas en la app Platform (deben coincidir con las que pide LaU).';
            if (str_contains(strtoupper($issues.' '.$name), 'ATTRIBUTION') || str_contains(strtoupper($issues), 'BN')) {
                $hint .= ' PayPal menciona el BN code: configura PAYPAL_BN_CODE.';
            }

            return $hint;
        }

        if ($context === 'orders') {
            if ($status === 403 || in_array($name, ['NOT_AUTHORIZED', 'PERMISSION_DENIED'], true)) {
                return 'PayPal no permite cobrar para esta tienda. Ejecuta php artisan paypal:seller-status con su tracking_id '
                    .'y confirma que el estado es HABILITADO.';
            }
            if (str_contains($issues, 'PAYEE')) {
                return 'PayPal rechazó la tienda receptora. Verifica con paypal:seller-status que la tienda sigue habilitada.';
            }

            return 'Revisa el estado de la orden en PayPal; si el problema persiste, crea una orden nueva.';
        }

        // Consulta de estado del vendedor.
        if ($status === 403 || in_array($name, ['NOT_AUTHORIZED', 'PERMISSION_DENIED'], true)) {
            return 'PayPal no permite consultar vendedores de esa cuenta. Verifica que PAYPAL_PLATFORM_ACCOUNT_ID es el Account ID '
                .'de la cuenta Business dueña de la app y que '
                .'php artisan paypal:check muestra el scope customer/partner.';
        }

        return 'Verifica PAYPAL_PLATFORM_ACCOUNT_ID y el tracking_id usado al generar el enlace.';
    }

    /** Limita un valor de la respuesta a una sola línea corta, sin datos binarios. */
    private static function clean(mixed $value): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        $text = preg_replace('/[^\P{C}]+/u', ' ', (string) $value) ?? '';

        return Str::limit(trim(preg_replace('/\s+/', ' ', $text) ?? ''), 160);
    }
}
