<?php
// File: app/Models/DataTimbangan.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Changelog;
use Illuminate\Support\Facades\Auth;

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

    protected $attributes = [
        'session_status' => 'open',
        'weight_uom' => 'GR',
    ];

    // ============= CHANGELOG TRACKING =============
    
    protected static function boot()
    {
        parent::boot();

        // Log when weighing session is created (started)
        static::created(function ($dataTimbangan) {
            $dataTimbangan->logSessionStart();
        });

        // Log when weighing session is updated
        static::updated(function ($dataTimbangan) {
            $dataTimbangan->logSessionUpdate();
        });

        // Log when weighing session is deleted
        static::deleted(function ($dataTimbangan) {
            $dataTimbangan->logSessionDelete();
        });
    }

    /**
     * Log when weighing session starts
     */
    protected function logSessionStart()
    {
        $user = User::where('nik', $this->nik)->first();
        if (!$user) return;

        $description = "Started weighing session for batch {$this->batch_number} by {$user->full_name} ({$this->nik})";
        
        $additionalData = [
            'session_id' => $this->id,
            'batch_number' => $this->batch_number,
            'material_id' => $this->material_id,
            'material_desc' => $this->material_desc_at_start,
            'machine_id' => $this->machine_id,
            'machine_name' => $this->machine_name_at_start,
            'shift_id' => $this->shift_id,
            'starting_counter_pro' => $this->starting_counter_pro,
            'weight_uom' => $this->weight_uom,
            'started_at' => $this->created_at->toDateTimeString(),
        ];

        Changelog::log(
            Changelog::ACTION_WEIGHING,
            $description,
            $this->nik,
            $this->batch_number,
            $additionalData
        );

        \Log::info('DataTimbangan session started', [
            'id' => $this->id,
            'nik' => $this->nik,
            'batch_number' => $this->batch_number,
            'timestamp' => now()
        ]);
    }

    /**
     * Log when weighing session is updated
     */
    protected function logSessionUpdate()
    {
        $changes = $this->getChanges();
        $original = $this->getOriginal();
        
        // Remove timestamps from changes
        unset($changes['updated_at'], $changes['created_at']);
        
        if (empty($changes)) return;

        // Check if session was closed
        if ($this->isDirty('session_status') && $this->session_status === 'closed') {
            $this->logSessionEnd();
            return;
        }

        // Check if totals were updated (weighing activity)
        if ($this->hasWeightChanges($changes)) {
            $this->logWeightUpdate($changes, $original);
            return;
        }

        // General update
        $this->logGeneralUpdate($changes, $original);
    }

    /**
     * Log when weighing session ends
     */
    protected function logSessionEnd()
    {
        $user = User::where('nik', $this->nik)->first();
        if (!$user) return;

        $duration = $this->created_at->diffInMinutes($this->ended_at ?? now());
        $description = "Ended weighing session for batch {$this->batch_number} - Duration: {$duration} minutes";
        
        $additionalData = [
            'session_id' => $this->id,
            'batch_number' => $this->batch_number,
            'starting_counter_pro' => $this->starting_counter_pro,
            'ending_counter_pro' => $this->ending_counter_pro,
            'duration_minutes' => $duration,
            'ended_at' => $this->ended_at?->toDateTimeString(),
            'summary' => [
                'total_weight_all' => (float) $this->total_weight_all,
                'total_weight_fg' => (float) $this->total_weight_fg,
                'total_weight_runner' => (float) $this->total_weight_runner,
                'total_weight_defect' => (float) $this->total_weight_defect,
                'total_qty_fg' => (float) $this->total_qty_fg,
                'total_boxes' => $this->perBoxData()->count(),
            ],
        ];

        Changelog::log(
            Changelog::ACTION_WEIGHING,
            $description,
            $this->nik,
            $this->batch_number,
            $additionalData
        );

        \Log::info('DataTimbangan session closed', [
            'id' => $this->id,
            'nik' => $this->nik,
            'batch_number' => $this->batch_number,
            'ending_counter_pro' => $this->ending_counter_pro,
            'timestamp' => now()
        ]);
    }

    /**
     * Log weight/quantity updates during session
     */
    protected function logWeightUpdate($changes, $original)
    {
        $user = User::where('nik', $this->nik)->first();
        if (!$user) return;

        // Build description of what weights changed
        $weightFields = array_filter(array_keys($changes), function($key) {
            return str_contains($key, 'weight') || str_contains($key, 'qty');
        });

        $description = "Updated weighing data for batch {$this->batch_number}";
        
        $additionalData = [
            'session_id' => $this->id,
            'batch_number' => $this->batch_number,
            'changes' => $this->formatWeightChanges($changes, $original, $weightFields),
            'current_totals' => [
                'total_weight_all' => (float) $this->total_weight_all,
                'total_weight_fg' => (float) $this->total_weight_fg,
                'total_qty_fg' => (float) $this->total_qty_fg,
            ],
        ];

        Changelog::log(
            Changelog::ACTION_WEIGHING,
            $description,
            $this->nik,
            $this->batch_number,
            $additionalData
        );
    }

    /**
     * Log general updates (non-weight related)
     */
    protected function logGeneralUpdate($changes, $original)
    {
        $user = User::where('nik', $this->nik)->first();
        if (!$user) return;

        $changedFields = array_keys($changes);
        $description = "Updated weighing session for batch {$this->batch_number}";
        
        $additionalData = [
            'session_id' => $this->id,
            'batch_number' => $this->batch_number,
            'changes' => $this->formatChangesForLog($changes, $original),
            'changed_fields' => $changedFields,
        ];

        Changelog::log(
            Changelog::ACTION_UPDATE,
            $description,
            $this->nik,
            $this->batch_number,
            $additionalData
        );
    }

    /**
     * Log when weighing session is deleted
     */
    protected function logSessionDelete()
    {
        $user = Auth::user();
        if (!$user) return;

        $description = "Deleted weighing session for batch {$this->batch_number} - Session ID: {$this->id}";
        
        $additionalData = [
            'deleted_session' => [
                'session_id' => $this->id,
                'operator_nik' => $this->nik,
                'batch_number' => $this->batch_number,
                'material_id' => $this->material_id,
                'machine_id' => $this->machine_id,
                'starting_counter_pro' => $this->starting_counter_pro,
                'ending_counter_pro' => $this->ending_counter_pro,
                'session_status' => $this->session_status,
                'total_weight_fg' => (float) $this->total_weight_fg,
                'total_qty_fg' => (float) $this->total_qty_fg,
            ],
        ];

        Changelog::log(
            Changelog::ACTION_DELETE,
            $description,
            $user->nik,
            $this->batch_number,
            $additionalData
        );
    }

    /**
     * Check if changes include weight or quantity fields
     */
    protected function hasWeightChanges($changes)
    {
        $weightFields = [
            'total_weight_all', 'total_weight_runner', 'total_weight_sapuan',
            'total_weight_purging', 'total_weight_defect', 'total_weight_fg',
            'total_qty_runner', 'total_qty_sapuan', 'total_qty_purging',
            'total_qty_defect', 'total_qty_fg'
        ];

        return !empty(array_intersect(array_keys($changes), $weightFields));
    }

    /**
     * Format weight changes for logging
     */
    protected function formatWeightChanges($changes, $original, $weightFields)
    {
        $formatted = [];
        
        foreach ($weightFields as $field) {
            if (isset($changes[$field])) {
                $formatted[$field] = [
                    'old' => (float) ($original[$field] ?? 0),
                    'new' => (float) $changes[$field],
                    'difference' => (float) $changes[$field] - (float) ($original[$field] ?? 0),
                ];
            }
        }
        
        return $formatted;
    }

    /**
     * Format general changes for logging
     */
    protected function formatChangesForLog($changes, $original)
    {
        $formatted = [];
        
        foreach ($changes as $field => $newValue) {
            $oldValue = $original[$field] ?? null;
            
            $formatted[$field] = [
                'old' => $oldValue,
                'new' => $newValue,
            ];
        }
        
        return $formatted;
    }

    /**
     * Manual method to log session start with custom details
     */
    public function logCustomSessionStart($additionalInfo = [])
    {
        $user = User::where('nik', $this->nik)->first();
        if (!$user) return;

        $description = "Started weighing session for batch {$this->batch_number}";
        
        $additionalData = array_merge([
            'session_id' => $this->id,
            'batch_number' => $this->batch_number,
            'material_desc' => $this->material_desc_at_start,
            'machine_name' => $this->machine_name_at_start,
            'starting_counter_pro' => $this->starting_counter_pro,
        ], $additionalInfo);

        Changelog::log(
            Changelog::ACTION_WEIGHING,
            $description,
            $this->nik,
            $this->batch_number,
            $additionalData
        );
    }

    // ============= RELATIONSHIPS =============
    
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

    /**
     * Get all changelogs for this weighing session
     */
    public function changelogs()
    {
        return $this->hasMany(Changelog::class, 'batch_number', 'batch_number')
            ->where(function($query) {
                $query->where('additional_data->session_id', $this->id)
                      ->orWhere('batch_number', $this->batch_number);
            })
            ->orderBy('timestamp', 'desc');
    }

    // ============= STATIC HELPER METHODS =============

    public static function hasActiveSession($nik)
    {
        return self::where('nik', $nik)
            ->where('session_status', 'open')
            ->whereNull('ending_counter_pro')
            ->exists();
    }

    public static function batchHasActiveSession($batchNumber)
    {
        return self::where('batch_number', $batchNumber)
            ->where('session_status', 'open')
            ->whereNull('ending_counter_pro')
            ->exists();
    }

    public static function getActiveSession($nik)
    {
        return self::where('nik', $nik)
            ->where('session_status', 'open')
            ->whereNull('ending_counter_pro')
            ->with(['productionOrder', 'material', 'machine'])
            ->first();
    }

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

    // ============= INSTANCE METHODS =============

    public function closeSession($endingCounter)
    {
        $this->update([
            'ending_counter_pro' => $endingCounter,
            'session_status' => 'closed',
            'ended_at' => now()
        ]);
    }

    public function isActive()
    {
        return $this->session_status === 'open' && is_null($this->ending_counter_pro);
    }

    public function getTotalBoxesAttribute()
    {
        return $this->perBoxData()->count();
    }

    public function getLatestBoxNumberAttribute()
    {
        $lastBox = $this->perBoxData()->orderBy('box_no', 'desc')->first();
        return $lastBox ? $lastBox->box_no : 0;
    }

    public function getNextBoxNumberAttribute()
    {
        return $this->latest_box_number + 1;
    }

    /**
     * Get session duration in minutes
     */
    public function getSessionDurationAttribute()
    {
        $endTime = $this->ended_at ?? now();
        return $this->created_at->diffInMinutes($endTime);
    }

    /**
     * Get changelog history for this weighing session
     */
    public function getChangeHistory()
    {
        return $this->changelogs()
            ->with('user')
            ->get()
            ->map(function($log) {
                return [
                    'timestamp' => $log->formatted_timestamp,
                    'time_ago' => $log->time_ago,
                    'action' => $log->action_type,
                    'description' => $log->description,
                    'user' => $log->user_name,
                    'user_nik' => $log->user_nik,
                    'user_role' => $log->user_role,
                    'details' => $log->additional_data,
                ];
            });
    }

    // ============= SCOPES =============
    
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