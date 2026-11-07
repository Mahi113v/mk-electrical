<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** A company's own product list. Product code is unique inside the company. Stock is per company + year (StockService). */
class Product extends Model
{
    use HasFactory, BelongsToCompany;

    protected $fillable = ['company_id', 'user_id', 'product_name', 'product_code', 'rate', 'image'];

    protected $casts = ['rate' => 'decimal:2'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function purchaseItems()
    {
        return $this->hasMany(PurchaseItem::class);
    }

    public function saleItems()
    {
        return $this->hasMany(SaleItem::class);
    }

    public function adjustmentItems()
    {
        return $this->hasMany(StockAdjustmentItem::class);
    }
}
