<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Feria de comercios dentro de un campus (con anuncio opcional para los estudiantes de ese campus). */
class Feria extends Model
{
    protected $fillable = ['universidad_id', 'titulo', 'descripcion', 'lugar', 'inicia_en', 'termina_en', 'latitud', 'longitud', 'banner_id'];

    protected $casts = [
        'inicia_en' => 'datetime',
        'termina_en' => 'datetime',
        'latitud' => 'float',
        'longitud' => 'float',
    ];

    protected $appends = ['en_curso'];

    public function universidad()
    {
        return $this->belongsTo(Universidad::class);
    }

    public function banner()
    {
        return $this->belongsTo(Banner::class);
    }

    public function getEnCursoAttribute(): bool
    {
        return $this->inicia_en?->isPast() && $this->termina_en?->isFuture();
    }

    /** Ferias que todavía no terminaron. */
    public function scopeVigentes($query)
    {
        return $query->where('termina_en', '>=', now())->orderBy('inicia_en');
    }
}
