<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ParentModel;
use Illuminate\Http\Request;

class ParentController extends Controller
{
    public function index()
    {
        try {
            $parents = ParentModel::with(['students' => function ($query) {
                $query->select('students.id', 'students.first_name', 'students.last_name');
            }])->get();

            return response()->json(['data' => $parents]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $data = $request->validate([
                'name' => 'required|string',
                'phone' => 'required|string',
                'email' => 'nullable|email',
                'country_code' => 'nullable|string',
                'relationship' => 'nullable|string',
                'student_id' => 'nullable|exists:students,id',
            ]);

            $data['organization_id'] = $request->user()->organization_id;
            $data['status'] = 'active';

            $parent = ParentModel::create($data);

            if (!empty($data['student_id'])) {
                \Illuminate\Support\Facades\DB::table('student_parents')->insert([
                    'student_id' => $data['student_id'],
                    'parent_id' => $parent->id,
                    'relationship' => $data['relationship'] ?? 'father',
                    'is_primary' => true,
                    'can_manage' => true,
                    'can_pay' => true,
                    'can_receive_notifications' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return response()->json($parent, 201);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
