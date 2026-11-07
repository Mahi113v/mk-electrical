<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WorkList extends Model
{
    use BelongsToCompany;

    use HasFactory;

    protected $table = 'work_list';

    protected $fillable = [
        'company_id',
        'work_status_id',
        'work_place',
        'worker_name',
    ];

    // Each WorkList belongs to one WorkStatus
    public function workStatus()
    {
        return $this->belongsTo(WorkStatus::class, 'work_status_id');
    }
}
