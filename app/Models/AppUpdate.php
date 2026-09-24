<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AppUpdate extends Model
{
    use HasFactory;

    // Permitir asignación masiva para estos campos
    protected $fillable = [
        'version',
        'file_path',
        'is_active',
    ];

    // Asegurarnos de que Laravel trate is_active como true/false
    protected $casts = [
        'is_active' => 'boolean',
    ];
}