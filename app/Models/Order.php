<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Order extends Model
{
    protected $fillable = [
        'uuid_cliente', 'code', 'buyer_id', 'seller_id', 'store_id', 'total', 'status',
        'meeting_point', 'meeting_at', 'note', 'cancelled_by', 'cancel_reason',
        'delivered_at', 'cancelled_at',
    ];

    protected $casts = [
        'total' => 'decimal:2',
        'meeting_at' => 'datetime',
        'delivered_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function buyer()
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function seller()
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function cancelledBy()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /** Un pedido tiene dos dueños legítimos: comprador y vendedor. */
    public function scopeForUser($query, int $userId)
    {
        return $query->where(fn ($q) => $q->where('buyer_id', $userId)->orWhere('seller_id', $userId));
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /** Referencia legible para el estudiante: LAU-7K2Q9F. */
    public static function generateCode(): string
    {
        do {
            $code = 'LAU-'.Str::upper(Str::random(6));
        } while (static::where('code', $code)->exists());

        return $code;
    }
}
