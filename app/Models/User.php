<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $table = 'users';
    protected $primaryKey = 'nik';
    public $incrementing = false; // Since nik is not auto-incrementing
    protected $keyType = 'string';
    

    protected $fillable = [
        'nik',
        'first_name',
        'last_name',
        'email',
        'password',
        'position',
        'div',
        'dept',
        'inisial',
        'group',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Accessor for full name
    public function getFullNameAttribute()
    {
        return $this->first_name . ' ' . $this->last_name;
    }

    // Mutator for password hashing
    public function setPasswordAttribute($value)
    {
        $this->attributes['password'] = bcrypt($value);
    }

    // Relationships (if needed)
    public function productionOrders()
    {
        return $this->hasMany(ProductionOrder::class, 'created_by', 'nik');
    }
    
    // Relationship with group
    public function groupRelation()
    {
        return $this->belongsTo(Group::class, 'group', 'group_code');
    }
}
