<?php

namespace App\Traits;

use App\Models\AuthenticationLog;
use Jenssegers\Agent\Agent;

trait LogsAuthentication
{
    /**
     * Log authentication event
     */
    protected function logAuthEvent(
        $eventType,
        $userId,
        $success,
        $request,
        $user = null,
        $failureReason = null,
        $tokensRevoked = null
    ) {
        try {
            $agent = new Agent();
            $agent->setUserAgent($request->userAgent());

            $isSuspicious = false;
            if ($eventType === 'login_failed' || ($eventType === 'login_success' && $success)) {
                $isSuspicious = AuthenticationLog::isSuspiciousActivity(
                    $userId,
                    $request->ip()
                );
            }

            return AuthenticationLog::create([
                'user_id' => $userId,
                'users_table_id' => $user ? $user->id : null,
                'user_email' => $user ? $user->email : null,
                'first_name' => $user ? $user->first_name : null,
                'last_name' => $user ? $user->last_name : null,
                'full_name' => $user ? $user->full_name : null,
                'jabatan' => $user ? $user->jabatan : null,
                'department' => $user ? $user->department : null,
                'event_type' => $eventType,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'device_type' => $agent->isDesktop() ? 'desktop' : ($agent->isMobile() ? 'mobile' : 'tablet'),
                'browser' => $agent->browser(),
                'platform' => $agent->platform(),
                'success' => $success,
                'failure_reason' => $failureReason,
                'is_suspicious' => $isSuspicious,
                'tokens_revoked' => $tokensRevoked
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to log authentication event', [
                'error' => $e->getMessage(),
                'user_id' => $userId,
                'event_type' => $eventType
            ]);
            return null;
        }
    }
}