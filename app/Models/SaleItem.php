<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaleItem extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['quantity' => 'decimal:2', 'rate' => 'decimal:2', 'amount' => 'decimal:2'];

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
