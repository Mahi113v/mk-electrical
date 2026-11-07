<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class PurchaseItem extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected $casts = ['quantity' => 'decimal:2', 'rate' => 'decimal:2', 'amount' => 'decimal:2'];

    public function purchase()
    {
        return $this->belongsTo(Purchase::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
