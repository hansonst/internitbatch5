<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Group extends Model
{
    use HasFactory;

    protected $table = 'groups';
    
    // Use group_code as primary key
    protected $primaryKey = 'group_code';
    public $incrementing = false;
    protected $keyType = 'string';
    
    protected $fillable = [
        'group_code',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Relationship with users
    public function users()
    {
        return $this->hasMany(User::class, 'group', 'group_code');
    }

    // Relationship with production orders
    public function productionOrders()
    {
        return $this->hasMany(ProductionOrder::class, 'assigned_group_code', 'group_code');
    }
}