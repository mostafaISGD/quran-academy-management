<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FinanceAlert;
use Illuminate\Http\Request;

/**
 * PR 2.9 — تنبيهات التسوية المالية.
 *
 * القراءة بعلم المالية (`finance.payments.view`) — الإغلاق
 * بصلاحية أعمق (`finance.payments.update`) والكلاوسير يدوي:
 * التسوية اليومية مش بتغلق تنبيه أبداً.
 */
class FinanceAlertController extends Controller
{
    /** المفتوحة افتراضياً — `?resolved=1` للمغلقة */
    public function index(Request $request)
    {
        $query = FinanceAlert::query()
            ->where('organization_id', $request->user()->organization_id)
            ->orderByDesc('id')
            ->limit(200);

        if ($request->boolean('resolved')) {
            $query->whereNotNull('resolved_at');
        } else {
            $query->open();
        }

        return response()->json($query->get());
    }

    public function resolve(Request $request, FinanceAlert $alert)
    {
        if ($alert->resolved_at !== null) {
            return response()->json([
                'message' => 'التنبيه مقفول من قبل',
                'data' => $alert,
            ]);
        }

        $alert->update([
            'resolved_at' => now(),
            'resolved_by' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'تم إغلاق التنبيه',
            'data' => $alert->fresh(),
        ]);
    }
}
