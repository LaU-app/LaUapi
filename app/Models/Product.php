<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use SoftDeletes;

    // user_id queda fuera a propósito: el vendedor siempre sale de
    // $request->user()->products()->create(...), nunca del payload.
    protected $fillable = [
        'store_id', 'category_id', 'universidad_id', 'name', 'description',
        'price', 'stock', 'status', 'condition', 'delivery_point',
    ];

    protected $casts = ['price' => 'decimal:2', 'stock' => 'integer'];

    protected $appends = ['is_available'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function category()
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    public function universidad()
    {
        return $this->belongsTo(Universidad::class, 'universidad_id');
    }

    public function images()
    {
        return $this->hasMany(ProductImage::class)->orderBy('position')->orderBy('id');
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }

    // No se appendea una URL de portada: dispararía N+1. Los controladores
    // siempre hacen with('images') y el front usa images[0].url.
    public function getIsAvailableAttribute(): bool
    {
        return $this->status === 'active' && $this->stock > 0;
    }

    public function scopeVisible($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeAvailable($query)
    {
        return $query->visible()->where('stock', '>', 0);
    }
}
