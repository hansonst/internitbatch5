<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Material extends Model
{
    use HasFactory;
    
    protected $table = 'master_materials';
    protected $primaryKey = 'id_mat';
    
    protected $fillable = [
        'material_code',
        'material_desc',
        'material_type',
        'material_group',
        'material_uom',
        'berat_satuan'  // Add this line
    ];
    
    // Relationship with production orders
    public function productionOrders()
    {
        return $this->hasMany(ProductionOrder::class, 'material_id', 'id_mat');
    }
}