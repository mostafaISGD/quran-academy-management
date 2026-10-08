<?php

namespace Database\Seeders;

use App\Models\GroupClass;
use App\Models\SubscriptionPlan;
use App\Models\WaitingListEntry;
use Illuminate\Database\Seeder;

/**
 * ⭐ طلبات **قائمة الانتظار المستقلة** — `/waitlist`.
 *
 * ⭐ ليه seeder لوحده؟
 *
 * الطلبات دي كانت بتتعمل يدوي من الواجهة كل مرة، فمفيش حاجة
 * في الريبو ترجّعها لو الجدول اتفضّى. الـ seeder ده بيخلي
 * أي حد يقدر يرجّعها في ثانية:
 *
 * ```
 * php artisan db:seed --class=WaitingListSeeder
 * ```
 *
 * ⭐ الطلبات **مش مربوطة بمجموعة** — وده مقصود. الطلب المستقل
 * معناه إننا لسه بن deciding: هيتبعت لمجموعة موجودة ولّا
 * هنفتح مجموعة جديدة؟ عشان كمان `proposed_group_id` في
 * ناس و `group_class_id` مش متربط خالص.
 */
class WaitingListSeeder extends Seeder
{
    public function run(): void
    {
        $orgId = 1;

        $groups = GroupClass::where('organization_id', $orgId)
            ->orderBy('id')
            ->pluck('id', 'name');

        if ($groups->isEmpty()) {
            $this->command?->warn('مفيش مجموعات — سيبنا الانتظار فاضي. شغّل seeder تاني بعد ما تعمل مجموعات.');

            return;
        }

        $groupIds = $groups->values()->all();

        // ⭐ الباقات من **البرامج** — عشان «الباقة المطلوبة»
        // تبقى باقة حقيقية موجودة، مش رقم وهمي.
        $plans = SubscriptionPlan::where('status', 'active')
            ->orderBy('id')
            ->pluck('id')
            ->take(4)
            ->all();

        /**
         * ⭐ الأسماء أصول وبنت على مستوى المستوى — عشان لما بنفتح
         * مجموعة جديدة نقدر نفلتر «بنفس المستوى» ونتوقع لاقية ناس.
         *
         * `group` = التلاتة الأولى مجموعة certain، والباقي
         * «لسه بنفكر» (مفيش مجموعة ولا مقترح).
         */
        $rows = [
            [
                'name' => 'مريم عبد الله',
                'phone' => '01011223344',
                'parent_phone' => '01055667788',
                'current_level' => 'متقن الحفظ، بيحفظ البقرة',
                'package' => 0,
                'group' => 0,
                'notes' => 'معاها أختها الصغرى، ممكن يدخلوا مع بعض',
            ],
            [
                'name' => 'يوسف إبراهيم',
                'phone' => '01022334455',
                'parent_phone' => '01066778899',
                'current_level' => 'متقن الجزء الخامس',
                'package' => 1,
                'group' => 0,
                'notes' => 'بفلتر عليه بعد المغرب',
            ],
            [
                'name' => 'خالد مصطفى',
                'phone' => '01033445566',
                'parent_phone' => null,
                'current_level' => 'مبتدئ تمامًا — لسه بيبدأ',
                'package' => 0,
                'group' => 1,
                'notes' => null,
            ],
            [
                'name' => 'سارة محمود',
                'phone' => '01044556677',
                'parent_phone' => '01077889900',
                'current_level' => 'حفظ وتحفيظ',
                'package' => 2,
                'group' => null,
                'notes' => 'الأهالي عايزين يبدأوا الشهر الجاي',
            ],
            [
                'name' => 'عمر طارق',
                'phone' => '01055667788',
                'parent_phone' => '01088990011',
                'current_level' => 'متقن التلاوة مع مراعاة أحكام التجويد',
                'package' => 1,
                'group' => null,
                'notes' => 'أهله طلبوا يبدأ من شهر الجاي',
            ],
            [
                'name' => 'محمد أنس',
                'phone' => '01066778899',
                'parent_phone' => '01099001122',
                'current_level' => 'يحفظ من جزء واحد',
                'package' => 3,
                'group' => 1,
                'notes' => null,
            ],
            [
                'name' => 'فاطمة الزهراء',
                'phone' => '01077889900',
                'parent_phone' => null,
                'current_level' => 'متقن الحفظ — بيحفظ من سنين',
                'package' => 0,
                'group' => 2,
                'notes' => 'أهلها طلبوا حصة بزيادة',
            ],
            [
                'name' => 'عبد الرحمن علي',
                'phone' => '01088990011',
                'parent_phone' => '01000112233',
                'current_level' => 'مبتدئ',
                'package' => null,
                'group' => null,
                'notes' => 'مش متأكد من المستوى لسه',
            ],
            [
                'name' => 'نور حسن',
                'phone' => '01099001122',
                'parent_phone' => '01011223344',
                'current_level' => 'متقن الجزء الثالث',
                'package' => 2,
                'group' => 2,
                'notes' => null,
            ],
            [
                'name' => 'زياد صلاح',
                'phone' => '01100112233',
                'parent_phone' => null,
                'current_level' => 'حفظ فقط — مش عايز تجويد',
                'package' => null,
                'group' => null,
                'notes' => 'الأهالي بيسألوا على السعر',
            ],
            [
                'name' => 'حبيبة سامي',
                'phone' => '01111223344',
                'parent_phone' => '01122334455',
                'current_level' => 'متقن الحفظ',
                'package' => 1,
                'group' => 1,
                'notes' => null,
            ],
            [
                'name' => 'إياد رامي',
                'phone' => '01122334455',
                'parent_phone' => '01133445566',
                'current_level' => 'يحفظ البقرة والباقرتين',
                'package' => 3,
                'group' => null,
                'notes' => 'اتكلم مع أهله إنه عنده وقت فراغ',
            ],
        ];

        // ⚠️ تنظيف: الـ seeder ممكن يتشغّل أكتر من مرة
        WaitingListEntry::where('organization_id', $orgId)->delete();

        $start = now()->subDays(count($rows));

        foreach ($rows as $i => $row) {
            // ⚠️ `updateOrCreate` على الموبايل مش `create` — الـ seeder
            // ممكن يتشغّل أكتر من مرة، و`create` كان هيرمي
            // exception على القيد الفريد وبيوقّف الـ seeder كله.
            WaitingListEntry::updateOrCreate(
                ['phone' => $row['phone']],
                [
                    'organization_id' => $orgId,
                    'name' => $row['name'],
                    'parent_phone' => $row['parent_phone'],
                    'current_level' => $row['current_level'],
                    'package_id' => $plans[$row['package']] ?? null,
                    'group_class_id' => $row['group'] === null ? null : $groupIds[$row['group']] ?? null,
                    'proposed_group_id' => null,
                    'status' => 'waiting',
                    'notes' => $row['notes'],
                    // ⭐ كل واحد بعد التاني بساعة — عشان «الترتيب»
                    // في الصفحة يبقى مقروء ومتحقق منه
                    'entered_at' => $start->copy()->addHours($i),
                ],
            );
        }

        $count = WaitingListEntry::where('organization_id', $orgId)->count();

        $this->command?->info("اتعمل {$count} طلب انتظار.");
    }
}