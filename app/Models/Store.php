<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Store extends Model
{
    protected $fillable = ['name', 'slug', 'description', 'category_id', 'logo', 'instagram', 'whatsapp', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    protected $appends = ['logo_url'];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function category()
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    // Mismo patrón que User::getImagenUrlAttribute(): en BD solo vive el filename.
    public function getLogoUrlAttribute(): ?string
    {
        return $this->logo ? url('tiendas/'.$this->logo) : null;
    }

    /** Genera un slug único a partir del nombre de la tienda. */
    public static function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'tienda';
        $slug = $base;
        $suffix = 2;

        while (static::where('slug', $slug)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists()) {
            $slug = Str::limit($base, 80, '').'-'.$suffix++;
        }

        return $slug;
    }
}
