<?php

namespace App\Models;

use App\Models\Concerns\Tenantable;
use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    use Tenantable;

    protected $guarded = ['id'];

    protected $casts = ['sale_date' => 'date:Y-m-d', 'total' => 'decimal:2'];

    public function items()
    {
        return $this->hasMany(SaleItem::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
