<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionRecord extends Model
{
    // Cada linha representa uma medição de um produto em um instante específico.
    protected $fillable = [
        'product_id',
        'produced_quantity',
        'defective_quantity',
        'target_quantity',
        'recorded_at',
    ];

    // Garante que o PHP receba números e data com os tipos esperados.
    protected $casts = [
        'product_id' => 'integer',
        'produced_quantity' => 'integer',
        'defective_quantity' => 'integer',
        'target_quantity' => 'integer',
        'recorded_at' => 'datetime',
    ];

    /** Cada registro pertence a um único produto da mesma planta. */
    public function produto(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
