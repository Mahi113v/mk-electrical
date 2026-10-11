<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WorkStatus extends Model
{
    use HasFactory;

    protected $table = 'work_status';

    protected $fillable = [
        'user_id',
        'work_date',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // One WorkStatus has many WorkLists
    public function workLists()
    {
        return $this->hasMany(WorkList::class, 'work_status_id');
    }
}
