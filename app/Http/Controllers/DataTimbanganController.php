<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\DataTimbangan;
use App\Models\ProductionOrder;
use App\Models\DataTimbanganPerbox;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Redis;


class DataTimbanganController extends Controller
{
    /**
     * Start a new shift session
     */public function options()
    {
        return response()->json([], 200);
    }
    public function startShift(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'batch_number' => 'required|string|exists:production_order,batch_number',
                'starting_counter_pro' => 'required|numeric|min:0'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = $request->user();
            $batchNumber = $request->batch_number;
            $startingCounter = $request->starting_counter_pro;

            DB::beginTransaction();

            // Check if user already has an active session
            $existingSession = DataTimbangan::where('nik', $user->nik)
                ->where('session_status', 'open')
                ->whereNull('ending_counter_pro')
                ->first();
                
            if ($existingSession) {
                return response()->json([
                    'success' => false,
                    'message' => 'You already have an active shift session. Please end it first.',
                    'active_session' => [
                        'batch_number' => $existingSession->batch_number,
                        'started_at' => $existingSession->created_at,
                        'data_timbangan_id' => $existingSession->id
                    ]
                ], 409);
            }

            // Check if batch already has an active session
            $batchSession = DataTimbangan::where('batch_number', $batchNumber)
                ->where('session_status', 'open')
                ->whereNull('ending_counter_pro')
                ->first();

            if ($batchSession) {
                return response()->json([
                    'success' => false,
                    'message' => 'This batch already has an active shift session.',
                    'active_operator' => $batchSession->nik
                ], 409);
            }

            // Get production order details
            $productionOrder = ProductionOrder::where('batch_number', $batchNumber)->first();
            if (!$productionOrder) {
                return response()->json([
                    'success' => false,
                    'message' => 'Production order not found'
                ], 404);
            }

            // Create the shift session - this is now the only requirement for digital scales access
            $shiftSession = DataTimbangan::create([
                'batch_number' => $batchNumber,
                'nik' => $user->nik,
                'inisial' => $user->inisial,
                'starting_counter_pro' => $startingCounter,
                'weight_uom' => 'GR',
                'session_status' => 'open',
                'created_at' => now(),
            ]);

            DB::commit();

            Log::info("Shift started successfully", [
                'shift_id' => $shiftSession->id,
                'batch_number' => $batchNumber,
                'nik' => $user->nik,
                'starting_counter' => $startingCounter,
                'timestamp' => now()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Shift started successfully. You can now access digital scales.',
                'data' => [
                    'shift_id' => $shiftSession->id,
                    'data_timbangan_id' => $shiftSession->id,
                    'batch_number' => $batchNumber,
                    'material_desc' => $productionOrder->material_desc,
                    'machine_name' => $productionOrder->machine_name,
                    'starting_counter_pro' => $startingCounter,
                    'session_status' => 'open',
                    'created_at' => $shiftSession->created_at
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error("Failed to start shift", [
                'user_nik' => $request->user()->nik ?? 'unknown',
                'batch_number' => $request->batch_number ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'timestamp' => now()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to start shift: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * End current shift session
     */
    
public function getActiveShift(Request $request)
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated'
                ], 401);
            }
            
            $activeShift = DataTimbangan::where('nik', $user->nik)
                ->where('session_status', 'open')
                ->whereNull('ending_counter_pro')
                ->orderBy('created_at', 'desc')
                ->first();
                
            if ($activeShift) {
                return response()->json([
                    'success' => true,
                    'has_active_shift' => true,
                    'data' => [
                        'id' => $activeShift->id,
                        'batch_number' => $activeShift->batch_number,
                        'material_id' => $activeShift->material_id,
                        'machine_id' => $activeShift->machine_id,
                        'shift_id' => $activeShift->shift_id,
                        'starting_counter_pro' => $activeShift->starting_counter_pro,
                        'created_at' => $activeShift->created_at,
                    ]
                ]);
            }
            
            return response()->json([
                'success' => true,
                'has_active_shift' => false,
                'data' => null
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error getting active shift: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to get active shift: ' . $e->getMessage()
            ], 500);
        }
    }

public function endShift(Request $request)
    {
        try {
            $user = $request->user();
            
            $request->validate([
                'batch_number' => 'required|string',
                'ending_counter_pro' => 'required|numeric|min:0',
            ]);
            
            DB::beginTransaction();
            
            // Find the active shift by batch_number and user NIK
            $shift = DataTimbangan::where('batch_number', $request->batch_number)
                ->where('nik', $user->nik)
                ->where('session_status', 'open')
                ->whereNull('ending_counter_pro')
                ->first();
                
            if (!$shift) {
                return response()->json([
                    'success' => false,
                    'message' => 'Active shift not found'
                ], 404);
            }
            
            // Update shift status and ending counter
            $shift->update([
                'ending_counter_pro' => $request->ending_counter_pro,
                'session_status' => 'closed',
                'ended_at' => now()
            ]);
            
            DB::commit();
            
            Log::info("Shift ended successfully", [
                'shift_id' => $shift->id,
                'batch_number' => $shift->batch_number,
                'nik' => $user->nik,
                'ending_counter' => $request->ending_counter_pro,
                'timestamp' => now()
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Shift ended successfully'
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error ending shift: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to end shift: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get current active session for user
     */
    public function getActiveSession(Request $request)
    {
        try {
            $user = $request->user();
            
            $activeSession = DataTimbangan::where('nik', $user->nik)
                ->where('session_status', 'open')
                ->whereNull('ending_counter_pro')
                ->first();
            
            if (!$activeSession) {
                return response()->json([
                    'success' => true,
                    'message' => 'No active session found',
                    'data' => null
                ], 200);
            }

            return response()->json([
                'success' => true,
                'message' => 'Active session found',
                'data' => [
                    'shift_id' => $activeSession->id,
                    'batch_number' => $activeSession->batch_number,
                    'starting_counter_pro' => $activeSession->starting_counter_pro,
                    'created_at' => $activeSession->created_at
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error("Failed to get active session", [
                'user_nik' => $request->user()->nik ?? 'unknown',
                'error' => $e->getMessage(),
                'timestamp' => now()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to get active session'
            ], 500);
        }
    }
    

    /**
     * Check if shift is started for a specific batch
     */
    public function checkShift(Request $request, $batchNumber)
    {
        try {
            $activeSession = DataTimbangan::where('batch_number', $batchNumber)
                ->whereNull('ending_counter_pro')
                ->first();

            return response()->json([
                'success' => true,
                'shift_started' => $activeSession !== null,
                'data' => $activeSession ? [
                    'nik' => $activeSession->nik,
                    'started_at' => $activeSession->created_at
                ] : null
            ], 200);

        } catch (\Exception $e) {
            Log::error("Failed to check shift status", [
                'batch_number' => $batchNumber,
                'error' => $e->getMessage(),
                'timestamp' => now()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to check shift status'
            ], 500);
        }
    }

    /**
     * Get current session for a specific batch
     */
    public function getCurrentSession(Request $request, $batchNumber)
    {
        try {
            $session = DataTimbangan::where('batch_number', $batchNumber)
                ->whereNull('ending_counter_pro')
                ->first();

            if (!$session) {
                return response()->json([
                    'success' => false,
                    'message' => 'No active session found for this batch'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $session
            ], 200);

        } catch (\Exception $e) {
            Log::error("Failed to get current session", [
                'batch_number' => $batchNumber,
                'error' => $e->getMessage(),
                'timestamp' => now()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to get current session'
            ], 500);
        }
    }
    
/**
 * Start weighing session (POST /sessions/start)
 * This is called after shift is started
 */
public function startSession(Request $request)
{
    $validator = Validator::make($request->all(), [
        'batch_number' => 'required|string|max:255',
        'starting_counter_pro' => 'required|integer|min:0',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $validator->errors()
        ], 422);
    }

    try {
        $batchNumber = $request->batch_number;
        $startingCounterPro = $request->starting_counter_pro;

        // Check if there's already an active session for this batch
        $existingSession = DataTimbangan::where('batch_number', $batchNumber)
            ->where('session_status', 'open')
            ->first();

        if ($existingSession) {
            return response()->json([
                'success' => true,
                'data' => [
                    'data_timbangan_id' => $existingSession->id,
                    'status' => 'open'
                ]
            ], 200);
        }

        // If no existing session, this means shift wasn't started properly
        return response()->json([
            'success' => false,
            'message' => 'No active shift session found. Please start shift first.'
        ], 404);

    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Failed to start session: ' . $e->getMessage()
        ], 500);
    }
}

/**
 * Close weighing session (PUT /sessions/{id}/close)
 */
public function closeSession(Request $request, $id)
{
    $validator = Validator::make($request->all(), [
        'ending_counter_pro' => 'required|integer|min:0',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $validator->errors()
        ], 422);
    }

    try {
        $session = DataTimbangan::find($id);

        if (!$session) {
            return response()->json([
                'success' => false,
                'message' => 'Session not found'
            ], 404);
        }

        if ($session->session_status !== 'open') {
            return response()->json([
                'success' => false,
                'message' => 'Session is already closed'
            ], 409);
        }

        $session->update([
            'ending_counter_pro' => $request->ending_counter_pro,
            'ended_at' => now(),
            'session_status' => 'closed',
            'updated_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Session closed successfully'
        ], 200);

    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Failed to close session: ' . $e->getMessage()
        ], 500);
    }
}

/**
 * Get session details (GET /sessions/{id})
 */
public function getSession($id)
{
    try {
        $session = DataTimbangan::find($id);

        if (!$session) {
            return response()->json([
                'success' => false,
                'message' => 'Session not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $session
        ], 200);

    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Failed to get session: ' . $e->getMessage()
        ], 500);
    }
}


/**
 * Add weighing entry (POST /weigh-box)
 */
public function addWeightEntry(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'data_timbangan_id' => 'required|integer|exists:data_timbangan,id',
            'weight_perbox' => 'required|numeric|min:0',
            'category' => 'required|string|in:Runner,Sapuan,Purging,Defect,Finished Good',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $dataTimbanganId = $request->data_timbangan_id;
            $weightPerBox = $request->weight_perbox;
            $category = $request->category;

            // Check if session exists and is open
            $session = DataTimbangan::find($dataTimbanganId);
            if (!$session) {
                return response()->json([
                    'success' => false,
                    'message' => 'Active shift session not found. Please start a shift first.'
                ], 404);
            }

            if ($session->session_status !== 'open') {
                return response()->json([
                    'success' => false,
                    'message' => 'Shift session is closed. Cannot add more entries.'
                ], 409);
            }

            // Verify this is the user's active session
            $user = $request->user();
            if ($session->nik !== $user->nik) {
                return response()->json([
                    'success' => false,
                    'message' => 'This shift session does not belong to you.'
                ], 403);
            }

            // Get next box number for this session
            $lastEntry = DataTimbanganPerbox::where('data_timbangan_id', $dataTimbanganId)
                ->orderBy('box_no', 'desc')
                ->first();
            
            $nextBoxNo = $lastEntry ? $lastEntry->box_no + 1 : 1;

            // Create weighing entry
            $entry = DataTimbanganPerbox::create([
                'data_timbangan_id' => $dataTimbanganId,
                'box_no' => $nextBoxNo,
                'weight_perbox' => $weightPerBox,
                'category' => $category,
                'weighed_at' => now(),
            ]);

            // Update session totals
            $this->updateSessionTotals($dataTimbanganId);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Weight entry added successfully',
                'data' => [
                    'id' => $entry->id,
                    'data_timbangan_id' => $entry->data_timbangan_id,
                    'box_no' => $entry->box_no,
                    'weight_perbox' => $entry->weight_perbox,
                    'category' => $entry->category,
                    'weighed_at' => $entry->weighed_at
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to add weight entry', [
                'data_timbangan_id' => $request->data_timbangan_id ?? 'unknown',
                'user_nik' => $request->user()->nik ?? 'unknown',
                'error' => $e->getMessage(),
                'timestamp' => now()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to add weight entry: ' . $e->getMessage()
            ], 500);
        }
    }

/**
 * Get weighing entries for a session (GET /weighing-entries/session/{data_timbangan_id})
 */
public function getWeightEntries($dataTimbanganId)
{
    try {
        $entries = DataTimbanganPerbox::where('data_timbangan_id', $dataTimbanganId)
            ->orderBy('box_no', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $entries
        ], 200);

    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Failed to get weight entries: ' . $e->getMessage()
        ], 500);
    }
}

/**
 * Delete weighing entry (DELETE /weighing-entries/{boxId})
 */
public function deleteBox($boxId)
{
    try {
        DB::beginTransaction();

        $entry = DataTimbanganPerbox::find($boxId);

        if (!$entry) {
            return response()->json([
                'success' => false,
                'message' => 'Entry not found'
            ], 404);
        }

        $dataTimbanganId = $entry->data_timbangan_id;

        // Check if session is still open
        $session = DataTimbangan::find($dataTimbanganId);
        if ($session && $session->session_status !== 'open') {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete entry from closed session'
            ], 409);
        }

        $entry->delete();

        // Update session totals
        $this->updateSessionTotals($dataTimbanganId);

        DB::commit();

        return response()->json([
            'success' => true,
            'message' => 'Entry deleted successfully'
        ], 200);

    } catch (\Exception $e) {
        DB::rollBack();
        return response()->json([
            'success' => false,
            'message' => 'Failed to delete entry: ' . $e->getMessage()
        ], 500);
    }
}

/**
 * Helper method to update session totals
 */

/**
 * Enhanced helper method to update session totals with category breakdown
 */
private function updateSessionTotals($dataTimbanganId)
    {
        $entries = DataTimbanganPerbox::where('data_timbangan_id', $dataTimbanganId)->get();
        
        $totalWeightAll = $entries->sum('weight_perbox');
        $totalQtyAll = $entries->count();
        
        $categories = ['Runner', 'Sapuan', 'Purging', 'Defect', 'Finished Good'];
        $updateData = [
            'total_weight_all' => $totalWeightAll,
            'updated_at' => now(),
        ];
        
        foreach ($categories as $category) {
            $categoryEntries = $entries->where('category', $category);
            $categoryKey = strtolower(str_replace(' ', '_', $category));
            
            if ($category === 'Finished Good') {
                $categoryKey = 'fg';
            }
            
            $updateData["total_weight_{$categoryKey}"] = $categoryEntries->sum('weight_perbox');
            $updateData["total_qty_{$categoryKey}"] = $categoryEntries->count();
        }
        
        DataTimbangan::where('id', $dataTimbanganId)->update($updateData);
    }

/**
 * Update weighing entry (PUT /weighing-entries/{boxId})
 */
public function updateWeightEntry(Request $request, $boxId)
{
    $validator = Validator::make($request->all(), [
        'weight_perbox' => 'sometimes|numeric|min:0',
        'category' => 'sometimes|string|in:Runner,Sapuan,Purging,Defect,Finished Good',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $validator->errors()
        ], 422);
    }

    try {
        DB::beginTransaction();

        $entry = DataTimbanganPerbox::find($boxId);

        if (!$entry) {
            return response()->json([
                'success' => false,
                'message' => 'Entry not found'
            ], 404);
        }

        $dataTimbanganId = $entry->data_timbangan_id;

        // Check if session is still open
        $session = DataTimbangan::find($dataTimbanganId);
        if ($session && $session->session_status !== 'open') {
            return response()->json([
                'success' => false,
                'message' => 'Cannot update entry from closed session'
            ], 409);
        }

        // Update only provided fields
        $updateData = [];
        if ($request->has('weight_perbox')) {
            $updateData['weight_perbox'] = $request->weight_perbox;
        }
        if ($request->has('category')) {
            $updateData['category'] = $request->category;
        }

        $entry->update($updateData);

        // Update session totals after modification
        $this->updateSessionTotals($dataTimbanganId);

        DB::commit();

        return response()->json([
            'success' => true,
            'message' => 'Entry updated successfully',
            'data' => [
                'id' => $entry->id,
                'data_timbangan_id' => $entry->data_timbangan_id,
                'box_no' => $entry->box_no,
                'weight_perbox' => $entry->weight_perbox,
                'category' => $entry->category,
                'weighed_at' => $entry->weighed_at,
            ]
        ], 200);

    } catch (\Exception $e) {
        DB::rollBack();
        return response()->json([
            'success' => false,
            'message' => 'Failed to update entry: ' . $e->getMessage()
        ], 500);
    }
}

/**
 * Check active shift with perbox data for a specific user
 * This is the method your Flutter app is calling
 */
public function checkActiveShiftWithPerbox(Request $request) // <-- This accepts the request object
{
    $nik = $request->input('nik');
        try {
            // Find active shift for this user with production order details
            $activeShift = DataTimbangan::select([
                'data_timbangan.*',
                'production_order.material_desc',
                'production_order.machine_name'
            ])
                ->leftJoin('production_order', 'data_timbangan.batch_number', '=', 'production_order.batch_number')
                ->where('data_timbangan.nik', $nik)
                ->where('data_timbangan.session_status', 'open')
                ->whereNull('data_timbangan.ending_counter_pro')
                ->first();
                
            if (!$activeShift) {
                return response()->json([
                    'success' => true,
                    'has_active_shift' => false,
                    'message' => 'No active shift found. Please start a shift first.',
                    'data_timbangan_id' => null,
                    'batch_number' => null,
                    'material_desc' => null,
                    'machine_name' => null
                ], 200);
            }
            
            // Get perbox entries if any (optional - user can start weighing even without existing entries)
            $perboxEntries = DataTimbanganPerbox::where('data_timbangan_id', $activeShift->id)
                ->orderBy('box_no', 'desc')
                ->get();
                
            $lastBoxNo = $perboxEntries->isNotEmpty() ? $perboxEntries->first()->box_no : 0;
            
            return response()->json([
                'success' => true,
                'has_active_shift' => true,
                'message' => 'Active shift found. Digital scales ready.',
                'data_timbangan_id' => $activeShift->id,
                'batch_number' => $activeShift->batch_number,
                'material_desc' => $activeShift->material_desc ?? 'Unknown Material',
                'machine_name' => $activeShift->machine_name ?? 'Unknown Machine',
                'shift_data' => [
                    'shift_id' => $activeShift->id,
                    'nik' => $activeShift->nik,
                    'inisial' => $activeShift->inisial,
                    'starting_counter_pro' => $activeShift->starting_counter_pro,
                    'session_status' => $activeShift->session_status,
                    'created_at' => $activeShift->created_at,
                    'total_entries' => $perboxEntries->count(),
                    'last_box_no' => $lastBoxNo,
                    'next_box_no' => $lastBoxNo + 1
                ]
            ], 200);
            
        } catch (\Exception $e) {
            Log::error('Error checking active shift with perbox data', [
                'nik' => $nik,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'timestamp' => now()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to check active shift: ' . $e->getMessage(),
                'data_timbangan_id' => null,
                'batch_number' => null,
                'material_desc' => null,
                'machine_name' => null
            ], 500);
        }
    }
    public function saveWeighingEntry(Request $request)
{
    $validator = Validator::make($request->all(), [
        'data_timbangan_id' => 'required|integer|exists:data_timbangan,id',
        'box_no' => 'sometimes|integer|min:1',
        'weight_perbox' => 'required|numeric|min:0',
        'category' => 'nullable|string|in:Runner,Sapuan,Purging,Defect,Finished Good',
        'timbangan_name' => 'sometimes|string', // Add this validation
        'weighed_at' => 'sometimes|date'
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $validator->errors()
        ], 422);
    }

    try {
        DB::beginTransaction();

        $dataTimbanganId = $request->data_timbangan_id;
        $weightPerBox = $request->weight_perbox;
        $category = $request->category;
        $timbanganName = $request->timbangan_name; // Get timbangan_name
        $weighedAt = $request->weighed_at ?? now();

        // Check if session exists and is open
        $session = DataTimbangan::find($dataTimbanganId);
        if (!$session) {
            return response()->json([
                'success' => false,
                'message' => 'Active shift session not found. Please start a shift first.'
            ], 404);
        }

        if ($session->session_status !== 'open') {
            return response()->json([
                'success' => false,
                'message' => 'Shift session is closed. Cannot add more entries.'
            ], 409);
        }

        // Get next box number for this session
        if ($request->has('box_no')) {
            $boxNo = $request->box_no;
            
            $existingEntry = DataTimbanganPerbox::where('data_timbangan_id', $dataTimbanganId)
                ->where('box_no', $boxNo)
                ->first();
                
            if ($existingEntry) {
                return response()->json([
                    'success' => false,
                    'message' => "Box number {$boxNo} already exists for this session."
                ], 409);
            }
        } else {
            $lastEntry = DataTimbanganPerbox::where('data_timbangan_id', $dataTimbanganId)
                ->orderBy('box_no', 'desc')
                ->first();
            
            $boxNo = $lastEntry ? $lastEntry->box_no + 1 : 1;
        }

        // Create weighing entry with timbangan_name
        $entry = DataTimbanganPerbox::create([
            'data_timbangan_id' => $dataTimbanganId,
            'box_no' => $boxNo,
            'weight_perbox' => $weightPerBox,
            'category' => $category,
            'timbangan_name' => $timbanganName, // Include timbangan_name
            'weighed_at' => $weighedAt,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        // Update session totals
        $this->updateSessionTotals($dataTimbanganId);

        DB::commit();

        Log::info('MQTT Weight entry saved successfully', [
            'entry_id' => $entry->id,
            'data_timbangan_id' => $dataTimbanganId,
            'box_no' => $boxNo,
            'weight_perbox' => $weightPerBox,
            'category' => $category,
            'timbangan_name' => $timbanganName,
            'session_batch' => $session->batch_number,
            'timestamp' => now()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Weight entry saved successfully',
            'data' => [
                'id' => $entry->id,
                'data_timbangan_id' => $entry->data_timbangan_id,
                'box_no' => $entry->box_no,
                'weight_perbox' => $entry->weight_perbox,
                'category' => $entry->category,
                'timbangan_name' => $entry->timbangan_name,
                'weighed_at' => $entry->weighed_at,
                'created_at' => $entry->created_at
            ]
        ], 201);

    } catch (\Exception $e) {
        DB::rollBack();
        Log::error('Failed to save MQTT weight entry', [
            'data_timbangan_id' => $request->data_timbangan_id ?? 'unknown',
            'weight_perbox' => $request->weight_perbox ?? 'unknown',
            'timbangan_name' => $request->timbangan_name ?? 'unknown',
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'timestamp' => now()
        ]);
        
        return response()->json([
            'success' => false,
            'message' => 'Failed to save weight entry: ' . $e->getMessage()
        ], 500);
    }
}
public function getLatestMqttWeight(Request $request)
{
    try {
        $selectedScale = $request->query('scale', 'TBG01'); // Default to TBG01 if not specified
        
        // Method 1: If you're storing MQTT data in Redis with scale-specific keys
        $redisKey = "mqtt:weight:{$selectedScale}";
        $latestWeight = Redis::get($redisKey);
        
        if ($latestWeight) {
            $weightData = json_decode($latestWeight, true);
            
            // Ensure the response includes the scale name
            $weightData['timbangan_name'] = $selectedScale;
            
            return response()->json($weightData);
        }
        
        // Method 2: If you're storing in database table (create this table if needed)
        /*
        $mqttData = DB::table('mqtt_weights')
            ->where('timbangan_name', $selectedScale)
            ->orderBy('created_at', 'desc')
            ->first();
            
        if ($mqttData) {
            return response()->json([
                'timbangan_name' => $mqttData->timbangan_name,
                'weight' => $mqttData->weight,
                'unit' => 'g',
                'timestamp' => $mqttData->created_at,
                'stable' => $mqttData->stable ?? true
            ]);
        }
        */
        
        // No weight data found for selected scale
        return response()->json([
            'message' => "No current weight data for {$selectedScale}"
        ], 404);
        
    } catch (\Exception $e) {
        Log::error('Failed to fetch MQTT weight data', [
            'scale' => $request->query('scale'),
            'error' => $e->getMessage(),
            'timestamp' => now()
        ]);
        
        return response()->json([
            'error' => 'Failed to fetch weight data'
        ], 500);
    }
}
public function saveMqttWeight(Request $request)
{
    $validator = Validator::make($request->all(), [
        'timbangan_name' => 'required|string',
        'weight_kg' => 'required|numeric', // Weight in kg from MQTT
        'stable' => 'sometimes|boolean'
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'errors' => $validator->errors()
        ], 422);
    }

    try {
        $timbanganName = strtoupper($request->timbangan_name); // Ensure uppercase
        $weightKg = (float) $request->weight_kg; // Weight from MQTT in kg
        $stable = $request->stable ?? true;
        
        // Convert kg to grams with 5 decimal precision
        $weightGrams = round($weightKg * 1000, 5);
        
        $weightData = [
            'timbangan_name' => $timbanganName,
            'weight' => $weightGrams, // Weight in grams
            'weight_kg' => $weightKg, // Original weight in kg
            'unit' => 'g',
            'timestamp' => now()->toISOString(),
            'stable' => $stable
        ];
        
        // Store in Redis with scale-specific key (expires after 30 seconds)
        $redisKey = "mqtt:weight:{$timbanganName}";
        Redis::setex($redisKey, 30, json_encode($weightData));
        
        // Optional: Also store in database for history
        /*
        DB::table('mqtt_weights')->insert([
            'timbangan_name' => $timbanganName,
            'weight_kg' => $weightKg,
            'weight_grams' => $weightGrams,
            'unit' => 'g',
            'stable' => $stable,
            'created_at' => now(),
            'updated_at' => now()
        ]);
        */
        
        Log::info('MQTT weight data saved', [
            'scale' => $timbanganName,
            'weight_kg' => $weightKg,
            'weight_grams' => $weightGrams,
            'timestamp' => now()
        ]);
        
        return response()->json([
            'success' => true,
            'message' => 'MQTT weight data saved successfully',
            'data' => [
                'timbangan_name' => $timbanganName,
                'weight_kg' => $weightKg,
                'weight_grams' => $weightGrams
            ]
        ]);
        
    } catch (\Exception $e) {
        Log::error('Failed to save MQTT weight data', [
            'data' => $request->all(),
            'error' => $e->getMessage(),
            'timestamp' => now()
        ]);
        
        return response()->json([
            'success' => false,
            'message' => 'Failed to save MQTT weight data'
        ], 500);
    }
}
public function checkBatchSessionStatus($batchNumber)
{
    try {
        // Validate batch number
        if (empty($batchNumber)) {
            return response()->json([
                'success' => false,
                'message' => 'Batch number is required',
            ], 400);
        }

        // Check if batch has active session using the model method
        $hasActiveSession = DataTimbangan::batchHasActiveSession($batchNumber);
        
        $sessionDetails = null;
        if ($hasActiveSession) {
            // Get the active session details (optional - for additional info)
            $activeSession = DataTimbangan::where('batch_number', $batchNumber)
                ->where('session_status', 'open')
                ->whereNull('ending_counter_pro')
                ->with(['user:nik,first_name,last_name,inisial']) // Get user info
                ->first();
            
            if ($activeSession) {
                $sessionDetails = [
                    'nik' => $activeSession->nik,
                    'user_name' => $activeSession->user ? $activeSession->user->full_name : null, // Use accessor
                    'inisial' => $activeSession->inisial,
                    'started_at' => $activeSession->created_at,
                    'starting_counter_pro' => $activeSession->starting_counter_pro,
                ];
            }
        }

        return response()->json([
            'success' => true,
            'batch_number' => $batchNumber,
            'has_active_session' => $hasActiveSession,
            'session_status' => $hasActiveSession ? 'open' : 'closed',
            'session_details' => $sessionDetails,
            'message' => $hasActiveSession 
                ? 'Batch has an active session' 
                : 'Batch is available for new session'
        ], 200);

    } catch (\Exception $e) {
        \Log::error('Error checking batch session status', [
            'batch_number' => $batchNumber,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Failed to check batch session status',
            'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
        ], 500);
    }
}

public function getCurrentSessionData(Request $request)
    {
        try {
            // Validate input
            $request->validate([
                'batch_number' => 'required|string',
                'nik' => 'required|string',
            ]);

            $batchNumber = $request->query('batch_number');
            $nik = $request->query('nik');

            // Log the request for debugging
            Log::info('Fetching current session data', [
                'batch_number' => $batchNumber,
                'nik' => $nik
            ]);

            // Query the data_timbangan table for the current open session
            $session = DB::table('data_timbangan')
                ->where('batch_number', $batchNumber)
                ->where('nik', $nik)
                ->where('session_status', 'open')
                ->orderBy('created_at', 'desc') // Get the most recent if multiple exist
                ->first();

            if (!$session) {
                return response()->json([
                    'success' => false,
                    'message' => 'No active session found for this batch and user',
                    'data' => null
                ], 404);
            }

            // Convert stdClass to array for easier manipulation
            $sessionData = (array) $session;

            // Ensure numeric fields are properly formatted
            $sessionData['total_weight_all'] = floatval($sessionData['total_weight_all'] ?? 0);
            $sessionData['total_weight_runner'] = floatval($sessionData['total_weight_runner'] ?? 0);
            $sessionData['total_weight_sapuan'] = floatval($sessionData['total_weight_sapuan'] ?? 0);
            $sessionData['total_weight_purging'] = floatval($sessionData['total_weight_purging'] ?? 0);
            $sessionData['total_weight_defect'] = floatval($sessionData['total_weight_defect'] ?? 0);
            $sessionData['total_weight_fg'] = floatval($sessionData['total_weight_fg'] ?? 0);
            $sessionData['total_qty_runner'] = floatval($sessionData['total_qty_runner'] ?? 0);
            $sessionData['total_qty_sapuan'] = floatval($sessionData['total_qty_sapuan'] ?? 0);
            $sessionData['total_qty_purging'] = floatval($sessionData['total_qty_purging'] ?? 0);
            $sessionData['total_qty_defect'] = floatval($sessionData['total_qty_defect'] ?? 0);
            $sessionData['total_qty_fg'] = floatval($sessionData['total_qty_fg'] ?? 0);
            $sessionData['starting_counter_pro'] = intval($sessionData['starting_counter_pro'] ?? 0);
            $sessionData['ending_counter_pro'] = isset($sessionData['ending_counter_pro']) ? intval($sessionData['ending_counter_pro']) : null;

            Log::info('Session data found', ['session_id' => $session->id]);

            return response()->json([
                'success' => true,
                'message' => 'Current session data retrieved successfully',
                'data' => $sessionData
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Validation error in getCurrentSessionData', [
                'errors' => $e->errors()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            Log::error('Error fetching current session data', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch session data',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}