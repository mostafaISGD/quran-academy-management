<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐⭐ بنية كلمات المرور — جدول التوكنات + إجبار التغيير.
 *
 * ═══════════════════════════════════════════════════════════════
 * ① `password_reset_tokens`
 *
 * ⭐⭐ **الجدول ده مفقود أصلاً.** و`config/auth.php` بيقول
 * `'passwords.users.provider' => 'users'` — يعني الـ broker
 * مربوط عليه وهو بيحاول يكتب في جدول مش موجود.
 *
 * وده بالظبط سبب إن الاستعادة كانت **cosmetic**: الكود القديم
 * مكانش بينادي `Password::` أصلاً، فلو كان ناداه كان هيرمي
 * «table not found».
 *
 * ═══════════════════════════════════════════════════════════════
 * ② `users.must_change_password`
 *
 * ⭐ الحساب الجديد بيتعمله كلمة مرور عشوائية **محدش يعرفها**
 * (مولّدة على السيرفر ومحدش بيقراها). فبلا عمود ده، الحساب ده
 * حيّ بس **مقفول**.
 *
 * ⭐ الـmiddleware بيقفل كل حاجة غير:
 *   • `POST /auth/change-password` — عشان يقدر يغيّرها
 *   • `GET  /auth/me`            — عشان يبقى عارف مين هو
 *   • `POST /auth/logout`        — عشان يقدر يخرج
 *
 * ═══════════════════════════════════════════════════════════════
 * ③ ليه مفيش unique على الإيميل
 *
 * ⭐ `users` عنده `unique(['organization_id', 'email'])` بس —
 * * ⭐ الـ middleware المسجّل على مجموعة api كلها — مش على
 *  * راوت واحد — وده اللي بيمنع تسريب راوت جديد.
 *
 * و`Password::sendResetLink` بيبعت على `where email = ?` — لو
 * الإيميل مكرر، بيبعت لكل الحسابات. ده سلوك معروف في Laravel،
 * بس بنخليه **متعمّد**: الاستعادة لازم تكون آمنة في الحالتين.
 * (التوحيد في `AuthController::forgotPassword` بيغطي الرد.)
 *
 * @see \App\Http\Middleware\EnsurePasswordChanged
 */
return new class extends Migration
{
    public function up(): void
    {
        // ① جدول توكنات استعادة كلمة المرور
        if (! Schema::hasTable('password_reset_tokens')) {
            Schema::create('password_reset_tokens', function (Blueprint $table) {
                $table->string('email')->primary();
                $table->string('token');
                $table->timestamp('created_at')->nullable();
            });
        }

        // ② إجبار تغيير كلمة المرور
        if (! Schema::hasColumn('users', 'must_change_password')) {
            Schema::table('users', function (Blueprint $table) {
                // ⭐ default **true** مش false.
                //
                // السبب: أي حساب موجود دلوقتي مالوش كلمة مرور
                // معروفة منprech الـseeder غير `password` — وده
                // بالظبط اللي بنقفله. الـdefault بيلزمهم يغيّروها
                // أول ما يدخلوا، بدل ما نملا الداتابيز.
                $table->boolean('must_change_password')->default(true)->after('password');
            });
        }

        // ③ وقت آخر تغيير — لـ PR 1.9 (إعادة تأكيد الهوية)
        if (! Schema::hasColumn('users', 'password_changed_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('password_changed_at')->nullable()->after('password');
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['must_change_password', 'password_changed_at']);
        });

        Schema::dropIfExists('password_reset_tokens');
    }
};
