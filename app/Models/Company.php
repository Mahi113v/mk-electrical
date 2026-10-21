<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    protected $fillable = [
        'code', 'name', 'gstin', 'phone', 'email', 'address', 'city', 'state', 'pincode', 'status', 'allow_negative_stock',
    ];

    protected $casts = ['allow_negative_stock' => 'boolean'];

    public function users()
    {
        return $this->belongsToMany(User::class)->withPivot(['role_id', 'is_default'])->withTimestamps();
    }

    public function years()
    {
        return $this->hasMany(CompanyYear::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
