<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐⭐⭐ **PR 3.11 — روابط الدروس وسجل الدخول.**
 *
 * ⭐ **قبل PR ده**:
 *
 * - رابط الحصة حقل حر (أي رابط بيتقبّل من غير فحص مزود).
 * - ما كانش مكان نعرف منه إن الطالب **دخل الحصة فعلاً** —
 *   فاقتراح الحضور كان مفيش مصدر له من حدث «الدخول».
 * - مفيش رابط افتراضي للمعلم ⇒ كل حصة بودّله يدويًا.
 *
 * ⭐ دلوقتي:
 *
 * ① `teachers.default_meeting_url` — الرابط الافتراضي للمعلم
 *    (بيتعلّق بكل حصة ما بتحددش رابط).
 * ② `lesson_participants` — **سجل الدخول** لكل (حصة، مشارك)
 *    مرة واحدة (قيد فريد) + مصدره (رابط رمز/رمز PIN) — ده اللي
 *    بيغذّي اقتراح الحضور من البوابة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teachers', function (Blueprint $table) {
            $table->string('default_meeting_url')->nullable()->after('timezone');
        });

        Schema::create('lesson_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_id')->constrained('lessons')->cascadeOnDelete();
            $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();

            /**
             * ⭐ الرمز اللي دخل بيه (لو جاي من `/join/{token}`) —
             * من غيره الرمز انتهى ولا حد نفّذه.
             */
            $table->foreignId('token_id')->nullable()
                ->constrained('lesson_join_tokens')->nullOnDelete();

            /** ⭐ مصدر القيد: `join_link` (الرابط) أو `pin` (الرمز من الـAPI). */
            $table->string('source', 30)->default('join_link');

            $table->timestamp('joined_at')->nullable();

            // ⭐ القبول: الدخول **مرة واحدة لكل مشارك**.
            $table->unique(['lesson_id', 'student_id']);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_participants');

        Schema::table('teachers', function (Blueprint $table) {
            $table->dropColumn('default_meeting_url');
        });
    }
};
