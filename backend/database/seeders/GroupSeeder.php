<?php

namespace Database\Seeders;

use App\Models\GroupClass;
use App\Models\GroupMember;
use App\Models\Program;
use App\Models\Teacher;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ المجموعات الأونلاين وأعضاؤها.
 *
 * ⭐ **ليه ده موجود أصلاً؟**
 *
 * المجموعات كانت بتتعمل **بإيد واحدة** من الواجهة كل مرة. يعني
 * `migrate:fresh` بيضيّعها، وأي حد هينسخ المشروع مش هيعرف
 * شكل البيانات المتوقعة.
 *
 * ⭐ السعات **متنوعة عن قصد** — كل مجموعة بتختبر حالة مختلفة:
 *   • `12` — فيها دايمًا مكان
 *   • `10` — بتخلص أحيانًا
 *   • `8`  — متوسطة
 *   • `null` — «مفيش حد أقصى»
 *   • `6`  — صغيرة ومتل Fuller
 *
 * لو حطّينا رقم واحد افتراضي، نص الاختبارات والمعاينات هتبقى
 * مضلّلة. كل مجموعة لازم تحدّد سعتها بنفسها.
 */
class GroupSeeder extends Seeder
{
    public function run(): void
    {
        $orgId = 1;

        // ⚠️ التنضيف — عشان يتشغّل أكتر من مرة
        DB::table('group_members')->delete();
        DB::table('waiting_list_entries')->delete();
        DB::table('group_classes')->delete();

        $programs = Program::orderBy('id')->get();
        $teachers = Teacher::orderBy('id')->get();

        if ($programs->isEmpty()) {
            $this->command?->warn('مفيش برامج — شغّل RealisticDataSeeder الأول.');

            return;
        }

        /**
         * ⭐ الأسماء زي ما الإدارة بتسميّيها فعليًا — لأنها اللي
         * واجهة المجموعات بتعرضها، فأي تغيير عليها بيخلي أي
         * مقارنة بمرجع قديم تبان غلط.
         */
        $rows = [
            [
                'name' => 'مجموعة التسميع الثلاثي',
                'program' => 'تسميع ثلاثي',
                'capacity' => 3,
                'weekday' => 0, 'start_time' => '18:00', 'end_time' => '19:00',
                'description' => 'تسميع ثلاثي الأجزاء — مصغّرة',
                'members' => 3,
                'waiting' => 2,
            ],
            [
                'name' => 'مجموعة التجويد الصوتي',
                'program' => 'تجويد',
                'capacity' => 10,
                'weekday' => 2, 'start_time' => '17:00', 'end_time' => '18:00',
                'description' => 'أحكام التجويد —Middle level',
                'members' => 9,
                'waiting' => 3,
            ],
            [
                'name' => 'مجموعة التلاوة للمبتدئين',
                'program' => 'تلاوة وتدبر',
                'capacity' => 8,
                'weekday' => 4, 'start_time' => '16:00', 'end_time' => '17:00',
                'description' => null,
                'members' => 3,
                'waiting' => 2,
            ],
            [
                'name' => 'مجموعة التحفيظ المسائي',
                'program' => 'تحفيظ القرآن',
                'capacity' => 12,
                'weekday' => 4, 'start_time' => '19:00', 'end_time' => '20:00',
                'description' => null,
                'members' => 4,
                'waiting' => 2,
            ],
            [
                'name' => 'مجموعة المراجعة المكثفة',
                'program' => 'تحفيظ القرآن',
                'capacity' => null, // ⭐ «مفيش حد أقصى»
                'weekday' => 6, 'start_time' => '10:00', 'end_time' => '12:00',
                'description' => 'مراجعة مكثفة قبل الامتحانات',
                'members' => 4,
                'waiting' => 0,
            ],
        ];

        $totalMembers = 0;

        foreach ($rows as $i => $row) {
            $program = $programs->firstWhere('name', $row['program'])
                ?? $programs[$i % $programs->count()];

            $group = GroupClass::create([
                'organization_id' => $orgId,
                'program_id' => $program->id,
                'teacher_id' => $teachers->isNotEmpty()
                    ? $teachers[$i % $teachers->count()]->id
                    : null,
                'name' => $row['name'],
                'capacity' => $row['capacity'],
                'weekday' => $row['weekday'],
                'start_time' => $row['start_time'],
                'end_time' => $row['end_time'],
                'description' => $row['description'],
                'status' => 'active',
                'sort_order' => $i,
                'meeting_provider' => 'zoom',
            ]);

            // ===== الأعضاء =====
            // ⭐ بنجيب طلاب **بترتيب ثابت** (الأقدم) عشان النتيجة
            // واحدة كل مرة. الاختبارات الماشية على الأرقام دي
            // لازم تشوف نفس الأرقام.
            $students = DB::table('students')
                ->where('organization_id', $orgId)
                ->where('status', 'active')
                ->orderBy('id')
                ->limit($row['members'])
                ->offset($totalMembers)
                ->get();

            $joinedAt = now()->subMonths(2)->startOfDay();

            foreach ($students as $s) {
                GroupMember::create([
                    'group_class_id' => $group->id,
                    'student_id' => $s->id,
                    'source' => 'manual',
                    'status' => 'active',
                    'joined_at' => $joinedAt->copy()->addMinutes($totalMembers * 3),
                    'created_at' => $joinedAt,
                    'updated_at' => $joinedAt,
                ]);
            }

            $totalMembers += $students->count();
        }

        $this->command?->info('اتعمل '.count($rows).' مجموعة · '.$totalMembers.' عضو');
    }
}