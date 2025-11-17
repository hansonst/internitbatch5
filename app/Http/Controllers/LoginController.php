<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class LoginController extends Controller
{
    /**
     * Login user with NIK only
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function login(Request $request): JsonResponse
{
    try {
        Log::info('Login attempt started', ['nik' => $request->nik]);
        
        // Validate the request
        $validator = Validator::make($request->all(), [
            'nik' => 'required|string|max:7',
        ]);

        if ($validator->fails()) {
            Log::warning('Validation failed', ['errors' => $validator->errors()]);
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        Log::info('Validation passed, searching for user');
        
        // Find user by NIK
        $user = User::where('nik', $request->nik)->first();
        
        Log::info('User search result', [
            'found' => $user ? 'yes' : 'no',
            'user_id' => $user ? $user->id : null
        ]);

        if (!$user) {
            Log::warning('User not found', ['nik' => $request->nik]);
            return response()->json([
                'success' => false,
                'message' => 'NIK not found'
            ], 404);
        }

        Log::info('User found, creating token');

        // ✅ Create token for API authentication
        $token = $user->createToken('auth_token')->plainTextToken;
        
        Log::info('Token created successfully');

        // ✅ Return response with token included
        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'data' => [
                'user' => [
                    'nik' => $user->nik,
                    'first_name' => $user->first_name ?? null,
                    'last_name' => $user->last_name ?? null,
                    'full_name' => $user->full_name ?? null,
                    'email' => $user->email ?? null,
                    'position' => $user->position ?? null,
                    'div' => $user->div ?? null,
                    'dept' => $user->dept ?? null,
                    'inisial' => $user->inisial ?? null,
                    'group' => $user->group ?? null,
                ],
                'token' => $token  // ✅ Token is included here
            ]
        ], 200);

    } catch (\Exception $e) {
        Log::error('Login error occurred', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'nik' => $request->nik ?? 'unknown'
        ]);
        
        return response()->json([
            'success' => false,
            'message' => 'Login failed',
            'error' => $e->getMessage()
        ], 500);
    }
}

    /**
     * Logout user
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function logout(Request $request): JsonResponse
    {
        try {
            // Delete current access token
            $request->user()->currentAccessToken()->delete();

            return response()->json([
                'success' => true,
                'message' => 'Logout successful'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Logout failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get authenticated user profile
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function profile(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            return response()->json([
                'success' => true,
                'data' => [
                    'user' => [
                        'nik' => $user->nik,
                        'first_name' => $user->first_name ?? null,
                        'last_name' => $user->last_name ?? null,
                        'full_name' => $user->full_name ?? null,
                        'email' => $user->email ?? null,
                        'position' => $user->position ?? null,
                        'div' => $user->div ?? null,
                        'dept' => $user->dept ?? null,
                        'inisial' => $user->inisial ?? null,
                        'group' => $user->group ?? null,
                    ]
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get profile',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}