<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccountYear extends Model
{
    protected $fillable = ['code', 'label', 'start_date', 'end_date'];

    protected $casts = ['start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d'];

    protected $appends = ['is_current'];

    public function getIsCurrentAttribute(): bool
    {
        $today = now()->toDateString();

        return $this->start_date->toDateString() <= $today && $this->end_date->toDateString() >= $today;
    }

    public function contains(string $date): bool
    {
        return $date >= $this->start_date->toDateString() && $date <= $this->end_date->toDateString();
    }

    /** The year just before this one (null for the first year) */
    public function previous(): ?self
    {
        return self::where('start_date', '<', $this->start_date->toDateString())->orderByDesc('start_date')->first();
    }
}
