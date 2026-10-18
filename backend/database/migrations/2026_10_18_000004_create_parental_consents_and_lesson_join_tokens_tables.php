<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ PR 1.12 — موافقة ولي الأمر + رموز دخول حصة الطالب.
 *
 * ═══════════════════════════════════════════════════════════════
 * ① `parental_consents`
 *
 * ⭐ «الموافقة» **صف مش boolean**: لازم نعرف *مين* approving *إمتى*،
 * ⭐ ** إضافة بس**: الصف بيفضل بعد السحب (السحب = revoked_at، مش
 * delete) — بيحتفظ بالتاريخ.
 *
 * ⭐ `scope` مش `true/false` عشان الموافقة مش كل consented:
 * موافقة «دخول لوحة الطالب» مش موافقة «الع ledge» ولا
 * «شوف المصاريف». scope بينعزل الصلاحيات.
 *
 * ⭐ ** إضافة بس**: الصف المعلّق بيفضل بعد السحب
 * (السحب = `revoked_at`، مش `delete`) — بيحتفظ بالتاريخ.
 *
 * ② `lesson_join_tokens`
 *
 * ⭐ الطالب اللي تحت 13 **مالوش حساب**، فلازم يدخل الحصة من غير
 * حساب. ده = رابط/كود قصير العمر مربوط بالحصة + الطالب.
 *
 * ⚠️ ليه **مش** PIN من الأرقام بس؟ لأن 4 أرقام = 10,000 احتمال،
 * والقوة العمياء عليها بتوصل. التوكن هنا **عشوائي طويل**
 * (token عشوائي) **+ محدود بالحصة** + **منتهي**.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parental_consents', function (Blueprint $table) {
            $table->id();

            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();

            // ⭐ `nullOnDelete` مش `cascade`: مسح ولي الأمر **مش**
            // معناه إن الموافقة سارت. السجل بيستحضر «كان في موافقة
            // من حد مش موجود دلوقتي».
            $table->foreignId('parent_id')->nullable()->constrained('parents')->nullOnDelete();

            // ⭐ account_activation | view_progress | payments
            $table->string('scope', 50)->default('account_activation');
            $table->boolean('granted')->default(true);

            $table->timestamp('granted_at');
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();

            // ⭐ السحب بيحط وقت + مين — مش حذف
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->timestamps();

            // ⭐ الاستعلام الساخن: «فيه موافقة سارية للطالب ده؟»
            $table->index(['student_id', 'scope', 'revoked_at']);
        });

        Schema::create('lesson_join_tokens', function (Blueprint $table) {
            $table->id();

            $table->foreignId('lesson_id')->constrained('lessons')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();

            // ⭐ **هاش** التوكن مش نصّه — نفس قاعدة `invitations`
            // (PR 1.4). لو اللوق مش دي، مفيش فايدة من الهاش.
            $table->string('token_hash', 64)->unique();

            // ⭐ الكود المختصر للعرض على ولي الأمر (٦ خانات)
            $table->string('pin', 6);

            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();

            $table->foreignId('used_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['lesson_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_join_tokens');
        Schema::dropIfExists('parental_consents');
    }
};
