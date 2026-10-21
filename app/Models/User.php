<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name', 'email', 'phone', 'password', 'is_admin', 'is_user', 'is_active', 'last_login_at',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_login_at'     => 'datetime',
        'password'          => 'hashed',
        'is_admin'          => 'boolean',   // = platform super admin (manages companies, users, roles, years)
        'is_user'           => 'boolean',
        'is_active'         => 'boolean',
    ];

    protected $appends = ['role'];

    public function getRoleAttribute(): string
    {
        return $this->is_admin ? 'product_admin' : 'user';
    }

    public function companies()
    {
        return $this->belongsToMany(Company::class)->withPivot(['role_id', 'is_default'])->withTimestamps();
    }

    public function years()
    {
        return $this->belongsToMany(AccountYear::class, 'user_year_access');
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }
}
