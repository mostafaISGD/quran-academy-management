<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Program;
use Illuminate\Http\Request;

class ProgramController extends Controller
{
    public function index(Request $request)
    {
        $query = Program::query()->with('levels')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')));

        $countsQuery = Program::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')));

        return $this->paginatedWithCounts($query, $countsQuery, $request, ['status']);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string',
            'slug' => 'required|string',
            'description' => 'nullable|string',
            'status' => 'nullable|in:active,inactive',
        ]);
        $data['organization_id'] = $request->user()->organization_id;
        return response()->json(Program::create($data), 201);
    }

    public function show(Program $program)
    {
        return response()->json($program->load(['levels', 'subscriptionPlans']));
    }

    public function update(Request $request, Program $program)
    {
        $data = $request->validate([
            'name' => 'sometimes|string',
            'description' => 'nullable|string',
            'status' => 'sometimes|in:active,inactive',
        ]);
        $program->update($data);
        return response()->json($program->fresh());
    }

    public function destroy(Program $program)
    {
        $program->delete();
        return response()->json(['message' => 'تم حذف البرنامج']);
    }
}
