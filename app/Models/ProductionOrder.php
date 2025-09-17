<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\DataTimbangan;

class ProductionOrder extends Model
{
    use HasFactory;

    protected $table = 'production_order';
    
    // Use batch_number as primary key since there's no id column
    protected $primaryKey = 'batch_number';
    public $incrementing = false;
    protected $keyType = 'string';
    
    protected $fillable = [
        'no_pro',
        'material_id',
        'material_desc',
        'batch_number',
        'machine_id',
        'machine_name',
        'manufacturing_date',
        'finish_date',
        'quantity_required',
        'quantity_fulfilled',
        'shift_id',
        'is_approved',
        'assigned_group_code'
    ];

    protected $casts = [
        'manufacturing_date' => 'datetime',
        'finish_date' => 'datetime',
        'quantity_required' => 'decimal:2',
        'quantity_fulfilled' => 'decimal:2',
        'shift_id' => 'integer',  // This will handle null properly
        'is_approved' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Add this to handle null values properly
    protected $attributes = [
        'shift_id' => null,
        'is_approved' => null,
        'assigned_group_code' => null,
    ];

    // Relationships
    public function material()
    {
        return $this->belongsTo(Material::class, 'material_id', 'id_mat');
    }

    public function machine()
    {
        return $this->belongsTo(Machine::class, 'machine_id', 'id_machine');
    }

    // Relationship with assigned group
    public function assignedGroup()
    {
        return $this->belongsTo(Group::class, 'assigned_group_code', 'group_code');
    }
    // Add this method to your ProductionOrder model

/**
 * Check if this production order's batch is currently locked (has active session)
 */
public function isLocked()
{
    return DataTimbangan::batchHasActiveSession($this->batch_number);
}

/**
 * Get the active session for this batch if any
 */
public function getActiveSession()
{
    return DataTimbangan::where('batch_number', $this->batch_number)
        ->whereNull('ending_counter_pro')
        ->with(['user'])
        ->first();
}

/**
 * Relationship with data timbangan records
 */
public function dataTimbangan()
{
    return $this->hasMany(DataTimbangan::class, 'batch_number', 'batch_number');
}

/**
 * Get only active data timbangan sessions
 */
public function activeDataTimbangan()
{
    return $this->hasMany(DataTimbangan::class, 'batch_number', 'batch_number')
        ->whereNull('ending_counter_pro');
}
    
    // Add accessor for status (to match your Flutter model)
    public function getStatusAttribute()
    {
        if ($this->is_approved === true) {
            return 'Completed';
        } else if ($this->quantity_fulfilled >= $this->quantity_required) {
            return 'Pending';
        } else {
            return 'Ongoing';
        }
    }
}
