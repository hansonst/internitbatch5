<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\UserSap;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use App\Models\AuthenticationLog;
use Jenssegers\Agent\Agent;

class SapLoginController extends Controller
{
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
   /**
     * Handle SAP user login
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function login(Request $request)
    {
        Log::info('SAP Login attempt', [
            'user_id' => $request->user_id,
            'ip' => $request->ip()
        ]);

        // Validate input
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|string',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
    $this->logAuthEvent('login_attempt', $request->user_id ?? 'unknown', false, $request, null, 'validation_error');
    
    Log::warning('SAP Login validation failed', [
        'errors' => $validator->errors()
    ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Find user by user_id
            $user = UserSap::where('user_id', $request->user_id)->first();

           if (!$user) {
    $this->logAuthEvent('login_failed', $request->user_id, false, $request, null, 'user_not_found');
    
    Log::warning('SAP Login failed - User not found', [
        'user_id' => $request->user_id
    ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid credentials'
                ], 401);
            }

            if (!$user->isActive()) {
    $this->logAuthEvent('login_failed', $request->user_id, false, $request, $user, 'user_inactive');
    
    Log::warning('SAP Login failed - User inactive', [
        'user_id' => $request->user_id
    ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'User account is not active'
                ], 403);
            }

if ($user->password !== $request->password) {
    $this->logAuthEvent('login_failed', $request->user_id, false, $request, $user, 'invalid_password');
    
    Log::warning('SAP Login failed - Invalid password', [
        'user_id' => $request->user_id
    ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid credentials'
                ], 401);
            }

            // Delete old tokens (optional - for security)
            // $user->tokens()->delete();

            // Create Sanctum token for SAP user
$token = $user->createToken('sap-auth-token', ['*'], now()->addDays(30))->plainTextToken;


$this->logAuthEvent('login_success', $user->user_id, true, $request, $user);

Log::info('SAP Login successful', [
    'user_id' => $user->user_id,
    'department' => $user->department
]);

            return response()->json([
                'success' => true,
                'message' => 'Login successful',
                'data' => [
                    'user' => [
                        'user_id' => $user->user_id,
                        'full_name' => $user->full_name,
                        'first_name' => $user->first_name,
                        'last_name' => $user->last_name,
                        'jabatan' => $user->jabatan,
                        'department' => $user->department,
                        'email' => $user->email,
                        'status' => $user->status,
                    ],
                    'token' => $token,
                    'token_type' => 'Bearer'
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error('SAP Login exception', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Login failed',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    public function loginWithRfid(Request $request)
{
    Log::info('SAP RFID Login attempt', [
        'id_card' => $request->id_card,
        'ip' => $request->ip()
    ]);

    // Validate input
    $validator = Validator::make($request->all(), [
        'id_card' => 'required|string',
    ]);

    if ($validator->fails()) {
        $this->logAuthEvent('login_attempt', $request->id_card ?? 'unknown', false, $request, null, 'validation_error');
        
        return response()->json([
            'success' => false,
            'message' => 'Validation error',
            'errors' => $validator->errors()
        ], 422);
    }

    try {
        // Find user by id_card (RFID)
        $user = UserSap::where('id_card', $request->id_card)->first();

        if (!$user) {
            $this->logAuthEvent('login_failed', $request->id_card, false, $request, null, 'rfid_not_found');
            
            return response()->json([
                'success' => false,
                'message' => 'RFID card not registered'
            ], 401);
        }

        if (!$user->isActive()) {
            $this->logAuthEvent('login_failed', $user->user_id, false, $request, $user, 'user_inactive');
            
            return response()->json([
                'success' => false,
                'message' => 'User account is not active'
            ], 403);
        }

        // Create token
        $token = $user->createToken('sap-rfid-auth-token', ['*'], now()->addDays(30))->plainTextToken;

        $this->logAuthEvent('login_success_rfid', $user->user_id, true, $request, $user);

        Log::info('SAP RFID Login successful', [
            'user_id' => $user->user_id,
            'department' => $user->department
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'data' => [
                'user' => [
                    'user_id' => $user->user_id,
                    'full_name' => $user->full_name,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'jabatan' => $user->jabatan,
                    'department' => $user->department,
                    'email' => $user->email,
                    'status' => $user->status,
                ],
                'token' => $token,
                'token_type' => 'Bearer'
            ]
        ], 200);

    } catch (\Exception $e) {
        Log::error('SAP RFID Login exception', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        
        return response()->json([
            'success' => false,
            'message' => 'Login failed',
            'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
        ], 500);
    }
}

    /**
     * Handle SAP user logout
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function logout(Request $request)
    {
        try {
            $userId = $request->user()->user_id;
            $user = $request->user();
            // Revoke the current token
            $request->user()->currentAccessToken()->delete();
            $this->logAuthEvent('logout', $user->user_id, true, $request, $user);
            Log::info('SAP Logout successful', [
                'user_id' => $userId
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Logout successful'
            ], 200);

        } catch (\Exception $e) {
            Log::error('SAP Logout exception', [
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Logout failed',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Get SAP user profile
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function profile(Request $request)
    {
        try {
            $user = $request->user();

            return response()->json([
                'success' => true,
                'data' => [
                    'user_id' => $user->user_id,
                    'full_name' => $user->full_name,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'jabatan' => $user->jabatan,
                    'department' => $user->department,
                    'email' => $user->email,
                    'status' => $user->status,
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error('SAP Get profile exception', [
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to get profile',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Revoke all tokens for SAP user
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function logoutAll(Request $request)
    {
        try {
            $userId = $request->user()->user_id;
            $user = $request->user();
            // Revoke all tokens for the user
            $tokenCount = $request->user()->tokens()->count();
            $request->user()->tokens()->delete();
            $this->logAuthEvent('logout_all', $user->user_id, true, $request, $user, null, $tokenCount);
            Log::info('SAP Logout all sessions successful', [
                'user_id' => $userId,
                'tokens_revoked' => $tokenCount
            ]);

            return response()->json([
                'success' => true,
                'message' => 'All sessions logged out successfully',
                'tokens_revoked' => $tokenCount
            ], 200);

        } catch (\Exception $e) {
            Log::error('SAP Logout all sessions exception', [
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Logout all sessions failed',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Check if SAP user is authenticated
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function checkAuth(Request $request)
    {
        try {
            $user = $request->user();
            
            return response()->json([
                'success' => true,
                'authenticated' => true,
                'user' => [
                    'user_id' => $user->user_id,
                    'full_name' => $user->full_name,
                    'department' => $user->department,
                    'jabatan' => $user->jabatan,
                    'email' => $user->email
                ]
            ], 200);
            
        } catch (\Exception $e) {
            Log::error('SAP Check auth exception', [
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'authenticated' => false,
                'message' => 'Authentication check failed'
            ], 401);
        }
    }

    /**
     * Refresh token - generates new token and revokes old one
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function refreshToken(Request $request)
    {
        try {
            $user = $request->user();
            
            // Revoke current token
            $request->user()->currentAccessToken()->delete();
            
            // Create new token
            $token = $user->createToken('sap-auth-token', ['*'], now()->addDays(30))->plainTextToken;
            $this->logAuthEvent('token_refresh', $user->user_id, true, $request, $user);
            Log::info('SAP Token refreshed', [
                'user_id' => $user->user_id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Token refreshed successfully',
                'data' => [
                    'token' => $token,
                    'token_type' => 'Bearer'
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error('SAP Token refresh exception', [
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Token refresh failed',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }
}