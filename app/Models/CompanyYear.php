<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyYear extends Model
{
    protected $fillable = ['company_id', 'account_year_id', 'is_locked'];

    protected $casts = ['is_locked' => 'boolean'];
}
