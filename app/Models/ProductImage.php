<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductImage extends Model
{
    protected $fillable = ['filename', 'position'];

    protected $casts = ['position' => 'integer'];

    protected $appends = ['url'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    // Mismo patrón que User::getImagenUrlAttribute(): en BD solo vive el filename.
    public function getUrlAttribute(): string
    {
        return url('marketplace/'.$this->filename);
    }
}
