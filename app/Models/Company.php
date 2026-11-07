<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    /** shared control table: always the main database (never a company's own database) */
    public function getConnectionName()
    {
        return config('database.default');
    }

    protected $fillable = [
        'code', 'name', 'logo', 'image', 'gstin', 'phone', 'email', 'address', 'city', 'state', 'pincode', 'status', 'allow_negative_stock',
        'upi_id', 'upi_name', 'bank_name', 'account_holder', 'account_number', 'ifsc', 'bank_branch',
        'database_name', 'database_tables', 'database_created_at',
    ];

    protected $casts = [
        'allow_negative_stock' => 'boolean',
        'database_tables'      => 'array',
        'database_created_at'  => 'datetime',
    ];

    /** logo_url / image_url: null = the screens show the default MK logo */
    protected $appends = ['logo_url', 'image_url', 'has_database'];

    public function getLogoUrlAttribute(): ?string
    {
        return $this->logo ? asset('storage/' . $this->logo) : null;
    }

    public function getImageUrlAttribute(): ?string
    {
        return $this->image ? asset('storage/' . $this->image) : null;
    }

    public function getHasDatabaseAttribute(): bool
    {
        return !empty($this->database_name);
    }

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

    /** What every screen needs to show the company: name, logo, image, address and how to pay. */
    public function profile(): array
    {
        return [
            'id'             => $this->id,
            'code'           => $this->code,
            'name'           => $this->name,
            'logo_url'       => $this->logo_url,
            'image_url'      => $this->image_url,
            'gstin'          => $this->gstin,
            'phone'          => $this->phone,
            'email'          => $this->email,
            'address'        => collect([$this->address, $this->city, $this->state, $this->pincode])->filter()->implode(', '),
            'upi_id'         => $this->upi_id,
            'upi_name'       => $this->upi_name ?: $this->name,
            'bank_name'      => $this->bank_name,
            'account_holder' => $this->account_holder,
            'account_number' => $this->account_number,
            'ifsc'           => $this->ifsc,
            'bank_branch'    => $this->bank_branch,
        ];
    }
}
