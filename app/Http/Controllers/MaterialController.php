<?php

namespace App\Http\Controllers;

use App\Models\Material;
use Illuminate\Http\Request;

class MaterialController extends Controller
{
    public function index()
    {
        try {
            $materials = Material::select([
                'id_mat',
                'material_code',
                'material_desc',
                'material_type',
                'material_group',
                'material_uom'
            ])->get();
            
            return response()->json($materials);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch materials',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $material = Material::findOrFail($id);
            return response()->json($material);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Material not found',
                'error' => $e->getMessage()
            ], 404);
        }
    }
}