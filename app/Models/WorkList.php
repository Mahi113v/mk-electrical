<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WorkList extends Model
{
    use HasFactory;

    protected $table = 'work_list';

    protected $fillable = [
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
