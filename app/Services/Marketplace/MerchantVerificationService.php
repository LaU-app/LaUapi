<?php

namespace App\Services\Marketplace;

use App\Mail\MerchantApprovedMail;
use App\Models\Store;
use App\Models\StoreVerification;
use App\Services\PayPal\PayPalClient;
use App\Services\PayPal\SellerStatus;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Ciclo de vida de un comercio verificado:
 * solicitud legal → revisión de LaU (panel admin) → conexión con PayPal → habilitado.
 *
 * LaU decide la verificación legal; PayPal decide si el comercio puede cobrar.
 * El estado de PayPal se consulta siempre a PayPal y aquí solo se guarda la última lectura.
 */
class MerchantVerificationService
{
    public const DOCUMENTS_DISK = 'local';

    public const DOCUMENTS_DIR = 'verificaciones-comercios';

    public function __construct(private readonly PayPalClient $paypal) {}

    /** El comercio envía (o reenvía tras correcciones) su solicitud. */
    public function submit(Store $store, array $data, ?UploadedFile $document): StoreVerification
    {
        if (in_array($store->verification_status, ['approved', 'suspended'], true)) {
            throw ValidationException::withMessages([
                'store' => $store->verification_status === 'approved'
                    ? 'Tu comercio ya está verificado.'
                    : 'Tu comercio está suspendido. Escribinos para revisarlo.',
            ]);
        }

        return DB::transaction(function () use ($store, $data, $document) {
            $verification = $store->verification()->firstOrNew();
            $previousDocument = $verification->document_path;

            if ($document) {
                $verification->document_path = $document->store(self::DOCUMENTS_DIR, self::DOCUMENTS_DISK);
                $verification->document_original_name = Str::limit($document->getClientOriginalName(), 150, '');
            }

            if (! $verification->document_path) {
                throw ValidationException::withMessages(['document' => 'Adjuntá el documento legal del comercio (PDF o imagen).']);
            }

            $verification->fill($data + [
                'status' => 'submitted',
                'submitted_at' => now(),
                'reviewed_at' => null,
                'reviewed_by' => null,
            ]);
            $verification->store()->associate($store);
            $verification->save();

            $store->forceFill(['verification_status' => 'submitted', 'verified_at' => null])->save();

            if ($document && $previousDocument && $previousDocument !== $verification->document_path) {
                Storage::disk(self::DOCUMENTS_DISK)->delete($previousDocument);
            }

            return $verification;
        });
    }

    /**
     * Aprueba al comercio y le avisa por correo. Devuelve si el correo se pudo enviar:
     * un fallo de correo no revierte la aprobación, solo se registra.
     */
    public function approve(StoreVerification $verification, string $reviewer, ?string $notes = null): bool
    {
        $this->review($verification, 'approved', $reviewer, $notes);

        try {
            $owner = $verification->store->user?->email;
            $mail = Mail::to($verification->contact_email);
            if ($owner && strcasecmp($owner, $verification->contact_email) !== 0) {
                $mail->cc($owner);
            }
            $mail->send(new MerchantApprovedMail($verification->fresh('store')));

            return true;
        } catch (Throwable $e) {
            Log::warning('Correo de comercio aprobado no enviado', ['store_id' => $verification->store_id, 'error' => class_basename($e)]);

            return false;
        }
    }

    public function reject(StoreVerification $verification, string $reviewer, string $notes): void
    {
        $this->review($verification, 'rejected', $reviewer, $notes);
    }

    /** Suspender quita el distintivo y deja de aceptar pagos de inmediato. */
    public function suspend(StoreVerification $verification, string $reviewer, string $notes): void
    {
        $this->review($verification, 'suspended', $reviewer, $notes);
    }

    /**
     * Genera el enlace de PayPal para que el comercio verificado conecte su cuenta.
     * El comercio vuelve a LaU al terminar y la app consulta a PayPal el resultado.
     */
    public function connectPayPal(Store $store): string
    {
        if ($store->verification_status !== 'approved') {
            throw ValidationException::withMessages(['store' => 'LaU debe aprobar la verificación antes de conectar PayPal.']);
        }

        $trackingId = $store->paypal_tracking_id ?: 'lau-store-'.$store->id.'-'.Str::lower(Str::random(6));
        $returnUrl = config('services.frontend_url').'/tabs/marketplace/empresa?paypal=retorno';

        $actionUrl = $this->paypal->createSellerReferral($trackingId, $returnUrl);

        $store->forceFill([
            'paypal_tracking_id' => $trackingId,
            'paypal_status' => $store->paypal_status === 'enabled' ? 'enabled' : 'pending',
        ])->save();

        return $actionUrl;
    }

    /** Consulta a PayPal el estado del comercio y guarda la lectura. */
    public function refreshPayPal(Store $store): SellerStatus
    {
        if (! $store->paypal_tracking_id) {
            throw ValidationException::withMessages(['store' => 'Este comercio todavía no inició la conexión con PayPal.']);
        }

        $status = $this->paypal->sellerStatus($store->paypal_tracking_id);

        $store->forceFill([
            'paypal_merchant_id' => $status->merchantId ?? $store->paypal_merchant_id,
            'paypal_status' => $status->state === SellerStatus::ENABLED ? 'enabled' : 'pending',
            'paypal_checked_at' => now(),
        ])->save();

        return $status;
    }

    /** Vincula un comercio con una cuenta ya conectada en PayPal (soporte / migraciones). */
    public function linkTrackingId(Store $store, string $trackingId): SellerStatus
    {
        if (! PayPalClient::isValidTrackingId($trackingId)) {
            throw ValidationException::withMessages(['tracking_id' => 'tracking_id inválido.']);
        }

        $store->forceFill(['paypal_tracking_id' => $trackingId, 'paypal_status' => 'pending'])->save();

        return $this->refreshPayPal($store);
    }

    private function review(StoreVerification $verification, string $status, string $reviewer, ?string $notes): void
    {
        DB::transaction(function () use ($verification, $status, $reviewer, $notes) {
            $verification->update([
                'status' => $status,
                'reviewed_at' => now(),
                'reviewed_by' => $reviewer,
                'review_notes' => $notes,
            ]);

            $verification->store->forceFill([
                'verification_status' => $status,
                'verified_at' => $status === 'approved' ? now() : null,
            ])->save();
        });
    }
}
