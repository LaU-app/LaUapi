<?php

namespace App\Services\PayPal;

/**
 * Estado de una tienda de prueba según lo que PayPal responde (fuente de verdad).
 * Nunca se calcula a partir de datos locales ni de los parámetros del return URL.
 */
final class SellerStatus
{
    public const PENDING = 'pendiente';

    public const ENABLED = 'habilitado';

    public const ERROR = 'error';

    public const CHECK_PAYMENTS = 'PayPal confirma que puede recibir pagos (payments_receivable)';

    public const CHECK_EMAIL = 'Correo principal confirmado (primary_email_confirmed)';

    public const CHECK_PERMISSIONS = 'Permisos otorgados a esta plataforma (oauth_third_party)';

    /**
     * @param  array<string, bool>  $checks  descripción => cumple
     * @param  list<string>  $reasons  qué falta o qué falló
     */
    public function __construct(
        public readonly string $state,
        public readonly string $trackingId,
        public readonly ?string $merchantId,
        public readonly array $checks,
        public readonly array $reasons,
    ) {}

    public static function notLinkedYet(string $trackingId): self
    {
        return new self(self::PENDING, $trackingId, null, [], [
            'PayPal aún no asocia ninguna tienda a este tracking_id: la vinculación no se completó o el tracking_id no coincide '
            .'con el usado en paypal:onboard-link.',
        ]);
    }

    /**
     * Evalúa la respuesta de GET /v1/customer/partners/{partner}/merchant-integrations/{merchant}.
     *
     * @param  list<string>  $requiredScopes
     */
    public static function fromPayPal(string $trackingId, string $merchantId, array $data, string $platformClientId, array $requiredScopes): self
    {
        $reasons = [];

        $paymentsReceivable = ($data['payments_receivable'] ?? null) === true;
        if (! $paymentsReceivable) {
            $reasons[] = 'PayPal aún no confirma que la tienda puede recibir pagos (payments_receivable no es true).';
        }

        $emailConfirmed = ($data['primary_email_confirmed'] ?? null) === true;
        if (! $emailConfirmed) {
            $reasons[] = 'La tienda no ha confirmado su correo principal en PayPal (primary_email_confirmed no es true).';
        }

        // Solo cuentan los permisos otorgados a ESTA app (partner_client_id = nuestro Client ID).
        $grantedToUs = false;
        $grantedScopes = [];
        foreach ((array) ($data['oauth_integrations'] ?? []) as $integration) {
            foreach ((array) ($integration['oauth_third_party'] ?? []) as $thirdParty) {
                $partnerClientId = $thirdParty['partner_client_id'] ?? null;
                if (is_string($partnerClientId) && hash_equals($platformClientId, $partnerClientId)) {
                    $grantedToUs = true;
                    $grantedScopes = array_merge($grantedScopes, array_filter((array) ($thirdParty['scopes'] ?? []), 'is_string'));
                }
            }
        }

        $missingScopes = array_values(array_diff($requiredScopes, $grantedScopes));
        $permissionsGranted = $grantedToUs && $missingScopes === [];
        if (! $grantedToUs) {
            $reasons[] = 'La tienda no ha otorgado permisos a esta plataforma (no hay oauth_third_party con nuestro Client ID).';
        } elseif ($missingScopes !== []) {
            $reasons[] = 'Faltan permisos: '.implode(', ', $missingScopes).'.';
        }

        $checks = [
            self::CHECK_PAYMENTS => $paymentsReceivable,
            self::CHECK_EMAIL => $emailConfirmed,
            self::CHECK_PERMISSIONS => $permissionsGranted,
        ];

        // Un rechazo explícito de PayPal no es "pendiente": la tienda no podrá habilitarse así.
        $denied = collect((array) ($data['products'] ?? []))
            ->filter(fn ($product) => is_array($product) && ($product['vetting_status'] ?? null) === 'DENIED')
            ->pluck('name')
            ->filter(fn ($name) => is_string($name))
            ->values()
            ->all();
        if ($denied !== []) {
            $reasons[] = 'PayPal rechazó la verificación del producto: '.implode(', ', $denied).'.';

            return new self(self::ERROR, $trackingId, $merchantId, $checks, $reasons);
        }

        $state = ($paymentsReceivable && $emailConfirmed && $permissionsGranted) ? self::ENABLED : self::PENDING;

        return new self($state, $trackingId, $merchantId, $checks, $reasons);
    }
}
