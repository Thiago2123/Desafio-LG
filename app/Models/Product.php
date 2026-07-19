<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    // Campos que podem ser preenchidos em massa pelo Eloquent.
    protected $fillable = [
        'name',
        'slug',
        'line_name',
    ];

    /** Um produto possui vários registros de produção ao longo do tempo. */
    public function registrosDeProducao(): HasMany
    {
        return $this->hasMany(ProductionRecord::class);
    }
}
