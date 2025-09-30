<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ProductionOrderController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\DataTimbanganController;
use App\Http\Controllers\ProductionReportController;

// Health check route
Route::get('health', function () {
    return response()->json([
        'status' => 'OK',
        'timestamp' => now(),
        'service' => 'Production Order API'
    ]);
});

// ADD THIS ROUTE OUTSIDE AUTH MIDDLEWARE - BEFORE THE PROTECTED ROUTES
Route::post('/check-active-shift-with-perbox', [DataTimbanganController::class, 'checkActiveShiftWithPerbox']);

// ADD THESE NEW ROUTES FOR THE FLUTTER APP
Route::get('/data-timbangan-perbox/{data_timbangan_id}', [DataTimbanganController::class, 'getWeightEntries']);

// Authentication routes (public) - FIXED CORS
Route::post('/login', [LoginController::class, 'login'])->name('login');

// Add this for testing in browser (GET request)
Route::get('/login', function () {
    return response()->json([
        'message' => 'Login endpoint is working. Use POST method to login.',
        'required_fields' => ['nik'],
        'example' => [
            'method' => 'POST',
            'url' => url('/api/login'),
            'body' => ['nik' => 'your_nik_here']
        ]
    ]);
});

// ADD MISSING PRO-ORDERS ROUTE (public for dropdown)
Route::get('/pro-orders', [ProductionOrderController::class, 'getProOrders']);

// Protected routes (require authentication)
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [LoginController::class, 'logout']);
    Route::get('/profile', [LoginController::class, 'profile']);
    
    // Production Orders Routes - SINGLE GROUP WITH CORRECT ORDER
    Route::prefix('production-orders')->group(function () {
        Route::get('/', [ProductionOrderController::class, 'index']);
        Route::post('/', [ProductionOrderController::class, 'store']);
        
        // SPECIFIC ROUTES MUST COME BEFORE PARAMETERIZED ROUTES
        Route::get('/unassigned', [ProductionOrderController::class, 'getUnassignedOrders']);
        Route::get('/group/{groupCode}', [ProductionOrderController::class, 'getOrdersByGroup']);
        
        // PARAMETERIZED ROUTES COME LAST
        Route::get('/{batchNumber}', [ProductionOrderController::class, 'show']);
        Route::put('/{batchNumber}', [ProductionOrderController::class, 'update']);
        Route::delete('/{batchNumber}', [ProductionOrderController::class, 'destroy']);
        
        // FIXED: Changed from PATCH to PUT to match Flutter expectations
        Route::put('/{batchNumber}/assign-group', [ProductionOrderController::class, 'assignGroup']);
        Route::patch('/{batchNumber}/remove-group', [ProductionOrderController::class, 'removeGroup']);
        
        // ADD: Start shift route for operators
        Route::post('/{batchNumber}/start-shift', [ProductionOrderController::class, 'startShift']);
    });

    // Data Timbangan Routes - COMBINED WEIGHING SESSION & ENTRY ROUTES
    Route::prefix('data-timbangan')->group(function () {
        // Shift management routes
        Route::post('/start-shift', [DataTimbanganController::class, 'startShift']);
        Route::get('/active-shift', [DataTimbanganController::class, 'getActiveShift']);
        Route::post('/end-shift', [DataTimbanganController::class, 'endShift']);
        Route::get('/active-session', [DataTimbanganController::class, 'getActiveSession']);
        
        // Weighing session routes (combined from SessionController)
        Route::post('/sessions/start', [DataTimbanganController::class, 'startSession']);
        Route::put('/sessions/{id}/close', [DataTimbanganController::class, 'closeSession']);
        Route::get('/sessions/{id}', [DataTimbanganController::class, 'getSession']);
        Route::get('/weighing-sessions/active/{batchNumber}', [DataTimbanganController::class, 'getActiveSessionByBatch']);
        
        // Weighing entry routes (combined from WeighingEntryController)
        Route::post('/weigh-box', [DataTimbanganController::class, 'addWeightEntry']);
        
        // ADD THIS NEW ROUTE FOR MQTT WEIGHT SAVING
        Route::post('/save-weighing-entry', [DataTimbanganController::class, 'saveWeighingEntry']);
        // GET SESSION STATUS FOR BATCHES
        Route::get('/batch-session-status/{batchNumber}', [DataTimbanganController::class, 'checkBatchSessionStatus']);
        Route::get('/weighing-entries/session/{data_timbangan_id}', [DataTimbanganController::class, 'getWeightEntries']);
        Route::delete('/weighing-entries/{boxId}', [DataTimbanganController::class, 'deleteBox']);
        
        // UPDATE ROUTE FOR CATEGORY ONLY
        Route::put('/weighing-entries/{boxId}', [DataTimbanganController::class, 'updateWeightEntry']);
        
        // Batch-specific routes - FIXED METHOD NAMES
        Route::get('/{batchNumber}/check-shift', [DataTimbanganController::class, 'checkShiftStatus']);
        Route::get('/{batchNumber}/current-session', [DataTimbanganController::class, 'getCurrentSession']);
        
        // Additional routes for data timbangan
        Route::get('/', [DataTimbanganController::class, 'index']);
         Route::get('/data-timbangan/current-session', [DataTimbanganController::class, 'getCurrentSessionData']);
        Route::get('/{batchNumber}', [DataTimbanganController::class, 'getByBatch']);
        // Add these routes for MQTT functionality
Route::get('/mqtt/latest-weight', [DataTimbanganController::class, 'getLatestMqttWeight']);
Route::post('/mqtt/save-weight', [DataTimbanganController::class, 'saveMqttWeight']);
        
        // ADD THIS MISSING ROUTE - This is what your Flutter app is calling
        Route::get('/perbox/{data_timbangan_id}', [DataTimbanganController::class, 'getWeightEntries']);
    });

    // Operator Groups Route (ADD THIS - matches Flutter expectation)
    Route::get('/operator-groups', [ProductionOrderController::class, 'getGroups']);

    // Dropdown Data Routes (keep these for backward compatibility)
    Route::prefix('dropdowns')->group(function () {
        Route::get('/materials', [ProductionOrderController::class, 'getMaterials']);
        Route::get('/machines', [ProductionOrderController::class, 'getMachines']);
        Route::get('/groups', [ProductionOrderController::class, 'getGroups']);
    });

    // Production Report Routes
    Route::prefix('production-report')->group(function () {
        Route::get('/', [ProductionReportController::class, 'index']);
        Route::get('/summary', [ProductionReportController::class, 'getProductionReportSummary']);
        Route::get('/filter-options', [ProductionReportController::class, 'getProductionReportFilterOptions']);
    });
    
    Route::put('/data-timbangan-perbox/{boxId}', [DataTimbanganController::class, 'updateWeightEntry']);
});

// User authentication routes
Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});