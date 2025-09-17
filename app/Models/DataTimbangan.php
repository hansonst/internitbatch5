<?php
// File: app/Models/DataTimbangan.php
// File: app/Models/DataTimbangan.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DataTimbangan extends Model
{
    use HasFactory;

    protected $table = 'data_timbangan';

    protected $fillable = [
        'nik',
        'inisial',
        'batch_number',
        'material_id',
        'machine_id',
        'shift_id',
        'weight_uom',
        'starting_counter_pro',
        'ending_counter_pro',
        'material_desc_at_start',
        'machine_name_at_start',
        'session_status',
        'ended_at',
        'total_weight_all',
        'total_weight_runner',
        'total_weight_sapuan',
        'total_weight_purging',
        'total_weight_defect',
        'total_weight_fg',
        'total_qty_runner',
        'total_qty_sapuan',
        'total_qty_purging',
        'total_qty_defect',
        'total_qty_fg',
    ];

    protected $casts = [
        'starting_counter_pro' => 'integer',
        'ending_counter_pro' => 'integer',
        'total_weight_all' => 'decimal:5',
        'total_weight_runner' => 'decimal:5',
        'total_weight_sapuan' => 'decimal:5',
        'total_weight_purging' => 'decimal:5',
        'total_weight_defect' => 'decimal:5',
        'total_weight_fg' => 'decimal:5',
        'total_qty_runner' => 'decimal:5',
        'total_qty_sapuan' => 'decimal:5',
        'total_qty_purging' => 'decimal:5',
        'total_qty_defect' => 'decimal:5',
        'total_qty_fg' => 'decimal:5',
        'ended_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Set default values for new records
    protected $attributes = [
        'session_status' => 'open',
        'weight_uom' => 'GR',
    ];

    // Relationships
    public function user()
    {
        return $this->belongsTo(User::class, 'nik', 'nik');
    }

    public function productionOrder()
    {
        return $this->belongsTo(ProductionOrder::class, 'batch_number', 'batch_number');
    }

    public function material()
    {
        return $this->belongsTo(Material::class, 'material_id', 'id_mat');
    }

    public function machine()
    {
        return $this->belongsTo(Machine::class, 'machine_id', 'id_machine');
    }

    public function perBoxData()
    {
        return $this->hasMany(DataTimbanganPerbox::class, 'data_timbangan_id');
    }

    // UPDATED: Check if user has active session for any batch
    public static function hasActiveSession($nik)
    {
        return self::where('nik', $nik)
            ->where('session_status', 'open')
            ->whereNull('ending_counter_pro')
            ->exists();
    }

    // UPDATED: Check if batch already has active session
    public static function batchHasActiveSession($batchNumber)
    {
        return self::where('batch_number', $batchNumber)
            ->where('session_status', 'open')
            ->whereNull('ending_counter_pro')
            ->exists();
    }

    // UPDATED: Get active session for user
    public static function getActiveSession($nik)
    {
        return self::where('nik', $nik)
            ->where('session_status', 'open')
            ->whereNull('ending_counter_pro')
            ->with(['productionOrder', 'material', 'machine'])
            ->first();
    }

    // NEW: Get active session with full details for digital scales
    public static function getActiveSessionForDigitalScales($nik)
    {
        return self::select([
                'data_timbangan.*',
                'production_order.material_desc',
                'production_order.machine_name'
            ])
            ->leftJoin('production_order', 'data_timbangan.batch_number', '=', 'production_order.batch_number')
            ->where('data_timbangan.nik', $nik)
            ->where('data_timbangan.session_status', 'open')
            ->whereNull('data_timbangan.ending_counter_pro')
            ->first();
    }

    // NEW: Close the session
    public function closeSession($endingCounter)
    {
        $this->update([
            'ending_counter_pro' => $endingCounter,
            'session_status' => 'closed',
            'ended_at' => now()
        ]);
    }

    // NEW: Check if session is active
    public function isActive()
    {
        return $this->session_status === 'open' && is_null($this->ending_counter_pro);
    }

    // NEW: Get total boxes weighed in this session
    public function getTotalBoxesAttribute()
    {
        return $this->perBoxData()->count();
    }

    // NEW: Get latest box number in this session
    public function getLatestBoxNumberAttribute()
    {
        $lastBox = $this->perBoxData()->orderBy('box_no', 'desc')->first();
        return $lastBox ? $lastBox->box_no : 0;
    }

    // NEW: Get next box number for this session
    public function getNextBoxNumberAttribute()
    {
        return $this->latest_box_number + 1;
    }

    // Boot method for logging
    protected static function boot()
    {
        parent::boot();

        static::created(function ($dataTimbangan) {
            \Log::info('DataTimbangan session started', [
                'id' => $dataTimbangan->id,
                'nik' => $dataTimbangan->nik,
                'batch_number' => $dataTimbangan->batch_number,
                'timestamp' => now()
            ]);
        });

        static::updated(function ($dataTimbangan) {
            if ($dataTimbangan->isDirty('session_status') && $dataTimbangan->session_status === 'closed') {
                \Log::info('DataTimbangan session closed', [
                    'id' => $dataTimbangan->id,
                    'nik' => $dataTimbangan->nik,
                    'batch_number' => $dataTimbangan->batch_number,
                    'ending_counter_pro' => $dataTimbangan->ending_counter_pro,
                    'timestamp' => now()
                ]);
            }
        });
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('session_status', 'open')
                    ->whereNull('ending_counter_pro');
    }

    public function scopeClosed($query)
    {
        return $query->where('session_status', 'closed')
                    ->whereNotNull('ending_counter_pro');
    }

    public function scopeForUser($query, $nik)
    {
        return $query->where('nik', $nik);
    }

    public function scopeForBatch($query, $batchNumber)
    {
        return $query->where('batch_number', $batchNumber);
    }
}