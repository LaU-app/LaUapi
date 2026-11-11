<?php

namespace App\Http\Controllers\Api\Marketplace;

use App\Models\Store;
use App\Models\StorePaymentGateway;
use App\Models\StoreVerification;
use App\Services\Marketplace\MerchantVerificationService;
use App\Services\PayPal\PayPalException;
use App\Services\PayPal\SellerStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Lado del vendedor. Son tres niveles separados:
 *  1. Tienda (emprendimiento): nombre y logo, sin papeles (StoreController).
 *  2. Negocio verificado: solicitud legal con documentos, la revisa el equipo de LaU.
 *  3. Pasarelas de pago: opcionales y aparte; PayPal se pide al registrarse o cuando el negocio quiera.
 */
class MerchantVerificationController extends MarketplaceController
{
    public function __construct(private readonly MerchantVerificationService $merchants) {}

    public function show(Request $request): JsonResponse
    {
        return $this->respond($this->payload($this->loadStore($request)));
    }

    public function submit(Request $request): JsonResponse
    {
        $store = $this->loadStore($request);
        abort_unless($store, 422, 'Primero creá tu tienda en LaU Marketplace.');

        $salvadoran = $request->input('country', 'SV') === 'SV';
        $document = fn (string $kind) => [
            Rule::requiredIf(! $this->documentSent($store->verification, $kind)), 'nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120',
        ];

        $data = $request->validate([
            'legal_name' => 'required|string|min:3|max:160',
            'business_type' => ['required', Rule::in(array_keys(StoreVerification::BUSINESS_TYPES))],
            'country' => ['nullable', Rule::in(array_keys(StoreVerification::COUNTRIES))],
            // En El Salvador, NIT de 14 dígitos (con o sin guiones: 0614-010190-101-3). Fuera, el identificador fiscal del país.
            'nit' => $salvadoran
                ? ['required', 'string', 'regex:/^\d{4}-?\d{6}-?\d{3}-?\d$/']
                : ['required', 'string', 'min:5', 'max:30', 'regex:/^[A-Za-z0-9.\-\s]+$/'],
            // El NRC (registro de IVA) es obligatorio para todo negocio verificado en El Salvador.
            'nrc' => $salvadoran ? ['required', 'string', 'regex:/^\d{1,7}-?\d$/'] : ['nullable', 'string', 'max:30'],
            'legal_representative' => 'required|string|min:3|max:120',
            'contact_email' => 'required|email|max:160',
            'contact_phone' => ['required', 'string', 'regex:/^\+?[\d\s-]{8,20}$/'],
            'address' => 'required|string|min:8|max:255',
            'website' => 'nullable|url|max:255',
            'offer_description' => 'required|string|min:30|max:1500',
            'document' => $document('document'),
            'registration_document' => $document('registration_document'),
            'id_document' => $document('id_document'),
            'request_paypal' => 'nullable|boolean',
            'accepts_terms' => 'accepted',
        ], [
            'nit.regex' => $salvadoran ? 'El NIT debe tener 14 dígitos (por ejemplo 0614-010190-101-3).' : 'Escribí el identificador fiscal tal como aparece en tu registro.',
            'nit.required' => $salvadoran ? 'Escribí el NIT del negocio.' : 'Escribí el identificador fiscal del negocio.',
            'nrc.regex' => 'El NRC debe tener el formato 123456-7.',
            'nrc.required' => 'Escribí el NRC (registro de IVA) del negocio.',
            'document.required' => 'Adjuntá tu registro fiscal (tarjeta de NIT / NRC o constancia de IVA).',
            'registration_document.required' => 'Adjuntá la matrícula de comercio, la escritura de constitución o la licencia municipal.',
            'id_document.required' => 'Adjuntá el DUI o pasaporte del representante legal.',
            'accepts_terms.accepted' => 'Debés aceptar los términos para negocios verificados.',
        ]);

        $files = collect(array_keys(StoreVerification::DOCUMENTS))
            ->mapWithKeys(fn ($kind) => [$kind => $request->file($kind)])
            ->filter()->all();
        $requestPayPal = $request->boolean('request_paypal');
        unset($data['document'], $data['registration_document'], $data['id_document'], $data['accepts_terms'], $data['request_paypal']);
        $data['country'] ??= 'SV';

        $this->merchants->submit($store, $data, $files);
        if ($requestPayPal) {
            $this->merchants->requestGateway($store->fresh(), StorePaymentGateway::PAYPAL);
        }

        return $this->respond($this->payload($this->loadStore($request)), 'Recibimos tu solicitud. El equipo de LaU la revisará.', 201);
    }

    /** Pedir una pasarela de pago: PayPal, o una que LaU todavía no integra ("otra"). */
    public function requestGateway(Request $request): JsonResponse
    {
        $store = $this->ownStore($request);
        $data = $request->validate([
            'gateway' => ['required', Rule::in([StorePaymentGateway::PAYPAL, StorePaymentGateway::OTHER])],
            'gateway_name' => ['nullable', 'string', 'min:2', 'max:80', Rule::requiredIf($request->input('gateway') === StorePaymentGateway::OTHER)],
            'note' => 'nullable|string|max:600',
        ], [
            'gateway_name.required' => 'Contanos qué pasarela usás.',
        ]);

        $this->merchants->requestGateway($store, $data['gateway'], $data['gateway_name'] ?? null, $data['note'] ?? null);

        return $this->respond(
            $this->payload($this->loadStore($request)),
            $data['gateway'] === StorePaymentGateway::PAYPAL ? 'Pediste cobrar con PayPal. El equipo de LaU lo revisará.' : 'Gracias. Tomamos nota de la pasarela que usás.',
            201,
        );
    }

    public function connect(Request $request): JsonResponse
    {
        $store = $this->ownStore($request);

        try {
            $actionUrl = $this->merchants->connectPayPal($store);
        } catch (PayPalException $e) {
            Log::warning('PayPal onboarding: connect', ['store_id' => $store->id, 'error' => $e->getMessage()]);

            return $this->fail('paypal_error', 'PayPal no pudo generar el enlace de conexión. Intentá de nuevo en un momento.', 502);
        }

        return $this->respond(['action_url' => $actionUrl, 'store' => $this->payload($this->loadStore($request))['store']]);
    }

    public function refresh(Request $request): JsonResponse
    {
        $store = $this->ownStore($request);

        try {
            $status = $this->merchants->refreshPayPal($store);
        } catch (PayPalException $e) {
            Log::warning('PayPal onboarding: refresh', ['store_id' => $store->id, 'error' => $e->getMessage()]);

            return $this->fail('paypal_error', 'No pudimos consultar tu estado en PayPal. Intentá de nuevo en un momento.', 502);
        }

        return $this->respond($this->payload($this->loadStore($request)) + [
            'paypal' => [
                'state' => $status->state,
                'checks' => collect($status->checks)->map(fn ($ok, $label) => ['label' => self::checkLabel($label), 'ok' => $ok])->values(),
                'reasons' => $status->reasons,
            ],
        ]);
    }

    private function loadStore(Request $request): ?Store
    {
        return $request->user()->store()->with(['verification', 'paymentGateways'])->first();
    }

    private function ownStore(Request $request): Store
    {
        $store = $request->user()->store()->first();
        abort_unless($store, 422, 'Primero creá tu tienda en LaU Marketplace.');

        return $store;
    }

    private function documentSent(?StoreVerification $verification, string $kind): bool
    {
        return $verification !== null && filled($verification->getAttribute(StoreVerification::DOCUMENTS[$kind][0]));
    }

    private function payload(?Store $store): array
    {
        return [
            'store' => $store ? [
                'id' => $store->id,
                'name' => $store->name,
                'slug' => $store->slug,
                'logo_url' => $store->logo_url,
                'verification_status' => $store->verification_status,
                'verified_at' => $store->verified_at,
                'is_verified' => $store->is_verified,
                'paypal_status' => $store->paypal_status,
                'paypal_checked_at' => $store->paypal_checked_at,
                'accepts_paypal' => $store->accepts_paypal,
            ] : null,
            'verification' => $store?->verification,
            'gateways' => $store ? $store->paymentGateways->values() : [],
            'business_types' => StoreVerification::BUSINESS_TYPES,
            'countries' => StoreVerification::COUNTRIES,
            'documents' => collect(StoreVerification::DOCUMENTS)->map(fn ($meta, $kind) => ['kind' => $kind, 'label' => $meta[2]])->values(),
        ];
    }

    private function fail(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['success' => false, 'code' => $code, 'message' => $message], $status);
    }

    private static function checkLabel(string $label): string
    {
        return match ($label) {
            SellerStatus::CHECK_PAYMENTS => 'Tu cuenta de PayPal puede recibir pagos',
            SellerStatus::CHECK_EMAIL => 'Confirmaste tu correo en PayPal',
            SellerStatus::CHECK_PERMISSIONS => 'Autorizaste a LaU a procesar pagos y reembolsos',
            default => $label,
        };
    }
}
