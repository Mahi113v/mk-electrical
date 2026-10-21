<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    protected $fillable = ['company_id', 'code', 'name', 'description', 'permissions', 'is_system'];

    protected $casts = ['permissions' => 'array', 'is_system' => 'boolean'];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
