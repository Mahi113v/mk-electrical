<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;

    public const STATUSES = ['pending', 'upcoming', 'active', 'completed'];

    protected $fillable = [
        'user_id', 'customer_id', 'order_name', 'order_date', 'customer_name',
        'customer_phone', 'whatsapp', 'alternate_phone', 'area', 'city', 'address',
        'service_charge', 'total', 'order_amount', 'referral', 'notes', 'status', 'source',
    ];

    protected $casts = [
        'order_date'     => 'date:Y-m-d',
        'service_charge' => 'decimal:2',
        'total'          => 'decimal:2',
        'order_amount'   => 'decimal:2',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }
}
