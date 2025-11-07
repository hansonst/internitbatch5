<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
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
        try {
            $url = "{$this->baseUrl}/sap/opu/odata4/sap/zmm_oji_po_bind/srvd/sap/zmm_oji_po/0001/ZPO_DTL_LIST(po_no='{$poNo}')/Set";

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

            if ($response->successful()) {
                return response()->json([
                    'success' => true,
                    'data' => $response->json()
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch PO data',
                'error' => $response->body()
            ], $response->status());

        } catch (Exception $e) {
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
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to fetch CSRF token'
                ], 500);
            }

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

            if ($response->successful()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Good Receipt created successfully',
                    'data' => $response->json()
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to create Good Receipt',
                'error' => $response->body()
            ], $response->status());

        } catch (Exception $e) {
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
        try {
            $poNo = $request->query('po_no');

            if (!$poNo) {
                return response()->json([
                    'success' => false,
                    'message' => 'PO number is required'
                ], 400);
            }

            return $this->getPurchaseOrder($poNo);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching PO list',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}