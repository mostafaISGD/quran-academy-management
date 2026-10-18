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

    /**
     * ⭐⭐⭐ تعليم إشعار كمقروء — بفحص الملكية.
     *
     * ⚠️ الكود كان:
     *
     *     public function markAsRead(Notification $notification)
     *     {
     *         $notification->update(['read_at' => now()]);
     *         return response()->json($notification);
     *     }
     *
     * مفيش أي فحص. الـ route model binding بيلاقي الإشعار بـ id
     * **أي حد** عنده — فأي مستخدم مسجّل (حتى ولي أمر أو معلم)
     * كان يقدر:
     *
     *   • يعلّم إشعار حد تاني كمقروء
     *   • وياخد محتوى الإشعار في الرد
     *
     * ⭐ والإشعار بيحمل `payload` فيه بيانات شخصية حقيقية —
     * «اشتراك ابنك خلص»، «فاتورة كذا متأخرة». فده كان تسريب
     * بيانات بين المستخدمين، مش مجرد تعديل حالة.
     *
     * ═══════════════════════════════════════════════════════════════
     * ليه 404 مش 403
     *
     * 403 بيقول «ده موجود بس مش بتاعك» — وبيكشف إن الـ id صحيح.
     * 404 بيقول «مفيش حاجة هنا» — وده الصح: من ناحية المستخدم
     * ده، إشعار حد تاني مش موجود أصلاً.
     *
     * @see \Tests\Feature\OwnershipTest
     */
    public function markAsRead(Request $request, Notification $notification)
    {
        // ⭐ لازم نشترط `user_id` مع `id` — `findOrFail` لوحدها
        // كانت هترجّع أي إشعار.
        abort_if(
            $notification->user_id !== $request->user()->id,
            404,
            'الإشعار غير موجود'
        );

        $notification->update(['read_at' => now()]);

        return response()->json($notification);
    }

    public function markAllAsRead(Request $request)
    {
        Notification::where('user_id', $request->user()->id)->whereNull('read_at')->update(['read_at' => now()]);
        return response()->json(['message' => 'تم تعليم الكل كمقروء']);
    }
}
