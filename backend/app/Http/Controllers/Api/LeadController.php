<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use Illuminate\Http\Request;

class LeadController extends Controller
{
    public function index(Request $request)
    {
        $query = Lead::query()->with(['program', 'assignedStaff'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('source'), fn ($q) => $q->where('source', $request->string('source')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = $request->string('search');
                $q->where(fn ($sq) => $sq->where('full_name', 'like', "%{$s}%")
                    ->orWhere('phone', 'like', "%{$s}%")
                    ->orWhere('email', 'like', "%{$s}%"));
            })
            ->orderByDesc('created_at');

        $countsQuery = Lead::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('source'), fn ($q) => $q->where('source', $request->string('source')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = $request->string('search');
                $q->where(fn ($sq) => $sq->where('full_name', 'like', "%{$s}%")
                    ->orWhere('phone', 'like', "%{$s}%")
                    ->orWhere('email', 'like', "%{$s}%"));
            });

        return $this->paginatedWithCounts($query, $countsQuery, $request, ['status', 'source']);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'full_name' => 'required|string',
            'phone' => 'required|string',
            'email' => 'nullable|email',
            'country_code' => 'nullable|string|max:5',
            'student_age' => 'nullable|integer|min:1|max:120',
            'interested_program_id' => 'nullable|exists:programs,id',
            'source' => 'nullable|in:facebook,instagram,website,referral,other',
            'assigned_staff_id' => 'nullable|exists:users,id',
            'notes' => 'nullable|string',
        ]);
        $data['organization_id'] = $request->user()->organization_id;
        return response()->json(Lead::create($data), 201);
    }

    public function show(Lead $lead)
    {
        return response()->json($lead->load(['program', 'assignedStaff', 'assessments']));
    }

    public function update(Request $request, Lead $lead)
    {
        $data = $request->validate([
            'full_name' => 'sometimes|string',
            'phone' => 'sometimes|string',
            'email' => 'nullable|email',
            'country_code' => 'nullable|string|max:5',
            'student_age' => 'nullable|integer|min:1|max:120',
            'interested_program_id' => 'nullable|exists:programs,id',
            'source' => 'nullable|in:facebook,instagram,website,referral,other',
            'assigned_staff_id' => 'nullable|exists:users,id',
            'notes' => 'nullable|string',
        ]);
        $lead->update($data);
        return response()->json($lead->fresh());
    }

    public function updateStatus(Request $request, Lead $lead)
    {
        $data = $request->validate(['status' => 'required|in:new,contacted,qualified,trial_booked,trial_completed,offer_sent,converted,lost']);
        $lead->update(['status' => $data['status']]);
        return response()->json($lead->fresh());
    }

    public function destroy(Lead $lead)
    {
        $lead->delete();
        return response()->json(['message' => 'تم حذف الـ Lead']);
    }
}
