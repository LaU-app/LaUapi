<?php

namespace App\Models;


use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Universidad extends Model
{
    use HasFactory;

    protected $fillable = [
        'nombre', 'dominio', 'siglas', 'color_primario',
        // Campus en el mapa: centro, área de entrega (radio) y referencia de Google Places.
        'latitud', 'longitud', 'radio_entrega_m', 'direccion', 'google_place_id', 'ubicado_en',
    ];

    protected $casts = [
        'latitud' => 'float',
        'longitud' => 'float',
        'radio_entrega_m' => 'integer',
        'ubicado_en' => 'datetime',
    ];

    // El id de Google Places es interno (sirve para volver a ubicar el campus).
    protected $hidden = ['google_place_id'];

    protected $table = 'universidades';

    public function carreras()
    {
        return $this->belongsToMany(Carrera::class, 'carrera_universidades');
    }

    public function alumnos()
    {
        return $this->hasMany(User::class, 'universidad_id');
    }

    public function products()
    {
        return $this->hasMany(Product::class, 'universidad_id');
    }

    public function ferias()
    {
        return $this->hasMany(Feria::class);
    }
}
