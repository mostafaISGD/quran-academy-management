<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ParentPortalView;
use Illuminate\Http\Request;

/**
 * صفحة ولي الأمر.
 *
 * المبدأ: الصفحة ترجع بيانات الأبناء المسجّلين عند ولي الأمر بس.
 * كل استعلام بيبدأ من `parents.user_id = المستخدم الحالي` — فمفيش
 * أي طريقة نعدّل الـ id ونشوف طالب تاني.
 *
 * ⭐ PR 4.5 نقل بناء الشكل لـ`ParentPortalView` عشان صفحة الابن
 * الواحدة (`ParentChildController`) تستخدم نفس القواعد بالظبط.
 */
class ParentPortalController extends Controller
{
    public function __construct(private readonly ParentPortalView $view) {}

    public function children(Request $request)
    {
        $parent = $this->view->parentFor($request->user());

        if (! $parent) {
            return response()->json([
                'message' => 'مفيش ملف ولي أمر مرتبط بالحساب ده',
            ], 404);
        }

        return response()->json($this->view->children($request->user(), $parent));
    }
}
