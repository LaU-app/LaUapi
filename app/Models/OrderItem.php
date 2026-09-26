<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    protected $fillable = ['product_id', 'product_name', 'unit_price', 'quantity', 'image_filename'];

    protected $casts = ['unit_price' => 'decimal:2', 'quantity' => 'integer'];

    protected $appends = ['subtotal', 'image_url'];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function getSubtotalAttribute(): float
    {
        return round((float) $this->unit_price * $this->quantity, 2);
    }

    public function getImageUrlAttribute(): ?string
    {
        return $this->image_filename ? url('marketplace/'.$this->image_filename) : null;
    }
}
