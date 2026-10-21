<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockAdjustmentItem extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['quantity_change' => 'decimal:2'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}

