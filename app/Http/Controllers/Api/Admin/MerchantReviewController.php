<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\StorePaymentGateway;
use App\Models\StoreVerification;
use App\Services\Marketplace\MerchantVerificationService;
use App\Services\PayPal\PayPalException;
use App\Services\PayPal\SellerStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Revisión de comercios desde la app (equipo de LaU). Misma lógica que el panel web:
 * aprobar (envía el correo), pedir cambios, suspender y consultar a PayPal.
 * Solo expone datos de solicitudes de comercios, nunca de otros usuarios.
 */
class MerchantReviewController extends Controller
{
    public function __construct(private readonly MerchantVerificationService $merchants) {}

    public function index(Request $request): JsonResponse
    {
        $status = $request->query('estado');
        $search = trim((string) $request->query('q'));

        $pendingGateway = fn ($q) => $q->whereHas('gateways', fn ($g) => $g->where('status', 'requested'));

        $page = StoreVerification::with('store:id,user_id,name,slug,logo,paypal_status,verification_status', 'store.user:id,name,username')
            ->withCount(['gateways as pending_gateways' => fn ($q) => $q->where('status', 'requested')])
            ->when(array_key_exists((string) $status, StoreVerification::STATUS_LABELS), fn ($q) => $q->where('status', $status))
            ->when($status === 'pagos', $pendingGateway)
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('legal_name', 'like', "%{$search}%")
                ->orWhere('nit', 'like', "%{$search}%")
                ->orWhereHas('store', fn ($s) => $s->where('name', 'like', "%{$search}%"))))
            ->orderByRaw("CASE status WHEN 'submitted' THEN 0 WHEN 'rejected' THEN 1 WHEN 'approved' THEN 2 ELSE 3 END")
            ->latest('submitted_at')
            ->paginate(20);

        $counts = StoreVerification::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return response()->json(['success' => true, 'data' => [
            'items' => collect($page->items())->map(fn (StoreVerification $v) => $this->summary($v))->all(),
            'counts' => collect(StoreVerification::STATUS_LABELS)->map(fn ($label, $key) => (int) ($counts[$key] ?? 0))
                ->put('pagos', StoreVerification::where($pendingGateway)->count()),
            'with_paypal' => StoreVerification::where('status', 'approved')
                ->whereHas('store', fn ($q) => $q->where('paypal_status', 'enabled'))->count(),
            'page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'total' => $page->total(),
        ]]);
    }

    public function show(StoreVerification $verification): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->detail($verification)]);
    }

    public function approve(Request $request, StoreVerification $verification): JsonResponse
    {
        $data = $request->validate(['notes' => 'nullable|string|max:1000']);
        $emailed = $this->merchants->approve($verification, $this->reviewer($request), $data['notes'] ?? null);

        return response()->json([
            'success' => true,
            'emailed' => $emailed,
            'message' => $emailed
                ? 'Negocio verificado. Le enviamos un correo con la noticia.'
                : 'Comercio aprobado, pero no se pudo enviar el correo. Revisá la configuración de correo del servidor.',
            'data' => $this->detail($verification->fresh()),
        ]);
    }

    public function reject(Request $request, StoreVerification $verification): JsonResponse
    {
        $data = $request->validate(['notes' => 'required|string|min:10|max:1000'], [
            'notes.required' => 'Explicá al comercio qué debe corregir.',
            'notes.min' => 'Explicá al comercio qué debe corregir (al menos 10 caracteres).',
        ]);
        $this->merchants->reject($verification, $this->reviewer($request), $data['notes']);

        return response()->json(['success' => true, 'message' => 'Solicitud devuelta al comercio con tus observaciones.', 'data' => $this->detail($verification->fresh())]);
    }

    public function suspend(Request $request, StoreVerification $verification): JsonResponse
    {
        $data = $request->validate(['notes' => 'required|string|min:10|max:1000'], [
            'notes.required' => 'Indicá el motivo de la suspensión.',
            'notes.min' => 'Indicá el motivo de la suspensión (al menos 10 caracteres).',
        ]);
        $this->merchants->suspend($verification, $this->reviewer($request), $data['notes']);

        return response()->json(['success' => true, 'message' => 'Comercio suspendido. Dejó de aceptar pagos de inmediato.', 'data' => $this->detail($verification->fresh())]);
    }

    public function refreshPayPal(StoreVerification $verification): JsonResponse
    {
        try {
            $status = $this->merchants->refreshPayPal($verification->store);
        } catch (PayPalException $e) {
            return response()->json(['success' => false, 'message' => 'No se pudo consultar PayPal: '.$e->getMessage()], 502);
        } catch (ValidationException $e) {
            return response()->json(['success' => false, 'message' => collect($e->errors())->flatten()->first()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => $status->state === SellerStatus::ENABLED
                ? 'PayPal confirma que el comercio puede recibir pagos.'
                : 'PayPal todavía no habilita al comercio: '.implode(' ', $status->reasons),
            'data' => $this->detail($verification->fresh()),
        ]);
    }

    public function approveGateway(Request $request, StorePaymentGateway $gateway): JsonResponse
    {
        $data = $request->validate(['notes' => 'nullable|string|max:1000']);
        try {
            $this->merchants->reviewGateway($gateway, true, $this->reviewer($request), $data['notes'] ?? null);
        } catch (ValidationException $e) {
            return response()->json(['success' => false, 'message' => collect($e->errors())->flatten()->first()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => $gateway->gateway === StorePaymentGateway::PAYPAL
                ? 'PayPal aprobado. El negocio ya puede conectar su cuenta.'
                : 'Pedido de pasarela marcado como aprobado.',
            'data' => $this->detail($gateway->store->verification),
        ]);
    }

    public function rejectGateway(Request $request, StorePaymentGateway $gateway): JsonResponse
    {
        $data = $request->validate(['notes' => 'required|string|min:10|max:1000'], [
            'notes.required' => 'Explicá al negocio por qué no se aprueba.',
            'notes.min' => 'Explicá al negocio por qué no se aprueba (al menos 10 caracteres).',
        ]);
        $this->merchants->reviewGateway($gateway, false, $this->reviewer($request), $data['notes']);

        return response()->json(['success' => true, 'message' => 'Pasarela no aprobada. El negocio verá tus observaciones.', 'data' => $this->detail($gateway->store->verification)]);
    }

    /** Documentos legales privados (nunca están en public/). */
    public function document(StoreVerification $verification, string $kind = 'document')
    {
        [$pathColumn, $nameColumn] = StoreVerification::DOCUMENTS[$kind] ?? abort(404);
        $path = $verification->getAttribute($pathColumn);
        abort_unless($path && Storage::disk(MerchantVerificationService::DOCUMENTS_DISK)->exists($path), 404);

        $name = Str::slug(pathinfo($verification->getAttribute($nameColumn) ?: 'documento', PATHINFO_FILENAME)) ?: 'documento';

        return Storage::disk(MerchantVerificationService::DOCUMENTS_DISK)
            ->download($path, $name.'.'.pathinfo($path, PATHINFO_EXTENSION), ['Cache-Control' => 'no-store']);
    }

    private function summary(StoreVerification $v): array
    {
        return [
            'id' => $v->id,
            'status' => $v->status,
            'status_label' => $v->status_label,
            'legal_name' => $v->legal_name,
            'nit' => $v->nit,
            'submitted_at' => $v->submitted_at?->toIso8601String(),
            'pending_gateways' => (int) ($v->pending_gateways ?? $v->gateways()->where('status', 'requested')->count()),
            'store' => $v->store ? [
                'name' => $v->store->name,
                'slug' => $v->store->slug,
                'logo_url' => $v->store->logo_url,
                'paypal_status' => $v->store->paypal_status,
                'owner' => $v->store->user ? ['name' => $v->store->user->name, 'username' => $v->store->user->username] : null,
            ] : null,
        ];
    }

    private function detail(StoreVerification $v): array
    {
        $v->loadMissing('store.user:id,name,username,email', 'store.category:id,name');
        $store = $v->store;
        $paid = $store?->orders()->where('payment_status', 'paid');

        return $this->summary($v) + [
            'business_type' => $v->business_type,
            'business_type_label' => StoreVerification::BUSINESS_TYPES[$v->business_type] ?? $v->business_type,
            'nrc' => $v->nrc,
            'legal_representative' => $v->legal_representative,
            'contact_email' => $v->contact_email,
            'contact_phone' => $v->contact_phone,
            'address' => $v->address,
            'website' => $v->website,
            'offer_description' => $v->offer_description,
            'country' => $v->country,
            'country_label' => $v->country_label,
            'has_document' => $v->has_document,
            'document_name' => $v->document_original_name,
            'documents' => $v->documents,
            'gateways' => $store ? $store->paymentGateways()->get()->map(fn (StorePaymentGateway $g) => [
                'id' => $g->id,
                'gateway' => $g->gateway,
                'label' => $g->label,
                'status' => $g->status,
                'status_label' => $g->status_label,
                'note' => $g->note,
                'requested_at' => $g->requested_at?->toIso8601String(),
                'reviewed_by' => $g->reviewed_by,
                'review_notes' => $g->review_notes,
            ])->all() : [],
            'reviewed_at' => $v->reviewed_at?->toIso8601String(),
            'reviewed_by' => $v->reviewed_by,
            'review_notes' => $v->review_notes,
            'store_detail' => $store ? [
                'description' => $store->description,
                'category' => $store->category?->name,
                'owner_email' => $store->user?->email,
                'paypal_connected' => filled($store->paypal_tracking_id),
                'paypal_checked_at' => $store->paypal_checked_at?->toIso8601String(),
                'accepts_paypal' => $store->accepts_paypal,
                'active_products' => $store->products()->where('status', 'active')->count(),
                'paid_orders' => (int) $paid->count(),
                'paid_total' => number_format((float) (clone $paid)->sum('total'), 2, '.', ''),
            ] : null,
        ];
    }

    private function reviewer(Request $request): string
    {
        $admin = $request->user();

        return Str::limit(($admin?->name ?? 'Administrador').' <'.($admin?->email ?? 'admin').'>', 160, '');
    }
}
