<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Store extends Model
{
    protected $fillable = ['name', 'slug', 'description', 'category_id', 'logo', 'instagram', 'whatsapp', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
        'verified_at' => 'datetime',
        'paypal_checked_at' => 'datetime',
    ];

    protected $appends = ['logo_url', 'is_verified', 'accepts_paypal'];

    // Identificadores internos de PayPal: el frontend recibe el Merchant ID solo al cotizar un pago.
    protected $hidden = ['paypal_tracking_id', 'paypal_merchant_id'];

    /** Columnas mínimas para mostrar una tienda con sus distintivos en listados y pedidos. */
    public const CARD_COLUMNS = 'id,name,slug,logo,verification_status,paypal_status,paypal_merchant_id';

    public function verification()
    {
        return $this->hasOne(StoreVerification::class);
    }

    /** Verificada por LaU (revisión legal aprobada en el panel). */
    public function getIsVerifiedAttribute(): bool
    {
        return ($this->attributes['verification_status'] ?? null) === 'approved';
    }

    /** Verificada y con PayPal habilitado según la última consulta a PayPal. */
    public function getAcceptsPaypalAttribute(): bool
    {
        return $this->is_verified
            && ($this->attributes['paypal_status'] ?? null) === 'enabled'
            && filled($this->attributes['paypal_merchant_id'] ?? null);
    }

    public function scopeAcceptingPaypal($query)
    {
        return $query->where('is_active', true)
            ->where('verification_status', 'approved')
            ->where('paypal_status', 'enabled')
            ->whereNotNull('paypal_merchant_id');
    }

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
