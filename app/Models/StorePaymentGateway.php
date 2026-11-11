<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Solicitud de una pasarela de pago para un negocio verificado. Es aparte de la verificación:
 * un negocio puede estar verificado sin cobrar en línea, y pedir PayPal (u otra) cuando quiera.
 */
class StorePaymentGateway extends Model
{
    public const PAYPAL = 'paypal';

    public const OTHER = 'otra';

    /** Pasarelas que LaU ya integra. Las demás se pueden pedir como "otra". */
    public const AVAILABLE = [self::PAYPAL => 'PayPal'];

    public const STATUS_LABELS = [
        'requested' => 'En revisión',
        'approved' => 'Aprobada',
        'rejected' => 'No aprobada',
    ];

    protected $fillable = ['gateway', 'gateway_name', 'status', 'note', 'requested_at', 'reviewed_at', 'reviewed_by', 'review_notes'];

    protected $casts = [
        'requested_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    protected $appends = ['label', 'status_label'];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function getLabelAttribute(): string
    {
        return self::AVAILABLE[$this->attributes['gateway'] ?? ''] ?? ($this->attributes['gateway_name'] ?? 'Otra pasarela');
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->attributes['status'] ?? ''] ?? 'Sin enviar';
    }
}
