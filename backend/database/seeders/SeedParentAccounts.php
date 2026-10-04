<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * حساب دخول لكل ولي أمر.
 *
 * ليش؟ إشعارات «اشتراكك هينتهي» بتتخزّن في جدول notifications، والعمود
 * user_id فيه مش بيسمح بـ null. من غير حساب لولي الأمر مفيش حد ياخد
 * الإشعار — الأمر القديم كان بيعمل التحقق على student.user اللي دايماً
 * null، فكان بيبعت صفر إشعار ويطبع إنه بعت ١٠.
 *
 * كلمة المرور الموحّدة 'password' زي باقي الحسابات التجريبية.
 *
 * التشغيل: php artisan db:seed --class=SeedParentAccounts
 */
class SeedParentAccounts extends Seeder
{
    /** كلمة المرور الموحّدة للحسابات التجريبية */
    private const PASSWORD = 'password';

    public function run(): void
    {
        $now = now();

        // الإيميلات اللي مستخدمة فعلاً — عشان لو ولي أمر إيميلو
        // نفس إيميل معلم أو الأدمن ما نعملوش تضارب
        $takenEmails = DB::table('users')
            ->where('organization_id', 1)
            ->pluck('email')
            ->flip();

        $created = 0;
        $linked = 0;
        $noEmail = 0;
        $emailClash = 0;

        $parents = DB::table('parents')->whereNull('user_id')->get();

        foreach ($parents as $parent) {
            $email = trim((string) ($parent->email ?? ''));

            // مفيش إيميل = مفيش حساب. أرقام الهاتف مش بتكفي للدخول.
            if ($email === '' || !str_contains($email, '@')) {
                $noEmail++;
                continue;
            }

            // الإيميل مستخدم لحساب تاني — نتخطى بدل ما نكسر الـ unique
            if (isset($takenEmails[$email])) {
                $emailClash++;
                continue;
            }

            $userId = DB::table('users')->insertGetId([
                'organization_id' => 1,
                'name' => $parent->name,
                'email' => $email,
                'phone' => $parent->phone,
                'password' => Hash::make(self::PASSWORD),
                'timezone' => 'Africa/Cairo',
                'locale' => 'ar',
                'job_title' => 'ولي أمر',
                'department' => null,
                'status' => 'active',
                'is_parent' => true,
                'email_verified_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $takenEmails[$email] = true;

            DB::table('parents')->where('id', $parent->id)->update([
                'user_id' => $userId,
                'updated_at' => $now,
            ]);

            $created++;
            $linked++;
        }

        $this->command?->info("✅ {$created} حساب ولي أمر — كلمة المرور: " . self::PASSWORD);

        if ($noEmail > 0) {
            $this->command?->warn("⚠️  {$noEmail} ولي أمر بدون إيميل — مقدرناش نعمللهم حساب دخول");
        }
        if ($emailClash > 0) {
            $this->command?->warn("⚠️  {$emailClash} إيميل مستخدم بحساب تاني — اتخطّى");
        }
        if ($created === 0) {
            $this->command?->info('   كل أولياء الأمور ليهم حسابات بالفعل');
        }
    }
}
