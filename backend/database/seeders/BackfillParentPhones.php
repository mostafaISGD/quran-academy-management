<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ربط البيانات القديمة بأرقام الهاتف.
 *
 * البيانات اللي كانت موجودة قبل نظام "أرقام الهاتف" بتخزّن:
 *   - pages في جدول parents
 *   - ربط في جدول student_parents
 *
 * بدون ربط لـ student_phones، يعني نظام الـ ParentLinker الجديد
 * مش شايفهم — فالمستخدم بيحس إن الولي اختفى من ملف الطالب.
 *
 * Seeder ده بيعمل backfill: يربط كل ولي أمر برقمه في student_phones.
 *
 * التشغيل: php artisan db:seed --class=BackfillParentPhones
 */
class BackfillParentPhones extends Seeder
{
    public function run(): void
    {
        $linked = 0;
        $skipped = 0;
        $orphaned = 0;

        // نجيب كل رابط في student_parents
        $links = DB::table('student_parents')
            ->select('student_id', 'parent_id', 'relationship', 'is_primary')
            ->get();

        foreach ($links as $link) {
            $parent = DB::table('parents')->where('id', $link->parent_id)->first();

            if (!$parent) {
                $orphaned++;
                continue;
            }

            // هل الرقم ده مسجل بالفعل كرقم ولي أمر للطالب؟
            $exists = DB::table('student_phones')
                ->where('student_id', $link->student_id)
                ->where('phone_number', $parent->phone)
                ->exists();

            if ($exists) {
                // موجود — نحدّث عليه البيانات بدل ما نضيف رقم مكرر
                DB::table('student_phones')
                    ->where('student_id', $link->student_id)
                    ->where('phone_number', $parent->phone)
                    ->update([
                        'is_parent' => true,
                        'parent_name' => $parent->name,
                        'parent_relationship' => $link->relationship,
                        'is_primary' => (bool) $link->is_primary,
                    ]);
                $linked++;
                continue;
            }

            // إضافة الرقم كرقم ولي أمر
            $hasAnyPhone = DB::table('student_phones')
                ->where('student_id', $link->student_id)
                ->exists();

            DB::table('student_phones')->insert([
                'student_id' => $link->student_id,
                'phone_number' => $parent->phone,
                'is_personal' => false,
                'is_parent' => true,
                'is_whatsapp' => true,
                'is_call' => true,
                'is_primary' => false, // مش أساسي إلا لو مفيش أرقام تانية
                'parent_name' => $parent->name,
                'parent_relationship' => $link->relationship,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // لو الطالب مافيشش أرقام تانية، خليه الأساسي
            if (!$hasAnyPhone) {
                DB::table('student_phones')
                    ->where('student_id', $link->student_id)
                    ->update(['is_primary' => true]);
            }

            $linked++;
        }

        $this->command?->info("✅ تم ربط {$linked} رقم لولي أمر");
        if ($orphaned) {
            $this->command?->warn("⚠️  {$orphaned} رابط بــ parent_id مفقود — محتاج مراجعة");
        }
        if ($skipped) {
            $this->command?->info("   تم تخطي {$skipped}");
        }
    }
}
