<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\AuditLogService;
use Illuminate\Http\Request;

/**
 * سجل النشاط العام (شاشة «سجل العمليات»).
 *
 * الجدول كان فاضي من كود حقيقي — الـ AuditLogService هو اللي بيملاه
 * دلوقتي من الـ controllers.
 */
class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        $query = AuditLog::query()
            ->with('user')
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->integer('user_id')))
            ->when($request->filled('action'), fn ($q) => $q->where('action', $request->string('action')))
            ->when($request->filled('entity_type'), fn ($q) => $q->where('entity_type', $request->string('entity_type')))
            ->when($request->filled('entity_id'), fn ($q) => $q->where('entity_id', $request->integer('entity_id')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = $request->string('search');
                $q->where(function ($sq) use ($s) {
                    $sq->where('entity_type', 'like', "%{$s}%")
                        ->orWhere('action', 'like', "%{$s}%")
                        ->orWhere('ip_address', 'like', "%{$s}%");
                });
            })
            ->orderByDesc('created_at');

        $paginator = $query->paginate($request->integer('per_page') ?: 50);

        $response = $paginator->toArray();

        // إحصائيات من الداتابيز — مش من الصفحة الحالية
        $base = AuditLog::query()
            ->when($request->filled('entity_type'), fn ($q) => $q->where('entity_type', $request->string('entity_type')));

        $response['counts'] = [
            'total' => (clone $base)->count(),
            'create' => (clone $base)->where('action', 'create')->count(),
            'update' => (clone $base)->where('action', 'update')->count(),
            'delete' => (clone $base)->where('action', 'delete')->count(),
            'today' => (clone $base)->whereDate('created_at', today())->count(),
        ];

        $response['entity_types'] = AuditLog::query()
            ->select('entity_type')
            ->distinct()
            ->orderBy('entity_type')
            ->pluck('entity_type');

        return response()->json($response);
    }
}
