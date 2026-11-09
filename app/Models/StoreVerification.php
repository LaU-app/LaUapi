<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Solicitud de verificación legal de un comercio en LaU Marketplace. */
class StoreVerification extends Model
{
    public const BUSINESS_TYPES = [
        'persona_natural' => 'Persona natural con negocio',
        'sociedad' => 'Sociedad (S.A. de C.V., S.R.L., etc.)',
    ];

    public const STATUS_LABELS = [
        'submitted' => 'En revisión',
        'approved' => 'Aprobada',
        'rejected' => 'Requiere cambios',
        'suspended' => 'Suspendida',
    ];

    protected $fillable = [
        'legal_name', 'business_type', 'nit', 'nrc', 'legal_representative', 'contact_email',
        'contact_phone', 'address', 'website', 'offer_description', 'document_path',
        'document_original_name', 'status', 'submitted_at', 'reviewed_at', 'reviewed_by', 'review_notes',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    // La ruta del archivo nunca sale por la API: solo se sabe si hay documento.
    protected $hidden = ['document_path'];

    protected $appends = ['has_document', 'status_label'];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function getHasDocumentAttribute(): bool
    {
        return filled($this->attributes['document_path'] ?? null);
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->attributes['status'] ?? ''] ?? 'Sin enviar';
    }
}
