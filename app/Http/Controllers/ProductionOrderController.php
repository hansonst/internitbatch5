<?php
namespace App\Http\Controllers;

use App\Models\ProductionOrder;
use App\Models\ProOrder;
use App\Models\Material;
use App\Models\Machine;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;
use App\Models\DataTimbangan;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

use Exception;

class ProductionOrderController extends Controller
{
    // Add CORS headers helper
    private function addCorsHeaders($response)
    {
        return $response->header('Access-Control-Allow-Origin', '*')
                       ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
                       ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization');
    }

    // Get all production orders
    public function index(): JsonResponse
    {
        try {
            \Log::info('Fetching production orders...');
            
            $productionOrders = ProductionOrder::orderBy('created_at', 'desc')->get();
            
            \Log::info('Production orders fetched:', ['count' => $productionOrders->count()]);
            
            $response = response()->json([
                'success' => true,
                'data' => $productionOrders
            ]);
            
            return $this->addCorsHeaders($response);
        } catch (Exception $e) {
            \Log::error('Production Order Fetch Error:', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            
            $response = response()->json([
                'success' => false,
                'message' => 'Failed to fetch production orders',
                'error' => $e->getMessage()
            ], 500);
            
            return $this->addCorsHeaders($response);
        }
    }
// Replace your getMaterials method in ProductionOrderController with this:
public function getMaterials(): JsonResponse
{
    try {
        \Log::info('Fetching materials for task creation...');
        
        // Use the correct column names that match your database table
        $materials = DB::table('master_materials')
            ->select([
                'id_mat',           // This is your primary key
                'material_code',    // Material code
                'material_desc',    // Material description (this will be displayed)
                'material_type',    // Material type
                'material_group',   // Material group
                'material_uom',     // Unit of measure
                'berat_satuan'      // Weight per unit (your new column)
            ])
            ->orderBy('material_desc')  // Order by description
            ->get();
        
        \Log::info('Found ' . $materials->count() . ' materials');
        
        $response = response()->json([
            'success' => true,
            'message' => 'Materials fetched successfully',
            'data' => $materials
        ]);
        
        return $this->addCorsHeaders($response);
    } catch (Exception $e) {
        \Log::error('Error fetching materials: ' . $e->getMessage());
        
        $response = response()->json([
            'success' => false,
            'message' => 'Failed to fetch materials',
            'error' => $e->getMessage()
        ], 500);
        
        return $this->addCorsHeaders($response);
    }
}
    public function store(Request $request): JsonResponse
    {
        try {
            \Log::info('Production Order Request Data:', $request->all());

            // Basic validation (removed foreign key check to pro_order table)
            $validated = $request->validate([
                'no_pro' => 'required|string',
                'material_id' => 'required|integer',
                'material_desc' => 'required|string',
                'batch_number' => 'required|string|unique:production_order,batch_number',
                'machine_id' => 'required|exists:master_machines,id_machine',
                'machine_name' => 'required|string',
                'finish_date' => 'required|date',
                'manufacturing_date' => 'required|date|before_or_equal:finish_date',
                'quantity_required' => 'required|numeric|min:0.01',
                'quantity_fulfilled' => 'nullable|numeric|min:0',
                'shift_id' => 'nullable|integer',
            ]);

            \Log::info('Validated Data:', $validated);

            // ADDED: Verify that the order exists in SAP API before creating
            $sapOrderExists = $this->verifySapOrder($validated['no_pro'], $validated['batch_number']);
            
            if (!$sapOrderExists) {
                \Log::warning('❌ Order not found in SAP API', [
                    'no_pro' => $validated['no_pro'],
                    'batch_number' => $validated['batch_number']
                ]);
                
                $response = response()->json([
                    'success' => false,
                    'message' => 'Production order not found in SAP system. Please verify the order number and batch number.',
                    'error' => 'SAP_ORDER_NOT_FOUND'
                ], 422);
                
                return $this->addCorsHeaders($response);
            }

            // Set default values
            $validated['quantity_fulfilled'] = $validated['quantity_fulfilled'] ?? 0;
            $validated['shift_id'] = $validated['shift_id'] ?? null;

            $productionOrder = ProductionOrder::create($validated);

            \Log::info('✅ Created Production Order:', $productionOrder->toArray());

            $response = response()->json([
                'success' => true,
                'message' => 'Production order created successfully',
                'data' => $productionOrder
            ], 201);

            return $this->addCorsHeaders($response);

        } catch (ValidationException $e) {
            \Log::error('Validation Error:', $e->errors());
            $response = response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
            
            return $this->addCorsHeaders($response);
        } catch (Exception $e) {
            \Log::error('Production Order Creation Error:', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            
            $response = response()->json([
                'success' => false,
                'message' => 'Failed to create production order',
                'error' => $e->getMessage()
            ], 500);
            
            return $this->addCorsHeaders($response);
        }
    }

    private function verifySapOrder(string $orderNo, string $batchNumber): bool
    {
        try {
            \Log::info('🔍 Verifying order in SAP API', [
                'order_no' => $orderNo,
                'batch_number' => $batchNumber
            ]);

            $period = \Carbon\Carbon::now('Asia/Jakarta')->format('Y-m');

            $sapBaseUrl = env('SAP_BASE_URL', 'https://192.104.210.16:44320');
            $sapClient = env('SAP_CLIENT', '210');
            $sapUsername = env('SAP_USERNAME', 'OJTECHIT01');
            $sapPassword = env('SAP_PASSWORD', '@DragonForce.7');
            
            $sapUrl = "{$sapBaseUrl}/sap/opu/odata4/sap/zpp_oji_pro/srvd/sap/zpp_oji_pro/0001/" .
                      "ZPP_PRO_LIST(period='{$period}')/Set?\$top=999999";
            
            $response = Http::withBasicAuth($sapUsername, $sapPassword)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'sap-client' => $sapClient,
                ])
                ->withOptions(['verify' => false])
                ->timeout(30)
                ->get($sapUrl);
            
            if (!$response->successful()) {
                \Log::error('❌ SAP API verification failed: Status ' . $response->status());
                return false;
            }
            
            $sapData = $response->json();
            $sapData = $response->json();

// ADD THIS DEBUG:
\Log::info('🔍 RAW SAP Data - First 2 orders:');
if (!empty($sapData['value'])) {
    $firstTwo = array_slice($sapData['value'], 0, 2);
    foreach ($firstTwo as $order) {
        \Log::info('ProNo: ' . ($order['ProNo'] ?? 'NULL') . 
                   ', BatchNo: ' . ($order['BatchNo'] ?? 'NULL') . 
                   ', Material: ' . ($order['Materialname'] ?? 'NULL'));
    }
}

$proOrders = collect($sapData['value'] ?? []);
            $proOrders = collect($sapData['value'] ?? []);
            
            \Log::info('🔍 RAW SAP Data - First order BEFORE filtering:');
if ($proOrders->count() > 0) {
    $sample = $proOrders->first();
    \Log::info('ProNo: ' . ($sample['ProNo'] ?? 'NULL'));
    \Log::info('BatchNo: ' . ($sample['BatchNo'] ?? 'NULL'));
    \Log::info('Materialname: ' . ($sample['Materialname'] ?? 'NULL'));
    \Log::info('Full order: ' . json_encode($sample));
}

\Log::info('✅ Fetched ' . $proOrders->count() . ' orders from SAP');

            // Check if order with matching ProNo and BatchNo exists
            $orderExists = $proOrders->contains(function($order) use ($orderNo, $batchNumber) {
                return ($order['ProNo'] ?? null) === $orderNo && 
                       ($order['BatchNo'] ?? null) === $batchNumber;
            });
            
            \Log::info($orderExists ? '✅ Order verified in SAP' : '❌ Order not found in SAP', [
                'order_no' => $orderNo,
                'batch_number' => $batchNumber
            ]);
            
            return $orderExists;
            
        } catch (Exception $e) {
            \Log::error('❌ Error verifying SAP order: ' . $e->getMessage());
            // On error, we'll allow the creation to proceed (fail-open approach)
            // You can change this to return false if you want strict validation
            return true;
        }
    }

// Updated method to fetch pro_order data with optional date filtering
public function getProOrders(Request $request): JsonResponse
{
    try {
        $filter = $request->input('filter', 'daily');
        
        \Log::info('🔄 Fetching pro orders from SAP API with filter: ' . $filter);
        
       
        $timezone = 'Asia/Jakarta';
        
        // SAP Configuration
        $sapBaseUrl = env('SAP_BASE_URL', 'https://192.104.210.16:44320');
        $sapClient = env('SAP_CLIENT', '210');
        $sapUsername = env('SAP_USERNAME', 'OJTECHIT01');
        $sapPassword = env('SAP_PASSWORD', '@DragonForce.7');
        

        $period = \Carbon\Carbon::now($timezone)->format('Y-m');
        
        
        $sapUrl = "{$sapBaseUrl}/sap/opu/odata4/sap/zpp_oji_pro/srvd/sap/zpp_oji_pro/0001/" .
                  "ZPP_PRO_LIST(period='{$period}')/Set?\$top=999999";
        
        \Log::info('📡 SAP API URL: ' . $sapUrl);
        
      
        $response = \Illuminate\Support\Facades\Http::withBasicAuth($sapUsername, $sapPassword)
            ->withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'sap-client' => $sapClient,
            ])
            ->withOptions([
                'verify' => false, // Disable SSL verification for self-signed certificates
            ])
            ->timeout(30)
            ->get($sapUrl);
        
        \Log::info('📥 SAP API Response Status: ' . $response->status());
        
        if (!$response->successful()) {
            \Log::error('❌ SAP API Error: Status ' . $response->status());
            $errorResponse = response()->json([
                'success' => false,
                'message' => 'Failed to fetch data from SAP API',
                'status_code' => $response->status(),
                'error' => $response->body()
            ], 500);
            return $this->addCorsHeaders($errorResponse);
        }
        
        $sapData = $response->json();
        
        $proOrders = collect($sapData['value'] ?? []);
        \Log::info('🔍 RAW SAP Data - First order BEFORE filtering:');
if ($proOrders->count() > 0) {
    $sample = $proOrders->first();
    \Log::info('ProNo: ' . ($sample['ProNo'] ?? 'NULL'));
    \Log::info('BatchNo: ' . ($sample['BatchNo'] ?? 'NULL'));
    \Log::info('Materialname: ' . ($sample['Materialname'] ?? 'NULL'));
    \Log::info('Full order: ' . json_encode($sample));
}

        
        \Log::info('✅ Fetched ' . $proOrders->count() . ' orders from SAP');
        
        // Apply date filters based on filter type
        switch ($filter) {
            case 'daily':
                // Orders for today in Jakarta timezone
                $today = \Carbon\Carbon::now($timezone)->toDateString();
                $proOrders = $proOrders->filter(function($order) use ($today) {
                    $startDate = $order['StartDate'] ?? null;
                    $endDate = $order['EndDate'] ?? null;
                    return $startDate <= $today && $endDate >= $today;
                });
                \Log::info('📅 Filtering by today (Jakarta): ' . $today);
                break;
                
            case 'weekly':
                // Orders for this week in Jakarta timezone
                $weekStart = \Carbon\Carbon::now($timezone)->startOfWeek()->toDateString();
                $weekEnd = \Carbon\Carbon::now($timezone)->endOfWeek()->toDateString();
                $proOrders = $proOrders->filter(function($order) use ($weekStart, $weekEnd) {
                    $startDate = $order['StartDate'] ?? null;
                    $endDate = $order['EndDate'] ?? null;
                    
                    return ($startDate >= $weekStart && $startDate <= $weekEnd) ||
                           ($endDate >= $weekStart && $endDate <= $weekEnd) ||
                           ($startDate <= $weekStart && $endDate >= $weekEnd);
                });
                \Log::info('📅 Filtering by this week (Jakarta): ' . $weekStart . ' to ' . $weekEnd);
                break;
                
            case 'monthly':
                // Orders for this month in Jakarta timezone
                $startOfMonth = \Carbon\Carbon::now($timezone)->startOfMonth()->toDateString();
                $endOfMonth = \Carbon\Carbon::now($timezone)->endOfMonth()->toDateString();
                $proOrders = $proOrders->filter(function($order) use ($startOfMonth, $endOfMonth) {
                    $startDate = $order['StartDate'] ?? null;
                    $endDate = $order['EndDate'] ?? null;
                    
                    return ($startDate >= $startOfMonth && $startDate <= $endOfMonth) ||
                           ($endDate >= $startOfMonth && $endDate <= $endOfMonth) ||
                           ($startDate <= $startOfMonth && $endDate >= $endOfMonth);
                });
                \Log::info('📅 Filtering by this month (Jakarta): ' . \Carbon\Carbon::now($timezone)->format('F Y'));
                break;
                
            case 'date_range':
                // Custom date range selected by user
                $startDate = $request->input('start_date');
                $endDate = $request->input('end_date');
                
                if ($startDate && $endDate) {
                    $start = \Carbon\Carbon::parse($startDate, $timezone)->startOfDay()->toDateString();
                    $end = \Carbon\Carbon::parse($endDate, $timezone)->endOfDay()->toDateString();
                    
                    $proOrders = $proOrders->filter(function($order) use ($start, $end) {
                        $orderStart = $order['StartDate'] ?? null;
                        $orderEnd = $order['EndDate'] ?? null;
                        
                        return ($orderStart >= $start && $orderStart <= $end) ||
                               ($orderEnd >= $start && $orderEnd <= $end) ||
                               ($orderStart <= $start && $orderEnd >= $end);
                    });
                    
                    \Log::info('📅 Filtering by date range (Jakarta): ' . $start . ' to ' . $end);
                } else {
                    \Log::warning('⚠️ Date range filter selected but dates not provided');
                    $errorResponse = response()->json([
                        'success' => false,
                        'message' => 'Start date and end date are required for date range filter',
                        'data' => [],
                        'filter_applied' => $filter,
                        'count' => 0
                    ], 400);
                    return $this->addCorsHeaders($errorResponse);
                }
                break;
                
            default:
                // Default to daily if filter not recognized
                $today = \Carbon\Carbon::now($timezone)->toDateString();
                $proOrders = $proOrders->filter(function($order) use ($today) {
                    $startDate = $order['StartDate'] ?? null;
                    $endDate = $order['EndDate'] ?? null;
                    return $startDate <= $today && $endDate >= $today;
                });
                \Log::info('📅 Unknown filter, defaulting to today (Jakarta): ' . $today);
                break;
        }
        
        // Transform SAP field names to match your app's expected format
$transformedOrders = $proOrders->map(function($order) {
    return [
        'order_id' => $order['ProNo'] ?? null,           // ✅ Changed from 'ProductionOrder'
        'material_id' => $order['ItemNo'] ?? null,
        'material_desc' => $order['Materialname'] ?? null, // ✅ Changed from 'ItemMaterial'
        'material_code' => $order['MaterialCode'] ?? null, // ✅ Changed from 'ItemNo'
        'plant' => $order['Plant'] ?? null,              // ✅ Now available
        'sloc' => $order['Sloc'] ?? null,                // ✅ Storage location
        'batch' => $order['BatchNo'] ?? null,            // ✅ THIS WAS NULL BEFORE!
        'order_quantity' => $order['QtyPro'] ?? null,    // ✅ Changed from 'ProQty'
        'confirmed_quantity' => $order['QtyConfirm'] ?? null, // ✅ Added
        'unit_of_measure' => $order['UnitPro'] ?? null,  // ✅ Changed from 'UoM'
        'basic_start_date' => $order['StartDate'] ?? null,
        'basic_finish_date' => $order['EndDate'] ?? null,
    ];
})->values();
        $transformedOrders = $proOrders->map(function($order) {
    return [
        'order_id' => $order['ProNo'] ?? null,
        // ... rest of mapping
    ];
})->values();

// ADD THIS DEBUG:
\Log::info('🔍 TRANSFORMED Data - First 2 orders:');
if ($transformedOrders->count() > 0) {
    $firstTwo = $transformedOrders->take(2);
    foreach ($firstTwo as $order) {
        \Log::info('order_id: ' . ($order['order_id'] ?? 'NULL') . 
                   ', batch: ' . ($order['batch'] ?? 'NULL') . 
                   ', material_desc: ' . ($order['material_desc'] ?? 'NULL'));
    }
}
        \Log::info('✅ Found ' . $transformedOrders->count() . ' pro orders after filtering');
        
        $response = response()->json([
            'success' => true,
            'message' => 'Pro orders fetched successfully from SAP',
            'data' => $transformedOrders,
            'filter_applied' => $filter,
            'count' => $transformedOrders->count(),
            'timezone' => $timezone,
            'source' => 'SAP API'
        ]);
        
        return $this->addCorsHeaders($response);
        
    } catch (\Illuminate\Http\Client\ConnectionException $e) {
        \Log::error('❌ Cannot connect to SAP server: ' . $e->getMessage());
        $errorResponse = response()->json([
            'success' => false,
            'message' => 'Cannot connect to SAP server',
            'error' => $e->getMessage()
        ], 503);
        return $this->addCorsHeaders($errorResponse);
        
    } catch (\Illuminate\Http\Client\RequestException $e) {
        \Log::error('❌ SAP API request error: ' . $e->getMessage());
        $errorResponse = response()->json([
            'success' => false,
            'message' => 'SAP API request failed',
            'error' => $e->getMessage()
        ], 500);
        return $this->addCorsHeaders($errorResponse);
        
    } catch (\Exception $e) {
        \Log::error('❌ Error fetching pro orders: ' . $e->getMessage());
        $errorResponse = response()->json([
            'success' => false,
            'message' => 'Failed to fetch pro orders',
            'error' => $e->getMessage()
        ], 500);
        return $this->addCorsHeaders($errorResponse);
    }
}

// Get batches for a specific production order
public function getBatchesByOrderId(Request $request): JsonResponse
{
    try {
        $orderId = $request->input('order_id'); // or 'no_pro'
        
        \Log::info('🔍 Fetching batches for order: ' . $orderId);
        
        // Query production_order table by no_pro (the order ID from SAP)
        $batches = \DB::table('production_order')
            ->where('no_pro', $orderId)
            ->select(
                'batch_number',
                'transaction_id',
                'material_id',
                'material_desc',
                'machine_id',
                'machine_name',
                'manufacturing_date',
                'finish_date',
                'quantity_required',
                'shift_id'
            )
            ->orderBy('created_at', 'desc')
            ->get();
        
        \Log::info('✅ Found ' . $batches->count() . ' batches');
        
        $response = response()->json([
            'success' => true,
            'message' => 'Batches fetched successfully',
            'data' => $batches,
            'count' => $batches->count()
        ]);
        
        return $this->addCorsHeaders($response);
        
    } catch (\Exception $e) {
        \Log::error('❌ Error fetching batches: ' . $e->getMessage());
        $errorResponse = response()->json([
            'success' => false,
            'message' => 'Failed to fetch batches',
            'error' => $e->getMessage()
        ], 500);
        return $this->addCorsHeaders($errorResponse);
    }
}
    // Fetch machines for dropdown
    public function getMachines(): JsonResponse
    {
        try {
            $machines = Machine::select('id_machine', 'machine_name')
                ->orderBy('machine_name')
                ->get();
            
            $response = response()->json([
                'success' => true,
                'data' => $machines
            ]);
            
            return $this->addCorsHeaders($response);
        } catch (Exception $e) {
            $response = response()->json([
                'success' => false,
                'message' => 'Failed to fetch machines',
                'error' => $e->getMessage()
            ], 500);
            
            return $this->addCorsHeaders($response);
        }
    }

    // Fetch groups for dropdown
    public function getGroups(): JsonResponse
    {
        try {
            \Log::info('🔄 Fetching groups from database...');
            
            // Since groups table only has group_code column
            $groups = DB::table('groups')
                ->select('group_code')
                ->orderBy('group_code', 'asc')
                ->get();
            
            // Transform the data to make it more user-friendly for the dropdown
            $transformedGroups = $groups->map(function ($group) {
                return [
                    'id' => $group->group_code,  // Use group_code as id for dropdown
                    'name' => $group->group_code, // Use group_code as display name
                    'group_code' => $group->group_code
                ];
            });
            
            \Log::info('✅ Found ' . $groups->count() . ' groups');
            
            return response()->json([
                'success' => true,
                'message' => 'Groups fetched successfully',
                'data' => $transformedGroups
            ]);
        } catch (\Exception $e) {
            \Log::error('❌ Error fetching groups: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch groups: ' . $e->getMessage(),
                'data' => []
            ], 500);
        }
    }

    // Get a specific production order by batch number
    public function show($batchNumber): JsonResponse
    {
        try {
            \Log::info('🔍 Fetching production order with batch number: ' . $batchNumber);
            
            $productionOrder = ProductionOrder::where('batch_number', $batchNumber)->first();
            
            if (!$productionOrder) {
                \Log::warning('❌ Production order not found with batch number: ' . $batchNumber);
                $response = response()->json([
                    'success' => false,
                    'message' => 'Production order not found'
                ], 404);
                
                return $this->addCorsHeaders($response);
            }
            
            \Log::info('✅ Production order found: ' . $productionOrder->no_pro);
            
            $response = response()->json([
                'success' => true,
                'data' => $productionOrder
            ]);
            
            return $this->addCorsHeaders($response);
        } catch (Exception $e) {
            \Log::error('❌ Error fetching production order: ' . $e->getMessage());
            
            $response = response()->json([
                'success' => false,
                'message' => 'Failed to fetch production order',
                'error' => $e->getMessage()
            ], 500);
            
            return $this->addCorsHeaders($response);
        }
    }

    // Update a production order by batch number
    public function update(Request $request, $batchNumber): JsonResponse
    {
        try {
            \Log::info('🔄 Updating production order with batch number: ' . $batchNumber);
            \Log::info('📝 Update data:', $request->all());
            
            $productionOrder = ProductionOrder::where('batch_number', $batchNumber)->first();
            
            if (!$productionOrder) {
                \Log::warning('❌ Production order not found with batch number: ' . $batchNumber);
                $response = response()->json([
                    'success' => false,
                    'message' => 'Production order not found'
                ], 404);
                
                return $this->addCorsHeaders($response);
            }
            
            $validated = $request->validate([
                'no_pro' => 'sometimes|string',
                'material_id' => 'sometimes|exists:master_materials,id_mat',
                'material_desc' => 'sometimes|string',
                'batch_number' => 'sometimes|string|unique:production_order,batch_number,' . $batchNumber . ',batch_number',
                'machine_id' => 'sometimes|exists:master_machines,id_machine',
                'machine_name' => 'sometimes|string',
                'finish_date' => 'sometimes|date',
                'manufacturing_date' => 'sometimes|date|before_or_equal:finish_date',
                'quantity_required' => 'sometimes|numeric|min:0.01',
                'quantity_fulfilled' => 'sometimes|numeric|min:0',
                'shift_id' => 'sometimes|integer',
                'is_approved' => 'sometimes|boolean',
            ]);

            $productionOrder->update($validated);
            
            \Log::info('✅ Production order updated successfully: ' . $productionOrder->no_pro);

            $response = response()->json([
                'success' => true,
                'message' => 'Production order updated successfully',
                'data' => $productionOrder->fresh()
            ]);

            return $this->addCorsHeaders($response);

        } catch (ValidationException $e) {
            \Log::error('❌ Validation error:', $e->errors());
            $response = response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
            
            return $this->addCorsHeaders($response);
        } catch (Exception $e) {
            \Log::error('❌ Error updating production order: ' . $e->getMessage());
            
            $response = response()->json([
                'success' => false,
                'message' => 'Failed to update production order',
                'error' => $e->getMessage()
            ], 500);
            
            return $this->addCorsHeaders($response);
        }
    }

    // Soft delete a production order by changing status to inactive
public function destroy($batchNumber): JsonResponse
{
    try {
        \Log::info('🗑️ Soft deleting production order with batch number: ' . $batchNumber);
        
        $productionOrder = ProductionOrder::where('batch_number', $batchNumber)->first();
        
        if (!$productionOrder) {
            \Log::warning('❌ Production order not found with batch number: ' . $batchNumber);
            $response = response()->json([
                'success' => false,
                'message' => 'Production order not found'
            ], 404);
            
            return $this->addCorsHeaders($response);
        }
        
        // Check if already inactive
        if ($productionOrder->order_status === 'inactive') {
            \Log::warning('⚠️ Production order already inactive: ' . $batchNumber);
            $response = response()->json([
                'success' => false,
                'message' => 'Production order is already inactive'
            ], 400);
            
            return $this->addCorsHeaders($response);
        }
        
        $deletedOrderInfo = [
            'no_pro' => $productionOrder->no_pro,
            'batch_number' => $productionOrder->batch_number,
            'material_desc' => $productionOrder->material_desc
        ];
        
        // Change status to inactive instead of deleting
        $productionOrder->order_status = 'inactive';
        $productionOrder->save();
        
        \Log::info('✅ Production order status changed to inactive: ' . $deletedOrderInfo['no_pro']);

        $response = response()->json([
            'success' => true,
            'message' => 'Production order deactivated successfully',
            'deleted_order' => $deletedOrderInfo
        ]);
        
        return $this->addCorsHeaders($response);
    } catch (Exception $e) {
        \Log::error('❌ Error deactivating production order: ' . $e->getMessage());
        
        $response = response()->json([
            'success' => false,
            'message' => 'Failed to deactivate production order',
            'error' => $e->getMessage()
        ], 500);
        
        return $this->addCorsHeaders($response);
    }
}

public function close($batchNumber): JsonResponse
{
    try {
        \Log::info('🔒 Closing production order with batch number: ' . $batchNumber);
        \Log::info('🔍 Request data: ' . json_encode(request()->all()));
        
        $productionOrder = ProductionOrder::where('batch_number', $batchNumber)->first();
        
        \Log::info('🔍 Found order: ' . ($productionOrder ? 'YES' : 'NO'));
        
        if (!$productionOrder) {
            \Log::warning('❌ Production order not found with batch number: ' . $batchNumber);
            $response = response()->json([
                'success' => false,
                'message' => 'Production order not found'
            ], 404);
            
            return $this->addCorsHeaders($response);
        }
        
        \Log::info('🔍 Current order_status: ' . ($productionOrder->order_status ?? 'NULL'));
        
        // Check if already pending
        if ($productionOrder->order_status === 'pending') {
            \Log::warning('⚠️ Production order already pending: ' . $batchNumber);
            $response = response()->json([
                'success' => false,
                'message' => 'Production order is already pending'
            ], 400);
            
            return $this->addCorsHeaders($response);
        }
        
        // Check if already inactive
        if ($productionOrder->order_status === 'inactive') {
            \Log::warning('⚠️ Cannot close an inactive production order: ' . $batchNumber);
            $response = response()->json([
                'success' => false,
                'message' => 'Cannot close an inactive production order'
            ], 400);
            
            return $this->addCorsHeaders($response);
        }
        
        $closedOrderInfo = [
            'no_pro' => $productionOrder->no_pro,
            'batch_number' => $productionOrder->batch_number,
            'material_desc' => $productionOrder->material_desc,
            'previous_status' => $productionOrder->order_status
        ];
        
        \Log::info('🔍 About to save order_status = pending');
        
        // Change status to pending
        $productionOrder->order_status = 'pending';
        $productionOrder->save();
        
        \Log::info('✅ Production order manually closed by admin: ' . $closedOrderInfo['no_pro']);

        $response = response()->json([
            'success' => true,
            'message' => 'Production order closed successfully (marked as pending)',
            'closed_order' => $closedOrderInfo
        ]);
        
        return $this->addCorsHeaders($response);
    } catch (Exception $e) {
        \Log::error('❌ Error closing production order: ' . $e->getMessage());
        \Log::error('❌ Stack trace: ' . $e->getTraceAsString());
        
        $response = response()->json([
            'success' => false,
            'message' => 'Failed to close production order',
            'error' => $e->getMessage()
        ], 500);
        
        return $this->addCorsHeaders($response);
    }
}

    // Handle OPTIONS requests for CORS
    public function options()
    {
        $response = response()->json(['status' => 'OK']);
        return $this->addCorsHeaders($response);
    }

    // Replace your existing getUnassignedOrders method with this improved version

public function getUnassignedOrders(): JsonResponse
{
    try {
        \Log::info('🔄 Fetching unassigned production orders...');
        
        $unassignedOrders = ProductionOrder::where(function($query) {
                $query->whereNull('assigned_group_code')
                      ->orWhere('assigned_group_code', '');
            })
            ->orderBy('created_at', 'desc')
            ->get();
        
        \Log::info('✅ Found ' . $unassignedOrders->count() . ' unassigned production orders');
        
        $response = response()->json([
            'success' => true,
            'message' => $unassignedOrders->count() > 0 
                ? 'Unassigned production orders fetched successfully' 
                : 'No unassigned production orders found',
            'data' => $unassignedOrders,
            'count' => $unassignedOrders->count()
        ]);
        
        return $this->addCorsHeaders($response);
    } catch (Exception $e) {
        \Log::error('❌ Error fetching unassigned production orders: ' . $e->getMessage());
        
        $response = response()->json([
            'success' => false,
            'message' => 'Failed to fetch unassigned production orders',
            'error' => $e->getMessage()
        ], 500);
        
        return $this->addCorsHeaders($response);
    }
}

   // Updated getOrdersByGroup method with batch locking
public function getOrdersByGroup($groupCode): JsonResponse
{
    try {
        \Log::info('🔄 Fetching production orders for group: ' . $groupCode);
        
        // Get batch numbers that have active sessions (locked batches)
        $lockedBatches = DataTimbangan::whereNull('ending_counter_pro')
            ->pluck('batch_number')
            ->toArray();
        
        \Log::info('🔒 Found ' . count($lockedBatches) . ' locked batches: ' . implode(', ', $lockedBatches));
        
        // Fetch orders for the group, excluding locked batches
        $orders = ProductionOrder::where('assigned_group_code', $groupCode)
            ->whereNotIn('batch_number', $lockedBatches)
            ->orderBy('created_at', 'desc')
            ->get();
        
        \Log::info('✅ Found ' . $orders->count() . ' available production orders for group: ' . $groupCode);
        
        $response = response()->json([
            'success' => true,
            'message' => 'Production orders for group fetched successfully',
            'data' => $orders,
            'locked_batches_count' => count($lockedBatches),
            'locked_batches' => $lockedBatches // Optional: include for debugging
        ]);
        
        return $this->addCorsHeaders($response);
    } catch (Exception $e) {
        \Log::error('❌ Error fetching production orders for group: ' . $e->getMessage());
        
        $response = response()->json([
            'success' => false,
            'message' => 'Failed to fetch production orders for group',
            'error' => $e->getMessage()
        ], 500);
        
        return $this->addCorsHeaders($response);
    }
}

    // Assign group to production order
    public function assignGroup(Request $request, $batchNumber): JsonResponse
    {
        try {
            \Log::info('🔄 Assigning group to production order: ' . $batchNumber);
            \Log::info('📝 Assignment data:', $request->all());
            
            $productionOrder = ProductionOrder::where('batch_number', $batchNumber)->first();
            
            if (!$productionOrder) {
                \Log::warning('❌ Production order not found with batch number: ' . $batchNumber);
                $response = response()->json([
                    'success' => false,
                    'message' => 'Production order not found'
                ], 404);
                
                return $this->addCorsHeaders($response);
            }
            
            $validated = $request->validate([
                'assigned_group_code' => 'required|string|exists:groups,group_code'
            ]);
            
            // Check if the group exists
            $groupExists = DB::table('groups')->where('group_code', $validated['assigned_group_code'])->exists();
            if (!$groupExists) {
                $response = response()->json([
                    'success' => false,
                    'message' => 'Group not found'
                ], 404);
                
                return $this->addCorsHeaders($response);
            }
            
            $productionOrder->update([
                'assigned_group_code' => $validated['assigned_group_code']
            ]);
            
            \Log::info('✅ Group assigned successfully to production order: ' . $productionOrder->no_pro);
            
            $response = response()->json([
                'success' => true,
                'message' => 'Group assigned successfully',
                'data' => $productionOrder->fresh()
            ]);
            
            return $this->addCorsHeaders($response);
            
        } catch (ValidationException $e) {
            \Log::error('❌ Validation error:', $e->errors());
            $response = response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
            
            return $this->addCorsHeaders($response);
        } catch (Exception $e) {
            \Log::error('❌ Error assigning group: ' . $e->getMessage());
            
            $response = response()->json([
                'success' => false,
                'message' => 'Failed to assign group',
                'error' => $e->getMessage()
            ], 500);
            
            return $this->addCorsHeaders($response);
        }
    }

    // Remove group assignment from production order
    public function removeGroup($batchNumber): JsonResponse
    {
        try {
            \Log::info('🔄 Removing group assignment from production order: ' . $batchNumber);
            
            $productionOrder = ProductionOrder::where('batch_number', $batchNumber)->first();
            
            if (!$productionOrder) {
                \Log::warning('❌ Production order not found with batch number: ' . $batchNumber);
                $response = response()->json([
                    'success' => false,
                    'message' => 'Production order not found'
                ], 404);
                
                return $this->addCorsHeaders($response);
            }
            
            $productionOrder->update([
                'assigned_group_code' => null
            ]);
            
            \Log::info('✅ Group assignment removed successfully from production order: ' . $productionOrder->no_pro);
            
            $response = response()->json([
                'success' => true,
                'message' => 'Group assignment removed successfully',
                'data' => $productionOrder->fresh()
            ]);
            
            return $this->addCorsHeaders($response);
            
        } catch (Exception $e) {
            \Log::error('❌ Error removing group assignment: ' . $e->getMessage());
            
            $response = response()->json([
                'success' => false,
                'message' => 'Failed to remove group assignment',
                'error' => $e->getMessage()
            ], 500);
            
            return $this->addCorsHeaders($response);
        }
    }
}