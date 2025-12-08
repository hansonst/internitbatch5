<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\GoodReceipt;
use App\Models\SapActivityLog;
use Exception;


class SapGrController extends Controller
{
    protected function logSapActivity(
        $activityType,
        $request,
        $success,
        $responseData = null,
        $statusCode = null,
        $errorMessage = null,
        $responseTime = null,
        $sapEndpoint = null
    ) {
        try {
            $user = $request->user();
            
            // Extract business references from request
            $requestData = $request->all();
            
            return SapActivityLog::create([
                'activity_type' => $activityType,
                'action' => $this->getActionFromActivityType($activityType),
                'user_id' => $user ? $user->user_id : null,
                'users_table_id' => $user ? $user->id : null,
                'user_email' => $user ? $user->email : null,
                'first_name' => $user ? $user->first_name : null,
                'last_name' => $user ? $user->last_name : null,
                'full_name' => $user ? $user->full_name : null,
                'jabatan' => $user ? $user->jabatan : null,
                'department' => $user ? $user->department : null,
                'ip_address' => $request->ip(),
                'po_no' => $requestData['po_no'] ?? null,
                'item_po' => $requestData['item_po'] ?? null,
                'dn_no' => $requestData['dn_no'] ?? null,
                'material_doc_no' => $responseData['mat_doc'] ?? $responseData['material_doc_no'] ?? null,
                'plant' => $requestData['plant'] ?? null,
                'request_payload' => $requestData,
                'response_data' => $responseData,
                'success' => $success,
                'status_code' => $statusCode,
                'error_message' => $errorMessage,
                'response_time_ms' => $responseTime,
                'sap_endpoint' => $sapEndpoint
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to log SAP activity', [
                'error' => $e->getMessage(),
                'activity_type' => $activityType
            ]);
            return null;
        }
    }

    /**
     * Get action name from activity type
     */
    private function getActionFromActivityType($activityType)
    {
        $actions = [
            'get_po' => 'view',
            'create_gr' => 'create',
            'get_gr_summary' => 'view',
            'get_po_list' => 'view',
            'cancel_gr' => 'delete',
            'update_gr' => 'update',
            'get_gr_history' => 'view', // NEW
            'get_gr_history_by_item' => 'view', // NEW
            'get_gr_dropdown_values' => 'view' // NEW
        ];

        return $actions[$activityType] ?? 'unknown';
    }
    private $baseUrl;
private $username;
private $password;
private $sapClient;

public function __construct()
{
    $this->baseUrl = env('SAP_BASE_URL');
    $this->username = env('SAP_USERNAME');
    $this->password = env('SAP_PASSWORD');
    $this->sapClient = env('SAP_CLIENT');
}

    /**
     * Get Purchase Order details - requires PO number input
     * 
     * @param Request $request  
     * @return \Illuminate\Http\JsonResponse
     */
    public function getPurchaseOrder(Request $request)
{
    // Validate that po_no is provided
    $validated = $request->validate([
        'po_no' => 'required|string'
    ]);

    $poNo = $validated['po_no'];
    
    // Get authenticated user
    $user = $request->user();
    $startTime = microtime(true);
    
    // 🔍 DEBUG: Log PO number details
    Log::info('=== GET PURCHASE ORDER START ===', [
        'po_no' => $poNo,
        'po_length' => strlen($poNo),
        'po_type' => gettype($poNo),
        'auth_user' => $user ? ($user->user_id ?? $user->id) : 'PUBLIC',
        'user_email' => $user ? ($user->email ?? 'N/A') : 'PUBLIC'
    ]);

    try {
        $url = "{$this->baseUrl}/sap/opu/odata4/sap/zmm_oji_po_bind/srvd/sap/zmm_oji_po/0001/ZPOA_DTL_LIST(po_no='{$poNo}')/Set";

        Log::info('Making request to SAP', [
            'url' => $url,
            'username' => $this->username,
            'requested_by' => $user ? ($user->email ?? $user->user_id ?? 'N/A') : 'PUBLIC'
        ]);

        $response = Http::withBasicAuth($this->username, $this->password)
            ->withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'sap-client' => $this->sapClient
            ])
            ->withOptions([
                'verify' => false 
            ])
            ->get($url);

        // 🔍 DEBUG: Log full SAP response
        Log::info('SAP Response received', [
            'status' => $response->status(),
            'successful' => $response->successful(),
            'response_body' => $response->json(), // Full response
            'item_count' => count($response->json()['value'] ?? []) // Count items
        ]);

        if ($response->successful()) {
            $responseTime = round((microtime(true) - $startTime) * 1000);
            $responseData = $response->json();
            
            // 🔍 DEBUG: Check if value array is empty
            if (empty($responseData['value'])) {
                Log::warning('⚠️ SAP returned empty items array', [
                    'po_no' => $poNo,
                    'full_response' => $responseData
                ]);
            }
            
            $this->logSapActivity('get_po', $request, true, $responseData, $response->status(), null, $responseTime, $url);
            
            Log::info('=== GET PURCHASE ORDER END (SUCCESS) ===', [
                'items_returned' => count($responseData['value'] ?? [])
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Purchase order retrieved successfully',
                'data' => $responseData
            ]);
        }

        Log::warning('SAP request failed', [
            'status' => $response->status(),
            'body' => $response->body()
        ]);
        
        $responseTime = round((microtime(true) - $startTime) * 1000);
        $this->logSapActivity('get_po', $request, false, null, $response->status(), $response->body(), $responseTime, $url);
        
        return response()->json([
            'success' => false,
            'message' => 'Failed to fetch PO data',
            'error' => $response->body()
        ], $response->status());

    } catch (Exception $e) {
        $responseTime = round((microtime(true) - $startTime) * 1000);
        $this->logSapActivity('get_po', $request, false, null, 500, $e->getMessage(), $responseTime, $url ?? null);
        
        Log::error('Exception in getPurchaseOrder', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Error fetching PO data',
            'error' => $e->getMessage()
        ], 500);
    }
}

    /**
     * Create Good Receipt Entry - Fixed to match SAP API requirements
     * 
     * @param Request $request
 * @return \Illuminate\Http\JsonResponse
 */
public function createGoodReceipt(Request $request)
{
    // Validate input - support both single item and batch items
    $validated = $request->validate([
        'dn_no' => 'required|string',  
        'doc_date' => 'required|date_format:d-m-Y', 
    'post_date' => 'required|date_format:d-m-Y',
        
        // ✅ Only validate items array structure
        'items' => 'required|array|min:1',
        'items.*.po_no' => 'required|string',
        'items.*.item_po' => 'required|string',
        'items.*.qty' => 'required|numeric|min:0.01',  
        'items.*.plant' => 'required|string',
        'items.*.sloc' => 'nullable|string',
        'items.*.batch_no' => 'nullable|string',
        'items.*.dom' => 'nullable|string'
    ]);

    $docDate = \Carbon\Carbon::createFromFormat('d-m-Y', $validated['doc_date'])->format('Y-m-d');
$postDate = \Carbon\Carbon::createFromFormat('d-m-Y', $validated['post_date'])->format('Y-m-d');

    // Get authenticated user
    $user = $request->user();
    $startTime = microtime(true);

    Log::info('=== CREATE GOOD RECEIPT START ===', [
        'auth_user' => $user ? ($user->user_id ?? $user->id) : 'PUBLIC',
        'user_email' => $user ? ($user->email ?? 'N/A') : 'PUBLIC',
        'items_count' => count($validated['items']),
        'request_data' => $validated
    ]);

    try {
        $url = "{$this->baseUrl}/zapi/ZAPI/OJI_GR_ENTRY?sap-client={$this->sapClient}";

        // Build it_input array for SAP API
        $itInputArray = [];
        $goodReceiptRecords = []; // Track DB records

        foreach ($validated['items'] as $item) {
            // Prepare SAP payload item
            $itInputArray[] = [
                'po_no' => $item['po_no'],
                'item_po' => str_pad($item['item_po'], 5, '0', STR_PAD_LEFT),
                'qty' => $item['qty'],
                'plant' => $item['plant'],
                'sloc' => $item['sloc'] ?? '',
                'batch_no' => $item['batch_no'] ?? '',
                'dom' => !empty($item['dom']) ? $item['dom'] : $validated['doc_date']
            ];

            // Create DB record for tracking (initially pending)
            $goodReceiptRecords[] = GoodReceipt::create([
                'dn_no' => $validated['dn_no'],
                'date_gr' => $validated['post_date'],
                'doc_date' => $validated['doc_date'],
                'po_no' => $item['po_no'],
                'item_po' => $item['item_po'],
                'qty' => $item['qty'],
                'plant' => $item['plant'],
                'sloc' => $item['sloc'] ?? null,
                'batch_no' => $item['batch_no'] ?? null,
                'dom' => $item['dom'] ?? null,
                'success' => false,
                'error_message' => 'Processing...',
                'user_id' => $user ? $user->user_id : null,
                'users_table_id' => $user ? $user->id : null,
                'user_email' => $user ? $user->email : null,
                'department' => $user ? $user->department : null,
                'sap_request' => $item,
                'sap_endpoint' => $url
            ]);
        }

        $payload = [
    'dn_no' => $validated['dn_no'] ?? '',
    'doc_date' => $docDate,   
    'post_date' => $postDate, 
    'it_input' => $itInputArray
];

        Log::info('GR Payload (Batch)', [
            'items_count' => count($itInputArray),
            'payload' => $payload
        ]);

        // Send to SAP
        $response = Http::withBasicAuth($this->username, $this->password)
            ->withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json'
            ])
            ->withOptions(['verify' => false])
            ->post($url, $payload);

        Log::info('SAP GR Response received', [
            'status' => $response->status(),
            'successful' => $response->successful(),
            'response_body' => $response->body()
        ]);

        if ($response->successful()) {
            $responseTime = round((microtime(true) - $startTime) * 1000);
            $sapData = $response->json();

            // Update all DB records with success
            foreach ($goodReceiptRecords as $record) {
                $record->update([
                    'success' => true,
                    'error_message' => null,
                    'material_doc_no' => $sapData['mat_doc'] ?? $sapData['material_doc_no'] ?? null,
                    'doc_year' => $sapData['doc_year'] ?? $sapData['year'] ?? null,
                    'posting_date' => $sapData['posting_date'] ?? null,
                    'sap_response' => $sapData,
                    'response_time_ms' => $responseTime
                ]);
            }

            $this->logSapActivity(
                'create_gr', 
                $request, 
                true, 
                $sapData, 
                $response->status(), 
                null, 
                $responseTime, 
                $url
            );

            Log::info('=== CREATE GOOD RECEIPT END (SUCCESS) ===', [
                'items_processed' => count($goodReceiptRecords),
                'material_doc_no' => $sapData['mat_doc'] ?? 'N/A'
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Good Receipt created successfully',
                'data' => $sapData,
                'meta' => [
                    'items_processed' => count($goodReceiptRecords)
                ]
            ]);
        }

        // Handle failure - update all records
        Log::warning('SAP GR request failed', [
            'status' => $response->status(),
            'body' => $response->body()
        ]);

        $responseTime = round((microtime(true) - $startTime) * 1000);

        foreach ($goodReceiptRecords as $record) {
            $record->update([
                'success' => false,
                'error_message' => $response->body(),
                'sap_response' => ['error' => $response->body()],
                'response_time_ms' => $responseTime
            ]);
        }

        $this->logSapActivity(
            'create_gr', 
            $request, 
            false, 
            null, 
            $response->status(), 
            $response->body(), 
            $responseTime, 
            $url
        );

        return response()->json([
            'success' => false,
            'message' => 'Failed to create Good Receipt',
            'error' => $response->body()
        ], $response->status());

    } catch (Exception $e) {
        $responseTime = round((microtime(true) - $startTime) * 1000);

        // Update all pending records with error
        foreach ($goodReceiptRecords as $record) {
            $record->update([
                'success' => false,
                'error_message' => $e->getMessage(),
                'response_time_ms' => $responseTime
            ]);
        }

        $this->logSapActivity(
            'create_gr', 
            $request, 
            false, 
            null, 
            500, 
            $e->getMessage(), 
            $responseTime, 
            $url ?? null
        );

        Log::error('Exception in createGoodReceipt', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Error creating Good Receipt',
            'error' => $e->getMessage()
        ], 500);
    }
}

    public function getGrHistoryByItem(Request $request)
    {
        $validated = $request->validate([
            'po_no' => 'required|string',
            'item_po' => 'required|string'
        ]);

        $poNo = $validated['po_no'];
        $itemPo = $validated['item_po'];
        $user = $request->user();

        Log::info('=== GET GR HISTORY BY ITEM START ===', [
            'po_no' => $poNo,
            'item_po' => $itemPo,
            'requested_by' => $user ? ($user->email ?? $user->user_id ?? 'N/A') : 'PUBLIC'
        ]);

        try {
            $grRecords = GoodReceipt::where('po_no', $poNo)
                ->where('item_po', $itemPo)
                ->where('success', true)
                ->whereNotNull('material_doc_no')
                ->orderBy('date_gr', 'desc')
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($record) {
                    return [
                        'date_gr' => $record->date_gr,
                        'qty' => $record->qty,
                        'dn_no' => $record->dn_no,
                        'sloc' => $record->sloc,
                        'batch_no' => $record->batch_no ?? '',
                        'mat_doc' => $record->material_doc_no,
                        'doc_year' => $record->doc_year,
                        'plant' => $record->plant,
                        'created_at' => $record->created_at->format('Y-m-d H:i:s'),
                        'created_by' => $record->user_email ?? 'Unknown',
                        'department' => $record->department
                    ];
                });

            return response()->json([
                'success' => true,
                'message' => 'GR history retrieved successfully',
                'data' => $grRecords,
                'meta' => [
                    'po_no' => $poNo,
                    'item_po' => $itemPo,
                    'total_records' => $grRecords->count()
                ]
            ]);

        } catch (Exception $e) {
            Log::error('Exception in getGrHistoryByItem', [
                'po_no' => $poNo,
                'item_po' => $itemPo,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error fetching GR history',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function getGrDropdownValues(Request $request)
    {
        $validated = $request->validate([
            'po_no' => 'required|string',
            'item_po' => 'nullable|string' // Optional: filter by specific item
        ]);

        $poNo = $validated['po_no'];
        $itemPo = $validated['item_po'] ?? null;

        try {
            $query = GoodReceipt::where('po_no', $poNo)
                ->where('success', true)
                ->whereNotNull('material_doc_no');

            if ($itemPo) {
                $query->where('item_po', $itemPo);
            }

            $records = $query->get();

            // Get unique values for dropdowns
            $dnNos = $records->pluck('dn_no')->unique()->filter()->sort()->values();
            $dateGrs = $records->pluck('date_gr')->unique()->filter()->sort()->values();
            $batchNos = $records->pluck('batch_no')->unique()->filter()->reject(function($value) {
                return empty($value);
            })->sort()->values();
            $slocs = $records->pluck('sloc')->unique()->filter()->sort()->values();

            return response()->json([
                'success' => true,
                'message' => 'Dropdown values retrieved successfully',
                'data' => [
                    'dn_no' => $dnNos,
                    'date_gr' => $dateGrs,
                    'batch_no' => $batchNos,
                    'sloc' => $slocs
                ],
                'meta' => [
                    'po_no' => $poNo,
                    'item_po' => $itemPo,
                    'total_records' => $records->count()
                ]
            ]);

        } catch (Exception $e) {
            Log::error('Exception in getGrDropdownValues', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error fetching dropdown values',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}