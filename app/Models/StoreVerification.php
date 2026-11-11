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

    /** Países donde LaU recibe negocios (El Salvador primero; el resto con su identificador fiscal). */
    public const COUNTRIES = [
        'SV' => 'El Salvador', 'GT' => 'Guatemala', 'HN' => 'Honduras', 'NI' => 'Nicaragua',
        'CR' => 'Costa Rica', 'PA' => 'Panamá', 'MX' => 'México', 'US' => 'Estados Unidos',
        'CO' => 'Colombia', 'ES' => 'España',
    ];

    /**
     * Documentos que pide la verificación: campo del formulario => [columna de ruta, columna de nombre, etiqueta].
     * Todos son obligatorios: un negocio verificado tiene que estar inscrito legalmente.
     */
    public const DOCUMENTS = [
        'document' => ['document_path', 'document_original_name', 'Registro fiscal (NIT / NRC o equivalente)'],
        'registration_document' => ['registration_document_path', 'registration_document_name', 'Matrícula de comercio, escritura o licencia'],
        'id_document' => ['id_document_path', 'id_document_name', 'Identidad del representante legal'],
    ];

    protected $fillable = [
        'legal_name', 'business_type', 'country', 'nit', 'nrc', 'legal_representative', 'contact_email',
        'contact_phone', 'address', 'website', 'offer_description', 'document_path',
        'document_original_name', 'status', 'submitted_at', 'reviewed_at', 'reviewed_by', 'review_notes',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    // Las rutas de los archivos nunca salen por la API: solo se sabe qué documentos hay.
    protected $hidden = ['document_path', 'registration_document_path', 'id_document_path'];

    protected $appends = ['has_document', 'documents', 'status_label', 'country_label'];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    /** Pasarelas que pidió el negocio (comparten store_id con la verificación). */
    public function gateways()
    {
        return $this->hasMany(StorePaymentGateway::class, 'store_id', 'store_id');
    }

    public function getHasDocumentAttribute(): bool
    {
        return filled($this->attributes['document_path'] ?? null);
    }

    /** @return list<array{kind: string, label: string, sent: bool, name: ?string}> */
    public function getDocumentsAttribute(): array
    {
        return collect(self::DOCUMENTS)->map(fn ($meta, $kind) => [
            'kind' => $kind,
            'label' => $meta[2],
            'sent' => filled($this->attributes[$meta[0]] ?? null),
            'name' => $this->attributes[$meta[1]] ?? null,
        ])->values()->all();
    }

    public function getCountryLabelAttribute(): string
    {
        return self::COUNTRIES[$this->attributes['country'] ?? 'SV'] ?? ($this->attributes['country'] ?? 'El Salvador');
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->attributes['status'] ?? ''] ?? 'Sin enviar';
    }
}
