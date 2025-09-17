<?php

namespace App\Http\Controllers;

use App\Models\Machine;
use Illuminate\Http\Request;

class MachineController extends Controller
{
    public function index()
    {
        try {
            $machines = Machine::select([
                'id_machine',
                'machine_code',
                'machine_name'
            ])->get();
            
            return response()->json($machines);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch machines',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $machine = Machine::findOrFail($id);
            return response()->json($machine);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Machine not found',
                'error' => $e->getMessage()
            ], 404);
        }
    }
}