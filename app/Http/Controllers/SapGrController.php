<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class SapGrController extends Controller
{
    private $baseUrl = 'https://192.104.210.16:44320';
    private $username = 'OJTECHIT01';
    private $password = ''; 
    private $sapClient = '210';

    /**
     * Get Purchase Order details with GR
     * 
     * @param string $poNo Purchase Order Number
     * @return \Illuminate\Http\JsonResponse
     */
    public function getPurchaseOrder($poNo)
    {
        Log::info('=== GET PURCHASE ORDER START ===', [
            'po_no' => $poNo,
            'auth_user' => auth('sap')->user() ? auth('sap')->user()->user_id : 'not authenticated',
            'guard' => 'sap'
        ]);

        try {
            $url = "{$this->baseUrl}/sap/opu/odata4/sap/zmm_oji_po_bind/srvd/sap/zmm_oji_po/0001/ZPO_DTL_LIST(po_no='{$poNo}')/Set";

            Log::info('Making request to SAP', [
                'url' => $url,
                'username' => $this->username
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

            Log::info('SAP Response received', [
                'status' => $response->status(),
                'successful' => $response->successful()
            ]);

            if ($response->successful()) {
                Log::info('=== GET PURCHASE ORDER END (SUCCESS) ===');
                return response()->json([
                    'success' => true,
                    'data' => $response->json()
                ]);
            }

            Log::warning('SAP request failed', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch PO data',
                'error' => $response->body()
            ], $response->status());

        } catch (Exception $e) {
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
     * Create Good Receipt Entry
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function createGoodReceipt(Request $request)
    {
        Log::info('=== CREATE GOOD RECEIPT START ===', [
            'auth_user' => auth('sap')->user() ? auth('sap')->user()->user_id : 'not authenticated',
            'guard' => 'sap',
            'request_data' => $request->all()
        ]);

        // Validate input
        $validated = $request->validate([
            'dn_no' => 'required|string',
            'date_gr' => 'required|date_format:Y-m-d',
            'it_input' => 'required|array',
            'it_input.*.po_no' => 'required|string',
            'it_input.*.item_po' => 'required|string',
            'it_input.*.qty' => 'required|numeric',
            'it_input.*.plant' => 'required|string',
            'it_input.*.sloc' => 'required|string',
            'it_input.*.batch_no' => 'nullable|string'
        ]);

        try {
            $url = "{$this->baseUrl}/zapi/ZAPI/OJI_GR_ENTRY?sap-client={$this->sapClient}";

            Log::info('Fetching CSRF token from SAP');

            // Get CSRF token first
            $csrfResponse = Http::withBasicAuth($this->username, $this->password)
                ->withHeaders([
                    'x-csrf-token' => 'fetch',
                    'Accept' => 'application/json'
                ])
                ->withOptions([
                    'verify' => false
                ])
                ->get($url);

            $csrfToken = $csrfResponse->header('x-csrf-token');

            if (!$csrfToken) {
                Log::error('Failed to fetch CSRF token');
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to fetch CSRF token'
                ], 500);
            }

            Log::info('CSRF token obtained, creating GR');

            // Make POST request with CSRF token
            $response = Http::withBasicAuth($this->username, $this->password)
                ->withHeaders([
                    'x-csrf-token' => $csrfToken,
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json'
                ])
                ->withOptions([
                    'verify' => false
                ])
                ->post($url, $validated);

            Log::info('SAP GR Response received', [
                'status' => $response->status(),
                'successful' => $response->successful()
            ]);

            if ($response->successful()) {
                Log::info('=== CREATE GOOD RECEIPT END (SUCCESS) ===');
                return response()->json([
                    'success' => true,
                    'message' => 'Good Receipt created successfully',
                    'data' => $response->json()
                ]);
            }

            Log::warning('SAP GR request failed', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create Good Receipt',
                'error' => $response->body()
            ], $response->status());

        } catch (Exception $e) {
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

    /**
     * Get PO List (alternative method without parameters)
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getPurchaseOrderList(Request $request)
    {
        Log::info('=== GET PO LIST START ===', [
            'query_params' => $request->query(),
            'headers' => [
                'authorization' => $request->header('Authorization') ? 'Bearer ***' : 'missing',
                'content-type' => $request->header('Content-Type'),
                'accept' => $request->header('Accept'),
            ],
            'bearer_token' => $request->bearerToken() ? substr($request->bearerToken(), 0, 20) . '...' : null,
            'token_length' => $request->bearerToken() ? strlen($request->bearerToken()) : 0,
            'auth_guard' => config('auth.defaults.guard'),
            'sap_guard_driver' => config('auth.guards.sap.driver') ?? 'not configured',
            'sap_guard_provider' => config('auth.guards.sap.provider') ?? 'not configured',
            'sanctum_check' => auth('sanctum')->check(),
            'sap_check' => auth('sap')->check(),
            'user_from_sanctum' => auth('sanctum')->user(),
            'user_from_sap' => auth('sap')->user() ? auth('sap')->user()->user_id : null,
            'request_user' => $request->user() ? $request->user()->user_id : null,
        ]);

        try {
            $poNo = $request->query('po_no');

            if (!$poNo) {
                Log::warning('PO number not provided in request');
                return response()->json([
                    'success' => false,
                    'message' => 'PO number is required'
                ], 400);
            }

            Log::info('Forwarding to getPurchaseOrder', ['po_no' => $poNo]);
            return $this->getPurchaseOrder($poNo);

        } catch (Exception $e) {
            Log::error('Exception in getPurchaseOrderList', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error fetching PO list',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}