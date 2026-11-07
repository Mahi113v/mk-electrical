<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    /** shared control table: always the main database (never a company's own database) */
    public function getConnectionName()
    {
        return config('database.default');
    }

    protected $fillable = ['company_id', 'code', 'name', 'description', 'permissions', 'is_system'];

    protected $casts = ['permissions' => 'array', 'is_system' => 'boolean'];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
