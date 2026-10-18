<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ PR 1.11 — حالة الرابط في `student_parents`.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐ ليه العمود ده؟
 *
 * الكود كان بيربط الطالب بولي الأمر **تلقائياً** لو الرقم موجود
 * في `parents`. يعني: أي حد يدخل رقم أم لمي في صفحة «أرقام
 * طالب»، يصير أبوه على طول — وبياخد `can_manage` و`can_pay`.
 *
 * ⭐ والرقم **مش** هوية: أرقام الشقق في مصر بتتكرر، وناس كتير
 * بتشارك رقم البيت. فالربط التلقائي مش دقيق — لازم تأكيد من
 * الموظف اللي إدخال البيانات (هو شايف الاسم الحقيقي قدام عينه).
 *
 * ⭐ `pending_verification` = «اتسجل، مستني التأكيد».
 * `verified` = «الموظف أكّد إن ده نفس الشخص».
 *
 * ⚠️ **القيمة الافتراضية `pending_verification`** — أي كود جديد
 * بيربط لازم يقرّر بحكمه: `link()` بتبقى معلّقة، والموظف بيوثّق.
 *
 * ⭐ **البيانات الموجودة:** كل الروابط القديمة كانت **تلقائية**
 * (نفس الكود القديم)، بس البيانات دي **أُدخلت من شاشات الموظف**
 * — فبنعتبرها مؤكدة بأثر رجعي. الروابط التلقائية الجديدة من
 * النهاردة هي اللي محتاجة تأكيد.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_parents', function (Blueprint $table) {
            $table->string('status', 30)
                ->default('pending_verification')
                ->after('relationship')
                ->index();

            // ⭐ مين أكّد ومتى — سجل الـaudit على مستوى الصف نفسه
            $table->timestamp('verified_at')->nullable()->after('status');
            $table->foreignId('verified_by')
                ->nullable()
                ->after('verified_at')
                ->constrained('users')
                ->nullOnDelete();
        });

        // ⭐ الصفوف الموجودة قبل العمود: مؤكَّدة بأثر رجعي
        DB::table('student_parents')->update(['status' => 'verified']);
    }

    public function down(): void
    {
        Schema::table('student_parents', function (Blueprint $table) {
            $table->dropForeign(['verified_by']);
            $table->dropColumn(['status', 'verified_at', 'verified_by']);
        });
    }
};