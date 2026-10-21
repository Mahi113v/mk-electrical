<?php

namespace App\Models;

use App\Models\Concerns\Tenantable;
use Illuminate\Database\Eloquent\Model;

class Purchase extends Model
{
    use Tenantable;

    protected $guarded = ['id'];

    protected $casts = ['purchase_date' => 'date:Y-m-d', 'total' => 'decimal:2'];

    public function items()
    {
        return $this->hasMany(PurchaseItem::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
