<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SapActivityLog extends Model
{
    protected $table = 'sap_activity_logs';
    protected $connection = 'pgsql_second';
    protected $fillable = [
        'activity_type',
        'action',
        'user_id',
        'users_table_id',
        'user_email',
        'first_name',
        'last_name',
        'full_name',
        'jabatan',
        'department',
        'ip_address',
        'po_no',
        'item_po',
        'dn_no',
        'material_doc_no',
        'plant',
        'request_payload',
        'response_data',
        'success',
        'status_code',
        'error_message',
        'response_time_ms',
        'sap_endpoint'
    ];

    protected $casts = [
        'request_payload' => 'array',
        'response_data' => 'array',
        'success' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    /**
     * Relationship to User model
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'users_table_id', 'id');
    }

    /**
     * Scope for successful operations
     */
    public function scopeSuccessful($query)
    {
        return $query->where('success', true);
    }

    /**
     * Scope for failed operations
     */
    public function scopeFailed($query)
    {
        return $query->where('success', false);
    }

    /**
     * Scope for specific activity type
     */
    public function scopeActivityType($query, $type)
    {
        return $query->where('activity_type', $type);
    }

    /**
     * Scope for specific PO
     */
    public function scopeForPo($query, $poNo)
    {
        return $query->where('po_no', $poNo);
    }

    /**
     * Scope for specific user
     */
    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Get average response time for activity type
     */
    public static function averageResponseTime($activityType = null)
    {
        $query = self::where('success', true)
            ->whereNotNull('response_time_ms');

        if ($activityType) {
            $query->where('activity_type', $activityType);
        }

        return $query->avg('response_time_ms');
    }
}