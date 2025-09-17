<?php

namespace App\Http\Controllers;

use App\Models\ProductionReport;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use App\Models\Shift;
use App\Models\ProductionOrder;
use App\Models\DataTimbangan;
use App\Models\Material;
use App\Models\Machine;
use Illuminate\Support\Facades\DB;



class ProductionReportController extends Controller
{
    /**
     * Get production report data with filtering and pagination
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // Validate request parameters
            $validated = $request->validate([
                'material_code' => 'nullable|string|max:50',
                'shift_id' => 'nullable|integer|min:1|max:3',
                'batch_number' => 'nullable|string|max:100',
                'start_date' => 'nullable|date',
                'end_date' => 'nullable|date|after_or_equal:start_date',
                'date' => 'nullable|date',
                'per_page' => 'nullable|integer|min:1|max:100',
                'page' => 'nullable|integer|min:1',
                'sort_by' => 'nullable|string|in:actual_time,batch_number,material_code,shift_id,total_qty',
                'sort_order' => 'nullable|string|in:asc,desc'
            ]);

            // Build query
            $query = ProductionReport::query();

            // Apply filters
            // Add these filter checks:
if (!empty($validated['machine_name'])) {
    $query->where('machine_name', 'like', "%{$validated['machine_name']}%");
}

if (!empty($validated['no_pro'])) {
    $query->where('no_pro', 'like', "%{$validated['no_pro']}%");
}

// Update date filtering to use single date:
if (!empty($validated['date'])) {
    $query->whereDate('actual_time', $validated['date']);
}
            if (!empty($validated['material_code'])) {
                $query->byMaterialCode($validated['material_code']);
            }

            if (!empty($validated['shift_id'])) {
                $query->byShift($validated['shift_id']);
            }

            if (!empty($validated['batch_number'])) {
                $query->byBatchNumber($validated['batch_number']);
            }

            // Handle date filtering
            if (!empty($validated['start_date']) && !empty($validated['end_date'])) {
                $query->byDateRange($validated['start_date'], $validated['end_date']);
            } elseif (!empty($validated['date'])) {
                $query->byDate($validated['date']);
            }

            // Apply sorting
            $sortBy = $validated['sort_by'] ?? 'actual_time';
            $sortOrder = $validated['sort_order'] ?? 'desc';
            $query->orderBy($sortBy, $sortOrder);

            // Set pagination parameters
            $perPage = $validated['per_page'] ?? 15;
            $page = $validated['page'] ?? 1;

            // Execute query with pagination
            $results = $query->paginate($perPage, ['*'], 'page', $page);

            // Format response
            return response()->json([
                'success' => true,
                'data' => $results->items(),
                'pagination' => [
                    'current_page' => $results->currentPage(),
                    'total_pages' => $results->lastPage(),
                    'per_page' => $results->perPage(),
                    'total_records' => $results->total(),
                    'from' => $results->firstItem(),
                    'to' => $results->lastItem(),
                    'has_next_page' => $results->hasMorePages(),
                    'has_previous_page' => $results->currentPage() > 1
                ],
                'filters_applied' => array_filter($validated, function($value) {
                    return !is_null($value) && $value !== '';
                }),
                'timestamp' => now()
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while fetching production report data',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
        
    }

    

    
// Add this method to your ProductionOrderController class
public function getProductionReportSummary(Request $request)
{
    try {
        // Get filter parameters
        $materialCode = $request->get('material_code');
        $shiftId = $request->get('shift_id');
        $batchNumber = $request->get('batch_number');
        $startDate = $request->get('start_date');
        $endDate = $request->get('end_date');

        // Build the query
        $query = ProductionOrder::query();

        // Apply filters
        if ($materialCode) {
            $query->whereHas('material', function($q) use ($materialCode) {
                $q->where('material_code', 'like', "%{$materialCode}%");
            });
        }

        if ($shiftId) {
            $query->where('shift_id', $shiftId);
        }

        if ($batchNumber) {
            $query->where('batch_number', 'like', "%{$batchNumber}%");
        }

        if ($startDate) {
            $query->whereDate('manufacturing_date', '>=', $startDate);
        }

        if ($endDate) {
            $query->whereDate('manufacturing_date', '<=', $endDate);
        }

        // Get summary statistics
        $totalOrders = $query->count();
        $completedOrders = $query->where('quantity_fulfilled', '>=', DB::raw('quantity_required'))->count();
        $pendingOrders = $query->where('quantity_fulfilled', '<', DB::raw('quantity_required'))->count();
        $totalQuantityRequired = $query->sum('quantity_required');
        $totalQuantityFulfilled = $query->sum('quantity_fulfilled');

        // Calculate efficiency percentage
        $efficiency = $totalQuantityRequired > 0 ? 
            round(($totalQuantityFulfilled / $totalQuantityRequired) * 100, 2) : 0;

        // Get top materials by production volume
        $topMaterials = $query->select('material_id')
            ->selectRaw('SUM(quantity_fulfilled) as total_produced')
            ->with('material:id_mat,material_desc')
            ->groupBy('material_id')
            ->orderByDesc('total_produced')
            ->limit(5)
            ->get();

        // Get production by shift
        $productionByShift = $query->select('shift_id')
            ->selectRaw('COUNT(*) as order_count, SUM(quantity_fulfilled) as total_produced')
            ->groupBy('shift_id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'total_orders' => $totalOrders,
                'completed_orders' => $completedOrders,
                'pending_orders' => $pendingOrders,
                'total_quantity_required' => $totalQuantityRequired,
                'total_quantity_fulfilled' => $totalQuantityFulfilled,
                'efficiency_percentage' => $efficiency,
                'top_materials' => $topMaterials,
                'production_by_shift' => $productionByShift,
            ]
        ]);

    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Failed to fetch production report summary',
            'error' => $e->getMessage()
        ], 500);
    }
}

// Also add this method for filter options
public function getProductionReportFilterOptions(Request $request)
{
    try {
        // In getProductionReportFilterOptions method, add:
$machines = Machine::select('id_machine', 'machine_name') // adjust column names as needed
    ->orderBy('machine_name')
    ->get();

        // Get unique material codes
        $materials = Material::select('id_mat', 'material_code', 'material_desc')
            ->orderBy('material_desc')
            ->get();

        // Get available shifts
        $shifts = ProductionOrder::select('shift_id')
            ->distinct()
            ->orderBy('shift_id')
            ->pluck('shift_id');

        // Get date range
        $dateRange = ProductionOrder::selectRaw('MIN(manufacturing_date) as min_date, MAX(manufacturing_date) as max_date')
            ->first();

        return response()->json([
            'success' => true,
            'data' => [
                'materials' => $materials,
                'shifts' => $shifts,
                'date_range' => $dateRange,
                'machines' => $machines,
            ]
        ]);

    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Failed to fetch filter options',
            'error' => $e->getMessage()
        ], 500);
    }
}

    /**
     * Handle CORS preflight requests
     */
    public function options(): JsonResponse
    {
        return response()->json(['status' => 'OK']);
    }
}