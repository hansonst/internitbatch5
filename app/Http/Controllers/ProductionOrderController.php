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

        $validated = $request->validate([
            'no_pro' => 'required|string', // This comes from selected order_id
            'material_id' => 'required|integer', // Auto-selected from pro_order
            'material_desc' => 'required|string', // Auto-selected from pro_order
            'batch_number' => 'required|string|unique:production_order,batch_number',
            'machine_id' => 'required|exists:master_machines,id_machine',
            'machine_name' => 'required|string',
            'finish_date' => 'required|date', // Auto-populated from pro_order
            'manufacturing_date' => 'required|date|before_or_equal:finish_date', // Auto-populated from pro_order
            'quantity_required' => 'required|numeric|min:0.01',
            'quantity_fulfilled' => 'nullable|numeric|min:0',
            'shift_id' => 'nullable|integer',
        ]);

        \Log::info('Validated Data:', $validated);

        // Set default values
        $validated['quantity_fulfilled'] = $validated['quantity_fulfilled'] ?? 0;
        $validated['shift_id'] = $validated['shift_id'] ?? null;

        $productionOrder = ProductionOrder::create($validated);

        \Log::info('Created Production Order:', $productionOrder->toArray());

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

    // Add this method to fetch pro_order data for the dropdowns
public function getProOrders(): JsonResponse
{
    try {
        \Log::info('🔄 Fetching pro orders for task creation...');
        
        // Fetch all pro_order records with necessary fields
        $proOrders = DB::table('pro_order')
            ->select([
                'order_id',
                'material_id', 
                'material_desc',
                'plant',
                'production_version',
                'batch',
                'order_quantity',
                'unit_of_measure',
                'basic_start_date',
                'basic_finish_date',
                'material_code'
            ])
            ->orderBy('order_id')
            ->orderBy('batch')
            ->get();
        
        \Log::info('✅ Found ' . $proOrders->count() . ' pro orders');
        
        $response = response()->json([
            'success' => true,
            'message' => 'Pro orders fetched successfully',
            'data' => $proOrders
        ]);
        
        return $this->addCorsHeaders($response);
    } catch (Exception $e) {
        \Log::error('❌ Error fetching pro orders: ' . $e->getMessage());
        
        $response = response()->json([
            'success' => false,
            'message' => 'Failed to fetch pro orders',
            'error' => $e->getMessage()
        ], 500);
        
        return $this->addCorsHeaders($response);
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

    // Delete a production order by batch number
    public function destroy($batchNumber): JsonResponse
    {
        try {
            \Log::info('🗑️ Deleting production order with batch number: ' . $batchNumber);
            
            $productionOrder = ProductionOrder::where('batch_number', $batchNumber)->first();
            
            if (!$productionOrder) {
                \Log::warning('❌ Production order not found with batch number: ' . $batchNumber);
                $response = response()->json([
                    'success' => false,
                    'message' => 'Production order not found'
                ], 404);
                
                return $this->addCorsHeaders($response);
            }
            
            $deletedOrderInfo = [
                'no_pro' => $productionOrder->no_pro,
                'batch_number' => $productionOrder->batch_number,
                'material_desc' => $productionOrder->material_desc
            ];
            
            $productionOrder->delete();
            
            \Log::info('✅ Production order deleted successfully: ' . $deletedOrderInfo['no_pro']);

            $response = response()->json([
                'success' => true,
                'message' => 'Production order deleted successfully',
                'deleted_order' => $deletedOrderInfo
            ]);
            
            return $this->addCorsHeaders($response);
        } catch (Exception $e) {
            \Log::error('❌ Error deleting production order: ' . $e->getMessage());
            
            $response = response()->json([
                'success' => false,
                'message' => 'Failed to delete production order',
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
