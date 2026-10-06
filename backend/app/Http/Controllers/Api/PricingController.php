<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionPlan;
use Illuminate\Http\Request;

/**
 * شاشة الأسعار — الباقات الـ ٢٨.
 *
 * ⭐ الباقات **مشتركة** بين البرامج (مربوطة بمدة الحصة وعدد
 * الحصص، مش بالبرنامج). فالصفحة دي بتعرض الكل في ٣ جداول:
 * تقليدي / ذهبي / مجموعات.
 *
 * العرض عام (مفيش `permission` middleware) — الأسعار حاجة
 * الأولاد بيسألوا عنها. التعديل (لو في لوحة admin بعدين) هيتحمي.
 */
class PricingController extends Controller
{
    /**
     * كل الباقات النشطة، مقسّمة بالفئة.
     *
     * ⚠️ الباقات اللي `program_id = null` — دي المشتركة. اللي
     * ليها برنامج (قديمة) مش بتظهر هنا، دي داتا تجريبية
     * بـ program_id.
     */
    public function index(Request $request)
    {
        $plans = SubscriptionPlan::query()
            ->where('status', 'active')
            ->whereNull('program_id')
            ->displayOrder()
            ->get();

        // مقسّمة بالفئة — الشكل اللي الشاشة عايزاه
        $grouped = [];
        foreach (SubscriptionPlan::CATEGORY_ORDER as $category) {
            $rows = $plans->where('category', $category)->values();

            if ($rows->isEmpty()) {
                continue;
            }

            $grouped[] = [
                'category' => $category,
                'label' => SubscriptionPlan::categoryLabel($category),
                // ⭐ المجموعة بتوصف نفسها — سعرها مخفّض لأنها جماعية
                'description' => $category === 'group'
                    ? 'حصة جماعية — سعر مخفّض'
                    : null,
                'plans' => $rows->map(fn ($p) => $this->present($p))->all(),
            ];
        }

        // المدد المتاحة — للشريط فوق
        $durations = $plans->pluck('lesson_duration_minutes')
            ->unique()->sort()->values()->all();

        return response()->json([
            'groups' => $grouped,
            'durations' => $durations,
            'counts' => [
                'lessons' => $plans->pluck('lessons_count')->unique()->sort()->values()->all(),
                'total' => $plans->count(),
            ],
        ]);
    }

    /**
     * جدول الأسعار كـ CSV — للأهل اللي بيحبوا يفتحوه في Excel.
     *
     * ⭐ الطلب كان «السعر الإجمالي بس» — فمفيش تقسيم للحصة.
     * السطر: الفئة، المدة، الحصص، السعر.
     */
    public function export(Request $request)
    {
        $plans = SubscriptionPlan::query()
            ->where('status', 'active')
            ->whereNull('program_id')
            ->displayOrder()
            ->get();

        $rows = [['الفئة', 'مدة الحصة (دقيقة)', 'عدد الحصص', 'السعر (ج.م)']];

        foreach ($plans as $p) {
            $rows[] = [
                $p->category_label,
                $p->lesson_duration_minutes,
                $p->lessons_count,
                number_format((float) $p->price, 2, '.', ''),
            ];
        }

        $csv = "\xEF\xBB\xBF"; // BOM — عشان Excel يقرأ العربي صح
        foreach ($rows as $row) {
            $csv .= implode(',', array_map(
                fn ($v) => '"'.str_replace('"', '""', (string) $v).'"',
                $row
            ))."\r\n";
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="prices.csv"',
        ]);
    }

    private function present(SubscriptionPlan $p): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'category' => $p->category,
            'category_label' => $p->category_label,
            'lesson_duration_minutes' => $p->lesson_duration_minutes,
            'lessons_count' => $p->lessons_count,
            // ⭐ الإجمالي بس — الـ ٣٥٠ ج هي سعر الـ ٨ حصص كلها
            'price' => (float) $p->price,
            'currency' => $p->currency,
            'description' => $p->description,
            'is_group' => $p->isGroup(),
        ];
    }
}