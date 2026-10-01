<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        $query = AuditLog::query()->with('user')
            ->when($request->filled('entity_type'), fn ($q) => $q->where('entity_type', $request->string('entity_type')))
            ->when($request->filled('entity_id'), fn ($q) => $q->where('entity_id', $request->string('entity_id')))
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->string('user_id')))
            ->when($request->filled('action'), fn ($q) => $q->where('action', $request->string('action')))
            ->orderByDesc('created_at');

        $countsQuery = AuditLog::query()
            ->when($request->filled('entity_type'), fn ($q) => $q->where('entity_type', $request->string('entity_type')))
            ->when($request->filled('entity_id'), fn ($q) => $q->where('entity_id', $request->string('entity_id')))
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->string('user_id')))
            ->when($request->filled('action'), fn ($q) => $q->where('action', $request->string('action')));

        return $this->paginatedWithCounts($query, $countsQuery, $request, ['action', 'entity_type']);
    }
}
