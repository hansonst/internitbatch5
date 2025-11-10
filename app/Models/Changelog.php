<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Changelog extends Model
{
    use HasFactory;

    protected $table = 'changelogs';

    protected $fillable = [
        'action_type',
        'description',
        'user_name',
        'user_nik',
        'user_role',
        'batch_number',
        'transaction_id',  
        'additional_data',
        'timestamp',
    ];

    protected $casts = [
        'additional_data' => 'array',
        'timestamp' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // ============= ACTION TYPE CONSTANTS =============
    const ACTION_CREATE = 'CREATE';
    const ACTION_UPDATE = 'UPDATE';
    const ACTION_DELETE = 'DELETE';
    const ACTION_WEIGHING = 'WEIGHING';
    const ACTION_APPROVE = 'APPROVE';
    const ACTION_REJECT = 'REJECT';
    const ACTION_LOGIN = 'LOGIN';
    const ACTION_LOGOUT = 'LOGOUT';
    const ACTION_START_SHIFT = 'START_SHIFT';
    const ACTION_END_SHIFT = 'END_SHIFT';

    /**
     * Static method to log activity
     * 
     * @param string $actionType - Type of action (use constants above)
     * @param string $description - Human readable description
     * @param string $userNik - NIK of user performing action
     * @param string|null $batchNumber - Related batch number (optional)
     * @param array $additionalData - Extra data to store (optional)
     * @param string|null $transactionId - Related transaction ID (optional)
     * @return Changelog|null
     */
    public static function log($actionType, $description, $userNik, $batchNumber = null, $additionalData = [], $transactionId = null)
    {
        try {
            // Get user info from database
            $user = User::where('nik', $userNik)->first();
            
            if (!$user) {
                \Log::warning("Changelog: User not found for NIK: $userNik");
                // Create log anyway with default values
                return self::create([
                    'action_type' => $actionType,
                    'description' => $description,
                    'user_name' => 'Unknown User',
                    'user_nik' => $userNik,
                    'user_role' => 'USER',
                    'batch_number' => $batchNumber,
                    'transaction_id' => $transactionId,  // ADDED
                    'additional_data' => $additionalData,
                    'timestamp' => now(),
                ]);
            }

            // Determine user role
            $userRole = strtoupper($user->position ?? 'USER');
            if (in_array($userRole, ['ADMIN', 'ADMINISTRATOR', 'ADM'])) {
                $userRole = 'ADMIN';
            } elseif (in_array($userRole, ['OPERATOR', 'OPR'])) {
                $userRole = 'OPERATOR';
            }

            // Create changelog entry
            $changelog = self::create([
                'action_type' => $actionType,
                'description' => $description,
                'user_name' => $user->full_name ?? 'Unknown',
                'user_nik' => $userNik,
                'user_role' => $userRole,
                'batch_number' => $batchNumber,
                'transaction_id' => $transactionId,  // ADDED
                'additional_data' => $additionalData,
                'timestamp' => now(),
            ]);

            \Log::info("Changelog created: {$actionType} by {$user->full_name} ({$userNik})");
            
            return $changelog;

        } catch (\Exception $e) {
            \Log::error('Failed to create changelog: ' . $e->getMessage());
            \Log::error('Stack trace: ' . $e->getTraceAsString());
            return null;
        }
    }

    /**
     * Log activity with current authenticated user
     * 
     * @param string $actionType
     * @param string $description
     * @param string|null $batchNumber
     * @param array $additionalData
     * @param string|null $transactionId
     * @return Changelog|null
     */
    public static function logAuth($actionType, $description, $batchNumber = null, $additionalData = [], $transactionId = null)
    {
        $user = \Illuminate\Support\Facades\Auth::user();
        
        if (!$user) {
            \Log::warning("Changelog: No authenticated user found");
            return null;
        }

        return self::log($actionType, $description, $user->nik, $batchNumber, $additionalData, $transactionId);
    }

    // ============= RELATIONSHIPS =============
    
    /**
     * Get the user who performed this action
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_nik', 'nik');
    }

    /**
     * Get the related production order if batch_number exists
     */
    public function productionOrder()
    {
        return $this->belongsTo(ProductionOrder::class, 'batch_number', 'batch_number');
    }

    /**
     * Get the related production order by transaction_id
     */
    public function productionOrderByTransaction()
    {
        return $this->belongsTo(ProductionOrder::class, 'transaction_id', 'transaction_id');
    }

    // ============= QUERY SCOPES =============
    
    /**
     * Filter by action type
     */
    public function scopeByActionType($query, $actionType)
    {
        return $query->where('action_type', $actionType);
    }

    /**
     * Filter by user NIK
     */
    public function scopeByUser($query, $userNik)
    {
        return $query->where('user_nik', $userNik);
    }

    /**
     * Filter by user role
     */
    public function scopeByRole($query, $role)
    {
        return $query->where('user_role', $role);
    }

    /**
     * Filter by batch number
     */
    public function scopeByBatch($query, $batchNumber)
    {
        return $query->where('batch_number', $batchNumber);
    }

    /**
     * Filter by transaction ID
     */
    public function scopeByTransaction($query, $transactionId)
    {
        return $query->where('transaction_id', $transactionId);
    }

    /**
     * Get recent changelogs within specified days
     */
    public function scopeRecent($query, $days = 30)
    {
        return $query->where('timestamp', '>=', now()->subDays($days));
    }

    /**
     * Order by most recent first
     */
    public function scopeLatest($query)
    {
        return $query->orderBy('timestamp', 'desc');
    }

    /**
     * Get today's changelogs
     */
    public function scopeToday($query)
    {
        return $query->whereDate('timestamp', today());
    }

    /**
     * Get this week's changelogs
     */
    public function scopeThisWeek($query)
    {
        return $query->whereBetween('timestamp', [
            now()->startOfWeek(),
            now()->endOfWeek()
        ]);
    }

    /**
     * Get this month's changelogs
     */
    public function scopeThisMonth($query)
    {
        return $query->whereMonth('timestamp', now()->month)
                    ->whereYear('timestamp', now()->year);
    }

    /**
     * Filter by date range
     */
    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('timestamp', [$startDate, $endDate]);
    }

    /**
     * Search in description, user_name, batch_number, or transaction_id
     */
    public function scopeSearch($query, $searchTerm)
    {
        return $query->where(function($q) use ($searchTerm) {
            $q->where('description', 'ILIKE', "%{$searchTerm}%")
              ->orWhere('user_name', 'ILIKE', "%{$searchTerm}%")
              ->orWhere('user_nik', 'ILIKE', "%{$searchTerm}%")
              ->orWhere('batch_number', 'ILIKE', "%{$searchTerm}%")
              ->orWhere('transaction_id', 'ILIKE', "%{$searchTerm}%");  // ADDED
        });
    }

    
    /**
     * Get formatted timestamp
     */
    public function getFormattedTimestampAttribute()
    {
        return $this->timestamp->timezone('Asia/Jakarta')->format('d M Y, H:i:s');
    }

    /**
     * Get human readable time ago
     */
    public function getTimeAgoAttribute()
    {
        return $this->timestamp->diffForHumans();
    }

    /**
     * Get colored badge class based on action type
     */
    public function getActionColorAttribute()
    {
        $colors = [
            'CREATE' => 'success',
            'UPDATE' => 'primary',
            'DELETE' => 'danger',
            'WEIGHING' => 'warning',
            'APPROVE' => 'success',
            'REJECT' => 'danger',
            'START_SHIFT' => 'info',
            'END_SHIFT' => 'secondary',
            'LOGIN' => 'info',
            'LOGOUT' => 'secondary',
        ];

        return $colors[$this->action_type] ?? 'secondary';
    }

    // ============= HELPER METHODS =============
    
    /**
     * Get all available action types
     */
    public static function getActionTypes()
    {
        return [
            self::ACTION_CREATE,
            self::ACTION_UPDATE,
            self::ACTION_DELETE,
            self::ACTION_WEIGHING,
            self::ACTION_APPROVE,
            self::ACTION_REJECT,
            self::ACTION_LOGIN,
            self::ACTION_LOGOUT,
            self::ACTION_START_SHIFT,
            self::ACTION_END_SHIFT,
        ];
    }

    /**
     * Get changelog statistics
     */
    public static function getStatistics($days = 30)
    {
        return [
            'total' => self::recent($days)->count(),
            'by_action_type' => self::recent($days)
                ->select('action_type', \DB::raw('count(*) as count'))
                ->groupBy('action_type')
                ->pluck('count', 'action_type'),
            'by_user_role' => self::recent($days)
                ->select('user_role', \DB::raw('count(*) as count'))
                ->groupBy('user_role')
                ->pluck('count', 'user_role'),
            'most_active_users' => self::recent($days)
                ->select('user_name', 'user_nik', \DB::raw('count(*) as count'))
                ->groupBy('user_name', 'user_nik')
                ->orderBy('count', 'desc')
                ->limit(10)
                ->get(),
        ];
    }
}