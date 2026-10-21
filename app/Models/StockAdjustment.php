<?php

namespace App\Models;

use App\Models\Concerns\Tenantable;
use Illuminate\Database\Eloquent\Model;

class StockAdjustment extends Model
{
    use Tenantable;

    public const REASONS = ['damage', 'lost', 'found', 'correction', 'expired', 'returned', 'other'];

    protected $guarded = ['id'];

    protected $casts = ['adjustment_date' => 'date:Y-m-d', 'is_auto' => 'boolean'];

    public function items()
    {
        return $this->hasMany(StockAdjustmentItem::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
