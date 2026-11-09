<?php

namespace App\Http\Controllers\Api\Marketplace;

use App\Models\Store;
use App\Models\StoreVerification;
use App\Services\Marketplace\MerchantVerificationService;
use App\Services\PayPal\PayPalException;
use App\Services\PayPal\SellerStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/** Lado del comercio: solicitud de verificación y conexión con PayPal. */
class MerchantVerificationController extends MarketplaceController
{
    public function __construct(private readonly MerchantVerificationService $merchants) {}

    public function show(Request $request): JsonResponse
    {
        $store = $request->user()->store()->with('verification')->first();

        return $this->respond($this->payload($store));
    }

    public function submit(Request $request): JsonResponse
    {
        $store = $request->user()->store()->with('verification')->first();
        abort_unless($store, 422, 'Primero creá tu tienda en LaU Marketplace.');

        $data = $request->validate([
            'legal_name' => 'required|string|min:3|max:160',
            'business_type' => ['required', Rule::in(array_keys(StoreVerification::BUSINESS_TYPES))],
            // NIT salvadoreño: 14 dígitos (con o sin guiones: 0614-010190-101-3).
            'nit' => ['required', 'string', 'regex:/^\d{4}-?\d{6}-?\d{3}-?\d$/'],
            'nrc' => ['nullable', 'string', 'regex:/^\d{1,7}-?\d$/', Rule::requiredIf($request->input('business_type') === 'sociedad')],
            'legal_representative' => 'required|string|min:3|max:120',
            'contact_email' => 'required|email|max:160',
            'contact_phone' => ['required', 'string', 'regex:/^\+?[\d\s-]{8,20}$/'],
            'address' => 'required|string|min:8|max:255',
            'website' => 'nullable|url|max:255',
            'offer_description' => 'required|string|min:30|max:1500',
            'document' => [Rule::requiredIf(! $store->verification?->has_document), 'nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'accepts_terms' => 'accepted',
        ], [
            'nit.regex' => 'El NIT debe tener 14 dígitos (por ejemplo 0614-010190-101-3).',
            'nrc.regex' => 'El NRC debe tener el formato 123456-7.',
            'nrc.required' => 'Las sociedades deben indicar su NRC.',
            'document.required' => 'Adjuntá el documento legal del comercio (PDF o imagen).',
            'accepts_terms.accepted' => 'Debés aceptar los términos para comercios verificados.',
        ]);

        unset($data['document'], $data['accepts_terms']);
        $this->merchants->submit($store, $data, $request->file('document'));

        return $this->respond($this->payload($store->fresh('verification')), 'Recibimos tu solicitud. El equipo de LaU la revisará.', 201);
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

        return $this->respond(['action_url' => $actionUrl, 'store' => $this->payload($store->fresh('verification'))['store']]);
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

        return $this->respond($this->payload($store->fresh('verification')) + [
            'paypal' => [
                'state' => $status->state,
                'checks' => collect($status->checks)->map(fn ($ok, $label) => ['label' => self::checkLabel($label), 'ok' => $ok])->values(),
                'reasons' => $status->reasons,
            ],
        ]);
    }

    private function ownStore(Request $request): Store
    {
        $store = $request->user()->store()->first();
        abort_unless($store, 422, 'Primero creá tu tienda en LaU Marketplace.');

        return $store;
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
            'business_types' => StoreVerification::BUSINESS_TYPES,
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
