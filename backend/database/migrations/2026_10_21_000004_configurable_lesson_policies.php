<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐⭐⭐ **PR 3.2 — سياسات الحصص القابلة للضبط (صف لكل نتيجة × نطاق).**
 *
 * ══════════════════════════════════════════════════════════════
 * الجدول القديم: **صف واحد** لكل أكاديمية، وكل حالة عندها
 * عمود (student_absence_consumes_lesson، teacher_absence_... إلخ).
 *
 * الجدول الجديد: **صف لكل (نتيجة × نطاق)**:
 *
 *   outcome ∈ completed · student_absent · teacher_absent ·
 *              cancelled_by_academy · cancelled_late
 *   scope   ∈ global (للأكاديمية كلها) · program (لبرنامج واحد)
 *
 * والأعمدة: deduct_credit · pay_teacher · allow_makeup ·
 * makeup_limit · makeup_window_days · notify_parent ·
 * late_cancel_notice_hours (+ makeup_deducts_from_package —
 * سلوك قائم من PR أقدم محفوظ عشان ما يتكسّرش).
 *
 * ⚠️ **ليه إعادة تصميم؟** كل النتيجة الجديدة كانت هتحتاج عمودين
 * جدد، وكل نطاق (برنامج) كان هيحتاج **نسخة كاملة** من الأعمدة —
 * يعني الشكل بيكبر أفقياً بدل ما يكبر رأسياً. الشكل الجديد
 * صف = (نتيجة، نطاق) بياخد صفين جدد من غير تعديل جدول.
 *
 * ⚠️ **البيانات القديمة بتتحوّل** (مش بتضيع): كل صف قديم بيتحول
 * لـ5 صفوف — القيم القديمة بتتموضع على نتايجها (غياب الطالب ←
 * صف `student_absent`، وهكذا).
 *
 * ⭐ **وكمان**: عمود `lessons.policy_snapshot` (JSON) —
 * **لقطة** السياسة وقت التحويل، فبتعديل السياسة بعدين ما
 * بيغيّرش التاريخ (قبول المواصفة).
 */
return new class extends Migration
{
    /** @var array<int, string> */
    private const OUTCOMES = [
        'completed', 'student_absent', 'teacher_absent',
        'cancelled_by_academy', 'cancelled_late',
    ];

    public function up(): void
    {
        // ── ① قراءة الصف القديم (للتحويل) ────────────────────────
        $legacy = DB::table('lesson_policies')->get();

        // ── ② تسمية الجدول القديم وحفظه ─────────────────────────
        Schema::rename('lesson_policies', 'lesson_policies_legacy');

        // ── ③ الجدول الجديد ──────────────────────────────────────
        Schema::create('lesson_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // ⭐ النتيجة (الحالة النهائية للحصة).
            $table->string('outcome', 32);

            // ⭐ النطاق: `global` لكل الأكاديمية، `program` لبرنامج.
            $table->string('scope', 16)->default('global');
            $table->foreignId('program_id')->nullable()->constrained('programs')->cascadeOnDelete();

            // ⭐ القرار نفسه.
            $table->boolean('deduct_credit')->default(true);
            $table->boolean('pay_teacher')->default(true);
            $table->boolean('allow_makeup')->default(false);
            $table->integer('makeup_limit')->default(0);
            $table->integer('makeup_window_days')->default(0);
            $table->boolean('notify_parent')->default(true);
            $table->integer('late_cancel_notice_hours')->default(0);

            // ⭐ سلوك قائم (من قبل PR 3.2): التعويض بيخصم من رصيد
            // الباقة الأصلي ولا من رصيد خاص.
            $table->boolean('makeup_deducts_from_package')->default(true);

            $table->timestamps();

            $table->unique(['organization_id', 'outcome', 'scope', 'program_id'], 'lesson_policies_scope_unique');
        });

        // ── ④ تحويل الصفوف القديمة ──────────────────────────────
        foreach ($legacy as $row) {
            foreach ($this->rowsFromLegacy($row) as $new) {
                DB::table('lesson_policies')->insert(array_merge($new, [
                    'organization_id' => $row->organization_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            }
        }

        Schema::dropIfExists('lesson_policies_legacy');

        // ── ⑤ لقطة السياسة على الحصة ────────────────────────────
        Schema::table('lessons', function (Blueprint $table) {
            $table->json('policy_snapshot')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->dropColumn('policy_snapshot');
        });

        // ⭐ رجوع للجدول القديم: نأخذ أول صف لكل مؤسسة (الصف
        // العام) ونرجّعه بالأعمدة القديمة. ⚠️ الصفوف الخاصة
        // بالبرامج (**نطاق program**) مالها مكان في الشكل القديم
        // — بتضيع بالرجوع (مقصود: الشكل القديم ما كانش بيعرف
        // النطاق).
        $rows = DB::table('lesson_policies')->where('scope', 'global')->get();

        Schema::rename('lesson_policies', 'lesson_policies_new');

        Schema::create('lesson_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->boolean('student_absence_consumes_lesson')->default(false);
            $table->boolean('student_absence_teacher_paid')->default(false);
            $table->boolean('teacher_absence_consumes_lesson')->default(false);
            $table->boolean('teacher_absence_teacher_paid')->default(false);
            $table->boolean('makeup_consumes_lesson')->default(true);
            $table->boolean('makeup_teacher_paid')->default(true);
            $table->integer('max_makeup_per_month')->default(4);
            $table->integer('max_makeup_days')->default(30);
            $table->boolean('makeup_deducts_from_package')->default(true);
            $table->timestamps();
            $table->unique('organization_id');
        });

        foreach ($rows as $row) {
            DB::table('lesson_policies')->insert([
                'organization_id' => $row->organization_id,
                'student_absence_consumes_lesson' => $row->outcome === 'student_absent' ? (int) $row->deduct_credit : 0,
                'student_absence_teacher_paid' => $row->outcome === 'student_absent' ? (int) $row->pay_teacher : 0,
                'teacher_absence_consumes_lesson' => $row->outcome === 'teacher_absent' ? (int) $row->deduct_credit : 0,
                'teacher_absence_teacher_paid' => $row->outcome === 'teacher_absent' ? (int) $row->pay_teacher : 0,
                'makeup_consumes_lesson' => (int) $row->allow_makeup,
                'makeup_teacher_paid' => 1,
                'max_makeup_per_month' => $row->makeup_limit,
                'max_makeup_days' => $row->makeup_window_days,
                'makeup_deducts_from_package' => (int) $row->makeup_deducts_from_package,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::dropIfExists('lesson_policies_new');
    }

    /**
     * ⭐ تحويل صف قديم (بأعمدة ثابتة) لـ5 صفوف جديدة.
     *
     * @return array<int, array<string, mixed>>
     */
    private function rowsFromLegacy(object $row): array
    {
        $shared = [
            'outcome' => 'completed',
            'scope' => 'global',
            'program_id' => null,
            'pay_teacher' => 1,
            'allow_makeup' => 0,
            'makeup_limit' => 0,
            'makeup_window_days' => 0,
            'notify_parent' => 1,
            'late_cancel_notice_hours' => 0,
            'makeup_deducts_from_package' => (int) $row->makeup_deducts_from_package,
        ];

        return [
            array_merge($shared, ['outcome' => 'completed', 'deduct_credit' => 1]),
            array_merge($shared, [
                'outcome' => 'student_absent',
                'deduct_credit' => (int) $row->student_absence_consumes_lesson,
                'pay_teacher' => (int) $row->student_absence_teacher_paid,
                'allow_makeup' => 1,
                'makeup_limit' => (int) $row->max_makeup_per_month,
                'makeup_window_days' => (int) $row->max_makeup_days,
            ]),
            array_merge($shared, [
                'outcome' => 'teacher_absent',
                'deduct_credit' => (int) $row->teacher_absence_consumes_lesson,
                'pay_teacher' => (int) $row->teacher_absence_teacher_paid,
                'allow_makeup' => 1,
                'makeup_limit' => (int) $row->max_makeup_per_month,
                'makeup_window_days' => (int) $row->max_makeup_days,
            ]),
            array_merge($shared, [
                'outcome' => 'cancelled_by_academy',
                'deduct_credit' => 0,
                'pay_teacher' => 0,
                'allow_makeup' => 1,
                'makeup_limit' => (int) $row->max_makeup_per_month,
                'makeup_window_days' => (int) $row->max_makeup_days,
                'late_cancel_notice_hours' => 24,
            ]),
            array_merge($shared, [
                'outcome' => 'cancelled_late',
                'deduct_credit' => 0,
                'pay_teacher' => 0,
                'allow_makeup' => 0,
                'late_cancel_notice_hours' => 0,
            ]),
        ];
    }
};
