<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyYear extends Model
{
    /** shared control table: always the main database (never a company's own database) */
    public function getConnectionName()
    {
        return config('database.default');
    }

    protected $fillable = ['company_id', 'account_year_id', 'is_locked'];

    protected $casts = ['is_locked' => 'boolean'];
}
