<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuthenticationLog extends Model
{
    protected $connection = 'pgsql_second';
    protected $table = 'authentication_logs';

    protected $fillable = [
        'user_id',
        'users_table_id',
        'user_email',
        'first_name',
        'last_name',
        'full_name',
        'jabatan',
        'department',
        'event_type',
        'ip_address',
        'user_agent',
        'device_type',
        'browser',
        'platform',
        'success',
        'failure_reason',
        'http_status_code',
        'token_name',
        'token_expires_at',
        'tokens_revoked',
        'is_suspicious',
        'country_code',
        'city',
        'metadata'
    ];

    protected $casts = [
        'success' => 'boolean',
        'is_suspicious' => 'boolean',
        'token_expires_at' => 'datetime',
        'metadata' => 'array',
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
     * Check if login attempt seems suspicious
     */
    public static function isSuspiciousActivity($userId, $ipAddress)
    {
        // Check for brute force (5+ failed attempts in 10 minutes)
        $recentFailures = self::where('user_id', $userId)
            ->where('event_type', 'login_failed')
            ->where('created_at', '>=', now()->subMinutes(10))
            ->count();

        if ($recentFailures >= 5) {
            return true;
        }

        // Check for new location (IP not seen in last 30 days)
        $knownIp = self::where('user_id', $userId)
            ->where('ip_address', $ipAddress)
            ->where('success', true)
            ->where('created_at', '>=', now()->subDays(30))
            ->exists();

        return !$knownIp;
    }

    /**
     * Scope for successful events
     */
    public function scopeSuccessful($query)
    {
        return $query->where('success', true);
    }

    /**
     * Scope for failed events
     */
    public function scopeFailed($query)
    {
        return $query->where('success', false);
    }

    /**
     * Scope for suspicious activity
     */
    public function scopeSuspicious($query)
    {
        return $query->where('is_suspicious', true);
    }

    /**
     * Scope for specific event type
     */
    public function scopeEventType($query, $type)
    {
        return $query->where('event_type', $type);
    }
}