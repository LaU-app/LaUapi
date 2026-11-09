<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AssistantDecision;
use App\Models\StoreVerification;
use App\Services\Marketplace\MerchantVerificationService;
use App\Services\PayPal\PayPalException;
use App\Services\PayPal\SellerStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Panel de LaU: revisión de comercios verificados y bitácora de la compra inteligente. */
class MerchantAdminController extends Controller
{
    public function __construct(private readonly MerchantVerificationService $merchants) {}

    public function index(Request $request)
    {
        $status = $request->query('estado');
        $search = trim((string) $request->query('q'));

        $verifications = StoreVerification::with('store.user:id,name,username,email')
            ->when(in_array($status, array_keys(StoreVerification::STATUS_LABELS), true), fn ($q) => $q->where('status', $status))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('legal_name', 'like', "%{$search}%")
                ->orWhere('nit', 'like', "%{$search}%")
                ->orWhereHas('store', fn ($s) => $s->where('name', 'like', "%{$search}%"))))
            ->orderByRaw("CASE status WHEN 'submitted' THEN 0 WHEN 'rejected' THEN 1 WHEN 'approved' THEN 2 ELSE 3 END")
            ->latest('submitted_at')
            ->paginate(20)
            ->withQueryString();

        $counts = StoreVerification::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $withPaypal = StoreVerification::whereHas('store', fn ($q) => $q->where('paypal_status', 'enabled'))->where('status', 'approved')->count();

        return view('su.comercios', compact('verifications', 'counts', 'withPaypal', 'status', 'search'));
    }

    public function show(StoreVerification $verification)
    {
        $verification->load(['store.user:id,name,username,email', 'store.category:id,name']);
        $productsCount = $verification->store->products()->where('status', 'active')->count();
        $paidOrders = $verification->store->orders()->where('payment_status', 'paid');
        $sales = ['count' => (clone $paidOrders)->count(), 'total' => (clone $paidOrders)->sum('total')];

        return view('su.comercio', compact('verification', 'productsCount', 'sales'));
    }

    public function approve(Request $request, StoreVerification $verification)
    {
        $data = $request->validate(['notes' => 'nullable|string|max:1000']);
        $emailed = $this->merchants->approve($verification, $this->reviewer(), $data['notes'] ?? null);

        return $emailed
            ? back()->with('success', 'Comercio aprobado. Le enviamos un correo a '.$verification->contact_email.' para que conecte PayPal.')
            : back()->with('error', 'Comercio aprobado, pero no se pudo enviar el correo a '.$verification->contact_email.'. Revisa la configuración MAIL_* del servidor.');
    }

    public function reject(Request $request, StoreVerification $verification)
    {
        $data = $request->validate(['notes' => 'required|string|min:10|max:1000'], [
            'notes.required' => 'Explicá al comercio qué debe corregir.',
        ]);
        $this->merchants->reject($verification, $this->reviewer(), $data['notes']);

        return back()->with('success', 'Solicitud devuelta al comercio con tus observaciones.');
    }

    public function suspend(Request $request, StoreVerification $verification)
    {
        $data = $request->validate(['notes' => 'required|string|min:10|max:1000'], [
            'notes.required' => 'Indicá el motivo de la suspensión.',
        ]);
        $this->merchants->suspend($verification, $this->reviewer(), $data['notes']);

        return back()->with('success', 'Comercio suspendido. Dejó de aceptar pagos de inmediato.');
    }

    /** Descarga privada del documento legal (nunca está en public/). */
    public function document(StoreVerification $verification)
    {
        $path = $verification->document_path;
        abort_unless($path && Storage::disk(MerchantVerificationService::DOCUMENTS_DISK)->exists($path), 404);

        $name = Str::slug(pathinfo($verification->document_original_name ?: 'documento', PATHINFO_FILENAME)) ?: 'documento';

        return Storage::disk(MerchantVerificationService::DOCUMENTS_DISK)
            ->download($path, $name.'.'.pathinfo($path, PATHINFO_EXTENSION));
    }

    public function refreshPayPal(StoreVerification $verification)
    {
        try {
            $status = $this->merchants->refreshPayPal($verification->store);
        } catch (PayPalException $e) {
            return back()->with('error', 'No se pudo consultar PayPal: '.$e->getMessage());
        } catch (\Illuminate\Validation\ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first());
        }

        return back()->with('success', $status->state === SellerStatus::ENABLED
            ? 'PayPal confirma que el comercio puede recibir pagos.'
            : 'PayPal todavía no habilita al comercio: '.implode(' ', $status->reasons));
    }

    public function decisions()
    {
        $decisions = AssistantDecision::with(['user:id,name,username', 'recommendedProduct:id,name,price', 'selectedProduct:id,name', 'order:id,code,total,payment_status'])
            ->latest()
            ->paginate(25);

        $outcomes = AssistantDecision::selectRaw('outcome, count(*) as total')->groupBy('outcome')->pluck('total', 'outcome');
        $paid = AssistantDecision::whereNotNull('order_id')->count();

        return view('su.compra-inteligente', compact('decisions', 'outcomes', 'paid'));
    }

    private function reviewer(): string
    {
        $admin = Auth::guard('super')->user();

        return Str::limit(($admin?->name ?? 'Administrador').' <'.($admin?->email ?? 'admin').'>', 160, '');
    }
}
