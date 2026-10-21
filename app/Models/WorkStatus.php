<?php

namespace App\Models;

use App\Models\Concerns\Tenantable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WorkStatus extends Model
{
    use HasFactory, Tenantable;

    protected $table = 'work_status';

    protected $fillable = ['company_id', 'account_year_id', 'user_id', 'work_date'];

    protected $casts = ['work_date' => 'date:Y-m-d'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function workLists()
    {
        return $this->hasMany(WorkList::class, 'work_status_id');
    }
}
