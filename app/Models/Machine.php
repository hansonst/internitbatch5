<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Machine extends Model
{
    use HasFactory;

    protected $table = 'master_machines';
    protected $primaryKey = 'id_machine';
    
    protected $fillable = [
        'machine_code',
        'machine_name'
    ];

    // Relationship with production orders
    public function productionOrders()
    {
        return $this->hasMany(ProductionOrder::class, 'machine_id', 'id_machine');
    }
}
