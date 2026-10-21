<?php

namespace App\Models;

use App\Models\Concerns\Tenantable;
use Illuminate\Database\Eloquent\Model;

class OpeningStock extends Model
{
    use Tenantable;

    protected $fillable = ['company_id', 'account_year_id', 'product_id', 'quantity', 'source', 'calculated_at', 'updated_by'];

    protected $casts = ['quantity' => 'decimal:2', 'calculated_at' => 'datetime'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
