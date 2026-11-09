<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Bitácora auditable de la compra inteligente: qué preguntó el estudiante, qué
 * candidatos reales vio Jev, sus probabilidades y qué decidió el código.
 */
class AssistantDecision extends Model
{
    protected $fillable = [
        'user_id', 'query', 'max_budget', 'budget_source', 'category_slug', 'outcome', 'model',
        'answers', 'candidates', 'recommended_product_id', 'selected_product_id', 'paypal_order_id', 'order_id',
    ];

    protected $casts = [
        'max_budget' => 'decimal:2',
        'answers' => 'array',
        'candidates' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function recommendedProduct()
    {
        return $this->belongsTo(Product::class, 'recommended_product_id')->withTrashed();
    }

    public function selectedProduct()
    {
        return $this->belongsTo(Product::class, 'selected_product_id')->withTrashed();
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
