<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'product_name', 'product_code', 'rate', 'stock'];

    protected $casts = [
        'rate'  => 'decimal:2',
        'stock' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // (old code had hasMany(Order::class) which has no product_id column)
    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }
}
