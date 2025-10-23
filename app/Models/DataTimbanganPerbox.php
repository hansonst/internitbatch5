<?php
// File: app/Models/DataTimbanganPerbox.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Changelog;
use Illuminate\Support\Facades\Auth;

class DataTimbanganPerbox extends Model
{
    use HasFactory;

    // This disables Laravel's automatic created_at and updated_at management
    public $timestamps = false;

    protected $table = 'data_timbangan_perbox';

    protected $fillable = [
        'data_timbangan_id',
        'box_no',
        'weight_perbox',
        'category',
        'weighed_at',
        'timbangan_name',
    ];

    protected $casts = [
        'weighed_at' => 'datetime',
        'weight_perbox' => 'decimal:5',
        'box_no' => 'integer',
    ];

    const CATEGORIES = [
        'Runner',
        'Sapuan',
        'Purging',
        'Defect',
        'Finished Good'
    ];

    // ============= CHANGELOG TRACKING =============
    
    protected static function boot()
    {
        parent::boot();

        // Log when a box is weighed (created)
        static::created(function ($perbox) {
            $perbox->logBoxWeighed();
        });

        // Log when box data is updated
        static::updated(function ($perbox) {
            $perbox->logBoxUpdated();
        });

        // Log when box data is deleted
        static::deleted(function ($perbox) {
            $perbox->logBoxDeleted();
        });
    }

    /**
     * Log when a box is weighed
     */
    protected function logBoxWeighed()
    {
        $session = $this->dataTimbangan;
        if (!$session) return;

        $user = $session->user;
        if (!$user) return;

        $description = "Weighed box #{$this->box_no} ({$this->category}) - {$this->weight_perbox} {$session->weight_uom} for batch {$session->batch_number}";
        
        $additionalData = [
            'perbox_id' => $this->id,
            'session_id' => $this->data_timbangan_id,
            'box_number' => $this->box_no,
            'weight' => (float) $this->weight_perbox,
            'category' => $this->category,
            'scale_name' => $this->timbangan_name,
            'weighed_at' => $this->weighed_at?->toDateTimeString(),
            'material_desc' => $session->material_desc_at_start,
            'machine_name' => $session->machine_name_at_start,
            'weight_uom' => $session->weight_uom,
        ];

        Changelog::log(
            Changelog::ACTION_WEIGHING,
            $description,
            $session->nik,
            $session->batch_number,
            $additionalData
        );
    }

    /**
     * Log when box data is updated
     */
    protected function logBoxUpdated()
    {
        $session = $this->dataTimbangan;
        if (!$session) return;

        $changes = $this->getChanges();
        if (empty($changes)) return;

        // Get the user performing the update (could be different from session owner)
        $currentUser = Auth::user();
        $userNik = $currentUser ? $currentUser->nik : $session->nik;
        
        $original = $this->getOriginal();
        
        // Build description based on what changed
        $description = $this->buildUpdateDescription($changes, $original, $session);
        
        $additionalData = [
            'perbox_id' => $this->id,
            'session_id' => $this->data_timbangan_id,
            'box_number' => $this->box_no,
            'changes' => $this->formatChangesForLog($changes, $original, $session),
            'changed_fields' => array_keys($changes),
        ];

        Changelog::log(
            Changelog::ACTION_UPDATE,
            $description,
            $userNik,
            $session->batch_number,
            $additionalData
        );
    }

    /**
     * Log when box data is deleted
     */
    protected function logBoxDeleted()
    {
        $session = $this->dataTimbangan;
        if (!$session) return;

        $currentUser = Auth::user();
        $userNik = $currentUser ? $currentUser->nik : $session->nik;

        $description = "Deleted box #{$this->box_no} ({$this->category}) weighing record for batch {$session->batch_number}";
        
        $additionalData = [
            'deleted_perbox' => [
                'perbox_id' => $this->id,
                'session_id' => $this->data_timbangan_id,
                'box_number' => $this->box_no,
                'weight' => (float) $this->weight_perbox,
                'category' => $this->category,
                'scale_name' => $this->timbangan_name,
                'weighed_at' => $this->weighed_at?->toDateTimeString(),
            ],
            'deleted_by' => $currentUser ? $currentUser->full_name : 'System',
        ];

        Changelog::log(
            Changelog::ACTION_DELETE,
            $description,
            $userNik,
            $session->batch_number,
            $additionalData
        );
    }

    /**
     * Build human-readable description of what was updated
     */
    protected function buildUpdateDescription($changes, $original, $session)
    {
        $changedFields = array_keys($changes);
        
        // Weight correction
        if (in_array('weight_perbox', $changedFields)) {
            $oldWeight = $original['weight_perbox'] ?? 0;
            $newWeight = $changes['weight_perbox'];
            return "Corrected weight for box #{$this->box_no} from {$oldWeight} to {$newWeight} {$session->weight_uom}";
        }
        
        // Category change
        if (in_array('category', $changedFields)) {
            $oldCategory = $original['category'] ?? 'Unknown';
            $newCategory = $changes['category'];
            return "Changed category for box #{$this->box_no} from {$oldCategory} to {$newCategory}";
        }
        
        // Box number change
        if (in_array('box_no', $changedFields)) {
            $oldBoxNo = $original['box_no'] ?? 0;
            $newBoxNo = $changes['box_no'];
            return "Changed box number from #{$oldBoxNo} to #{$newBoxNo} for batch {$session->batch_number}";
        }
        
        // General update
        $fieldNames = [
            'weight_perbox' => 'weight',
            'category' => 'category',
            'box_no' => 'box number',
            'timbangan_name' => 'scale name',
            'weighed_at' => 'weighing time',
        ];
        
        $readableFields = array_map(function($field) use ($fieldNames) {
            return $fieldNames[$field] ?? $field;
        }, $changedFields);
        
        $fieldsStr = count($readableFields) > 1 
            ? implode(', ', array_slice($readableFields, 0, -1)) . ' and ' . end($readableFields)
            : $readableFields[0];
        
        return "Updated {$fieldsStr} for box #{$this->box_no}";
    }

    /**
     * Format changes for logging with before/after values
     */
    protected function formatChangesForLog($changes, $original, $session)
    {
        $formatted = [];
        
        foreach ($changes as $field => $newValue) {
            $oldValue = $original[$field] ?? null;
            
            // Add unit for weight
            if ($field === 'weight_perbox') {
                $formatted[$field] = [
                    'old' => (float) $oldValue,
                    'new' => (float) $newValue,
                    'difference' => (float) $newValue - (float) $oldValue,
                    'unit' => $session->weight_uom,
                ];
            } else {
                $formatted[$field] = [
                    'old' => $oldValue,
                    'new' => $newValue,
                ];
            }
        }
        
        return $formatted;
    }

    /**
     * Manual method to log weighing with custom details
     */
    public function logCustomWeighing($additionalInfo = [])
    {
        $session = $this->dataTimbangan;
        if (!$session) return;

        $user = $session->user;
        if (!$user) return;

        $description = "Weighed box #{$this->box_no} - {$this->weight_perbox} {$session->weight_uom}";
        
        $additionalData = array_merge([
            'perbox_id' => $this->id,
            'session_id' => $this->data_timbangan_id,
            'box_number' => $this->box_no,
            'weight' => (float) $this->weight_perbox,
            'category' => $this->category,
        ], $additionalInfo);

        Changelog::log(
            Changelog::ACTION_WEIGHING,
            $description,
            $session->nik,
            $session->batch_number,
            $additionalData
        );
    }

    // ============= RELATIONSHIPS =============
    
    public function dataTimbangan()
    {
        return $this->belongsTo(DataTimbangan::class, 'data_timbangan_id');
    }

    /**
     * Get the production order through data timbangan
     */
    public function productionOrder()
    {
        return $this->hasOneThrough(
            ProductionOrder::class,
            DataTimbangan::class,
            'id', // Foreign key on data_timbangan
            'batch_number', // Foreign key on production_order
            'data_timbangan_id', // Local key on data_timbangan_perbox
            'batch_number' // Local key on data_timbangan
        );
    }

    /**
     * Get the user who weighed this box
     */
    public function weighedBy()
    {
        return $this->hasOneThrough(
            User::class,
            DataTimbangan::class,
            'id', // Foreign key on data_timbangan
            'nik', // Foreign key on users
            'data_timbangan_id', // Local key on data_timbangan_perbox
            'nik' // Local key on data_timbangan
        );
    }

    // ============= SCOPES =============
    
    /**
     * Filter by category
     */
    public function scopeByCategory($query, $category)
    {
        return $query->where('category', $category);
    }

    /**
     * Filter by session
     */
    public function scopeBySession($query, $sessionId)
    {
        return $query->where('data_timbangan_id', $sessionId);
    }

    /**
     * Get finished goods only
     */
    public function scopeFinishedGood($query)
    {
        return $query->where('category', 'Finished Good');
    }

    /**
     * Get defects only
     */
    public function scopeDefect($query)
    {
        return $query->where('category', 'Defect');
    }

    /**
     * Get runner only
     */
    public function scopeRunner($query)
    {
        return $query->where('category', 'Runner');
    }

    /**
     * Order by box number
     */
    public function scopeOrderedByBox($query)
    {
        return $query->orderBy('box_no', 'asc');
    }

    /**
     * Order by weighing time
     */
    public function scopeOrderedByTime($query)
    {
        return $query->orderBy('weighed_at', 'asc');
    }

    /**
     * Filter by date range
     */
    public function scopeWeighedBetween($query, $startDate, $endDate)
    {
        return $query->whereBetween('weighed_at', [$startDate, $endDate]);
    }

    /**
     * Filter by specific scale
     */
    public function scopeByScale($query, $scaleName)
    {
        return $query->where('timbangan_name', $scaleName);
    }

    // ============= HELPER METHODS =============
    
    /**
     * Check if this is a finished good
     */
    public function isFinishedGood()
    {
        return $this->category === 'Finished Good';
    }

    /**
     * Check if this is a defect
     */
    public function isDefect()
    {
        return $this->category === 'Defect';
    }

    /**
     * Check if this is runner/waste
     */
    public function isWaste()
    {
        return in_array($this->category, ['Runner', 'Sapuan', 'Purging']);
    }

    /**
     * Get formatted weight with unit
     */
    public function getFormattedWeightAttribute()
    {
        $session = $this->dataTimbangan;
        $unit = $session ? $session->weight_uom : 'GR';
        return number_format($this->weight_perbox, 2) . ' ' . $unit;
    }

    /**
     * Get badge color based on category
     */
    public function getCategoryColorAttribute()
    {
        $colors = [
            'Finished Good' => 'success',
            'Defect' => 'danger',
            'Runner' => 'warning',
            'Sapuan' => 'info',
            'Purging' => 'secondary',
        ];

        return $colors[$this->category] ?? 'secondary';
    }

    /**
     * Validate category
     */
    public static function isValidCategory($category)
    {
        return in_array($category, self::CATEGORIES);
    }

    /**
     * Get category options for forms
     */
    public static function getCategoryOptions()
    {
        return array_combine(self::CATEGORIES, self::CATEGORIES);
    }

    /**
     * Get statistics for a session
     */
    public static function getSessionStatistics($sessionId)
    {
        $boxes = self::where('data_timbangan_id', $sessionId)->get();
        
        return [
            'total_boxes' => $boxes->count(),
            'total_weight' => $boxes->sum('weight_perbox'),
            'by_category' => $boxes->groupBy('category')->map(function($group) {
                return [
                    'count' => $group->count(),
                    'total_weight' => $group->sum('weight_perbox'),
                    'average_weight' => $group->avg('weight_perbox'),
                ];
            }),
            'finished_good_count' => $boxes->where('category', 'Finished Good')->count(),
            'defect_count' => $boxes->where('category', 'Defect')->count(),
            'waste_count' => $boxes->whereIn('category', ['Runner', 'Sapuan', 'Purging'])->count(),
        ];
    }
}