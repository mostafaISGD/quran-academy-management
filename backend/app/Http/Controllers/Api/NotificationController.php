<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $query = Notification::query()->where('user_id', $request->user()->id)
            ->when($request->filled('unread'), fn ($q) => $q->whereNull('read_at'))
            ->orderByDesc('created_at');

        $countsQuery = Notification::query()->where('user_id', $request->user()->id)
            ->when($request->filled('unread'), fn ($q) => $q->whereNull('read_at'));

        return $this->paginatedWithCounts($query, $countsQuery, $request, ['event_type', 'channel']);
    }

    public function markAsRead(Notification $notification)
    {
        $notification->update(['read_at' => now()]);
        return response()->json($notification);
    }

    public function markAllAsRead(Request $request)
    {
        Notification::where('user_id', $request->user()->id)->whereNull('read_at')->update(['read_at' => now()]);
        return response()->json(['message' => 'تم تعليم الكل كمقروء']);
    }
}
