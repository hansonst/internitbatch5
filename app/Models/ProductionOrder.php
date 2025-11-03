<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\DataTimbangan;
use App\Models\Changelog;
use Illuminate\Support\Facades\Auth;

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
        'assigned_group_code',
        'order_status'  // ← Added this
    ];

    protected $casts = [
        'manufacturing_date' => 'datetime',
        'finish_date' => 'datetime',
        'quantity_required' => 'decimal:2',
        'quantity_fulfilled' => 'decimal:2',
        'shift_id' => 'integer',
        'is_approved' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $attributes = [
        'shift_id' => null,
        'is_approved' => null,
        'assigned_group_code' => null,
        'order_status' => 'active'  // ← Added this with default value
    ];

    // ← Added these scopes for easy filtering
    public function scopeActive($query)
    {
        return $query->where('order_status', 'active');
    }
    
    public function scopeInactive($query)
    {
        return $query->where('order_status', 'inactive');
    }


    // ============= CHANGELOG TRACKING =============
    
    /**
     * Boot method to register model events for changelog
     */
    protected static function boot()
    {
        parent::boot();

        // Log when a new production order is created
        static::created(function ($productionOrder) {
            $productionOrder->logCreate();
        });

        // Log when a production order is updated
        static::updated(function ($productionOrder) {
            $productionOrder->logUpdate();
        });

        // Log when a production order is deleted
        static::deleted(function ($productionOrder) {
            $productionOrder->logDelete();
        });
    }

    /**
     * Log creation of production order
     */
    protected function logCreate()
    {
        $user = Auth::user();
        if (!$user) return;

        $description = "Created production order {$this->batch_number} for material {$this->material_desc}";
        
        $additionalData = [
            'no_pro' => $this->no_pro,
            'material_id' => $this->material_id,
            'material_desc' => $this->material_desc,
            'machine_id' => $this->machine_id,
            'machine_name' => $this->machine_name,
            'quantity_required' => (float) $this->quantity_required,
            'manufacturing_date' => $this->manufacturing_date?->toDateTimeString(),
            'finish_date' => $this->finish_date?->toDateTimeString(),
            'assigned_group_code' => $this->assigned_group_code,
        ];

        Changelog::log(
            Changelog::ACTION_CREATE,
            $description,
            $user->nik,
            $this->batch_number,
            $additionalData,
            $this->transaction_id  // ADDED: Pass transaction_id
        );
    }

    /**
     * Log updates to production order
     */
    protected function logUpdate()
    {
        $user = Auth::user();
        if (!$user) return;

        // Get what changed
        $changes = $this->getChanges();
        $original = $this->getOriginal();
        
        // Remove timestamps from changes to focus on meaningful data
        unset($changes['updated_at'], $changes['created_at']);
        
        if (empty($changes)) return;

        // Build description based on what changed
        $changedFields = array_keys($changes);
        $description = $this->buildUpdateDescription($changedFields, $changes, $original);

        $additionalData = [
            'changes' => $this->formatChangesForLog($changes, $original),
            'changed_fields' => $changedFields,
        ];

        // Use special action types for specific updates
        $actionType = $this->determineUpdateActionType($changedFields);

        Changelog::log(
            $actionType,
            $description,
            $user->nik,
            $this->batch_number,
            $additionalData,
            $this->transaction_id  // ADDED: Pass transaction_id
        );
    }

    /**
     * Log deletion of production order
     */
    protected function logDelete()
    {
        $user = Auth::user();
        if (!$user) return;

        $description = "Deleted production order {$this->batch_number} - {$this->material_desc}";
        
        $additionalData = [
            'deleted_data' => [
                'no_pro' => $this->no_pro,
                'material_id' => $this->material_id,
                'material_desc' => $this->material_desc,
                'batch_number' => $this->batch_number,
                'machine_id' => $this->machine_id,
                'quantity_required' => (float) $this->quantity_required,
                'quantity_fulfilled' => (float) $this->quantity_fulfilled,
                'is_approved' => $this->is_approved,
            ],
        ];

        Changelog::log(
            Changelog::ACTION_DELETE,
            $description,
            $user->nik,
            $this->batch_number,
            $additionalData,
            $this->transaction_id  // ADDED: Pass transaction_id
        );
    }

    /**
     * Build human-readable description of what was updated
     */
    protected function buildUpdateDescription($changedFields, $changes, $original)
    {
        $fieldNames = [
            'no_pro' => 'Production Order Number',
            'material_id' => 'Material ID',
            'material_desc' => 'Material Description',
            'machine_id' => 'Machine ID',
            'machine_name' => 'Machine Name',
            'manufacturing_date' => 'Manufacturing Date',
            'finish_date' => 'Finish Date',
            'quantity_required' => 'Required Quantity',
            'quantity_fulfilled' => 'Fulfilled Quantity',
            'shift_id' => 'Shift',
            'is_approved' => 'Approval Status',
            'assigned_group_code' => 'Assigned Group',
            'order_status' => 'Order Status',  // ADDED: Added order_status to field names
        ];

        // Special case for approval
        if (in_array('is_approved', $changedFields)) {
            $status = $changes['is_approved'] ? 'approved' : 'unapproved';
            return "Production order {$this->batch_number} has been {$status}";
        }

        // Special case for order status
        if (in_array('order_status', $changedFields) && count($changedFields) === 1) {
            $old = $original['order_status'] ?? 'unknown';
            $new = $changes['order_status'];
            return "Updated order_status for production order {$this->batch_number}";
        }

        // Special case for quantity fulfilled
        if (in_array('quantity_fulfilled', $changedFields) && count($changedFields) === 1) {
            $old = $original['quantity_fulfilled'] ?? 0;
            $new = $changes['quantity_fulfilled'];
            return "Updated fulfilled quantity for {$this->batch_number} from {$old} to {$new}";
        }

        // General case
        $readableFields = array_map(function($field) use ($fieldNames) {
            return $fieldNames[$field] ?? $field;
        }, $changedFields);

        $fieldsStr = count($readableFields) > 1 
            ? implode(', ', array_slice($readableFields, 0, -1)) . ' and ' . end($readableFields)
            : $readableFields[0];

        return "Updated {$fieldsStr} for production order {$this->batch_number}";
    }

    /**
     * Format changes for logging with before/after values
     */
    protected function formatChangesForLog($changes, $original)
    {
        $formatted = [];
        
        foreach ($changes as $field => $newValue) {
            $oldValue = $original[$field] ?? null;
            
            // Format datetime values
            if ($this->isDateAttribute($field)) {
                $oldValue = $oldValue ? (new \DateTime($oldValue))->format('Y-m-d H:i:s') : null;
                $newValue = $newValue ? (new \DateTime($newValue))->format('Y-m-d H:i:s') : null;
            }
            
            $formatted[$field] = [
                'old' => $oldValue,
                'new' => $newValue,
            ];
        }
        
        return $formatted;
    }

    /**
     * Determine specific action type based on what was changed
     */
    protected function determineUpdateActionType($changedFields)
    {
        // If only is_approved changed
        if ($changedFields === ['is_approved']) {
            return $this->is_approved ? Changelog::ACTION_APPROVE : Changelog::ACTION_REJECT;
        }
        
        return Changelog::ACTION_UPDATE;
    }

    /**
     * Manual method to log approval with custom message
     */
    public function logApproval($userNik = null)
    {
        $user = $userNik ? \App\Models\User::where('nik', $userNik)->first() : Auth::user();
        if (!$user) return;

        $description = "Approved production order {$this->batch_number} - {$this->material_desc}";
        
        Changelog::log(
            Changelog::ACTION_APPROVE,
            $description,
            $user->nik,
            $this->batch_number,
            [
                'quantity_required' => (float) $this->quantity_required,
                'quantity_fulfilled' => (float) $this->quantity_fulfilled,
                'approved_at' => now()->toDateTimeString(),
            ],
            $this->transaction_id  // ADDED: Pass transaction_id
        );
    }

    /**
     * Manual method to log rejection with custom message
     */
    public function logRejection($userNik = null, $reason = null)
    {
        $user = $userNik ? \App\Models\User::where('nik', $userNik)->first() : Auth::user();
        if (!$user) return;

        $description = "Rejected production order {$this->batch_number}" . ($reason ? " - Reason: {$reason}" : "");
        
        Changelog::log(
            Changelog::ACTION_REJECT,
            $description,
            $user->nik,
            $this->batch_number,
            [
                'reason' => $reason,
                'rejected_at' => now()->toDateTimeString(),
            ],
            $this->transaction_id  // ADDED: Pass transaction_id
        );
    }

    // ============= RELATIONSHIPS =============
    
    public function material()
    {
        return $this->belongsTo(Material::class, 'material_id', 'id_mat');
    }

    public function machine()
    {
        return $this->belongsTo(Machine::class, 'machine_id', 'id_machine');
    }

    public function assignedGroup()
    {
        return $this->belongsTo(Group::class, 'assigned_group_code', 'group_code');
    }

    public function dataTimbangan()
    {
        return $this->hasMany(DataTimbangan::class, 'batch_number', 'batch_number');
    }

    public function activeDataTimbangan()
    {
        return $this->hasMany(DataTimbangan::class, 'batch_number', 'batch_number')
            ->whereNull('ending_counter_pro');
    }

    /**
     * Get all changelogs for this production order
     */
    public function changelogs()
    {
        return $this->hasMany(Changelog::class, 'batch_number', 'batch_number')
            ->orderBy('timestamp', 'desc');
    }

    /**
     * Get all changelogs for this production order by transaction_id
     */
    public function changelogsByTransaction()
    {
        return $this->hasMany(Changelog::class, 'transaction_id', 'transaction_id')
            ->orderBy('timestamp', 'desc');
    }

    // ============= HELPER METHODS =============
    
    public function isLocked()
    {
        return DataTimbangan::batchHasActiveSession($this->batch_number);
    }

    public function getActiveSession()
    {
        return DataTimbangan::where('batch_number', $this->batch_number)
            ->whereNull('ending_counter_pro')
            ->with(['user'])
            ->first();
    }
    
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

    /**
     * Get changelog history for this production order
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
    
    /**
     * Get changelog history for this production order by transaction_id
     */
    public function getChangeHistoryByTransaction()
    {
        return $this->changelogsByTransaction()
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
}