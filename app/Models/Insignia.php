<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Insignia extends Model
{
    use HasFactory;

    protected $fillable = [
        'nombre',
        'descripcion',
        'icono',
        'bgicon',
        'slug'
    ];


    /**
     * Relación con User
     * Una insignia pertenece a muchos usuarios
     */
    public function users()
    {
        // Laravel debe de buscar automáticamente la tabla 'insignia_user'
        return $this->belongsToMany(User::class)->withTimestamps();
    }
}
