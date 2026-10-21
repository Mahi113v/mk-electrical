<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** Shared master: the same product list is used by every company. Stock is per company + year (StockService). */
class Product extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'product_name', 'product_code', 'rate', 'image'];

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
