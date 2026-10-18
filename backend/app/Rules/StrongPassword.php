<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

/**
 * ⭐⭐ سياسة كلمة المرور — حتمية، بلا شبكة.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐ ليه مش `uncompromised()` بتاع Laravel
 *
 * Laravel عنده `Password::uncompromised()`، بس هو بيعمل **طلب شبكة**
 * لـ Have I Been Pwned في **كل** تحقق. ده بيخلّي:
 *
 *   • *   • الاختبارات تفشل أو تتأخر لو النت بطيء أو مقطوع
 *   • **تسجيل الدخول والاستعادة** معتمدين على خدمة خارجية —
 *     يعني لو الخدمة وقعت، ما حدش يقدر يسجّل دخول
 *
 * ⭐ فبنعمل نسخة محلية: قائمة كلمات تسريبات شائعة مخزّنة جوه
 * الكود، وفحص سريع، ونتيجة **واحدة دايماً**.
 *
 * ═══════════════════════════════════════════════════════════════
 * ليه القائمة جوه الكود ومش في config
 *
 * لأنها **ثابتة** — مش إعداد. لو بقت في `config` حد يقدر
 * يمسحها أو يقيّدها وبتضعف الحماية بصمت. الكود ثابت ومكتوب
 * على عيوننا.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐ الـ TODO الحقيقي
 *
 * فحص HIBP **يستاهل** يتعمل، لكنه محتاج قرار تشغيلي: cache
 * للنتايج + timeout + سلوك عند فشل الشبكة. ده شغل PR 1.4 مع
 * الـinvitations، مش هنا.
 *
 * @see \Tests\Feature\PasswordPolicyTest
 */
class StrongPassword implements ValidationRule
{
    /**
     * ⭐ أشهر كلمات المرور المسرّبة.
     *
     * مبنية على قوائم التسريبات العامة. الهدف مش نغطي ** إن نغطي أول ** بتظهر في ذي في في تطهر دي ترت مشي بتطر اتحاد.
     * مش إننا نغطي **كل** كلمة ضعيفة — الهدف نرفض أشهر ١٠٠
     * اللي بتظهر أول في أي هجوم.
     *
     * ⚠️ العناصر lowercase — المقارنة كمان lowercase.
     *
     * @var array<int, string>
     */
    private const COMMON = [
        'password', 'password1', 'password123', 'password1234', 'passw0rd',
        '123456', '1234567', '12345678', '123456789', '1234567890',
        '123123', '111111', '000000', '121212', 'qwerty', 'qwerty123',
        'abc123', 'abcd1234', 'a123456', 'admin', 'admin123', 'administrator',
        'letmein', 'welcome', 'welcome1', 'welcome123', 'monkey', 'dragon',
        'iloveyou', 'sunshine', 'princess', 'football', 'charlie', 'shadow',
        'master', 'superman', 'batman', 'michael', 'jennifer', 'trustno1',
        'baseball', 'iloveu', 'whatever', 'qazwsx', 'zaq12wsx', 'pass',
        'secret', 'root', 'toor', 'test', 'testing', 'guest', 'info',
        'login', 'hello', 'freedom', 'computer', 'ninja', 'azerty',
        'loveme', 'whatever', '654321', '7777777', '1q2w3e4r', 'qwer1234',
        '987654321', '123321', 'passw0rd', 'changeme', 'default', 'guest123',
        'welcome1234', 'password!', 'p@ssw0rd', 'p@ssword', 'admin@123',
        'quran', 'quran123', 'academy', 'academy123', 'teacher', 'teacher123',
        'student', 'student123', 'parent', 'parent123', 'mum', 'dad',
        'egypt', 'cairo', 'maktaba', 'ahlan', 'salam',
    ];

    public function __construct(
        private readonly int $minLength = 12,
        /**
         * ⭐ الإيميل — بنرفض أي كلمة مرور فيه جزء منه.
         *
         * ⭐ ليه: كلمة مرور زي `ahmed@academy.com` بتبقى سهلة
         * التخمّن حتى لو طويلة، لأنها **معلنة** في الـAPI.
         */
        private readonly ?string $email = null,
    ) {}

/**
     * @param  Closure(string): void  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $password = (string) $value;

        // ① الطول
        if (mb_strlen($password) < $this->minLength) {
            $fail("كلمة المرور لازم تكون {$this->minLength} حرف على الأقل.");

            return;
        }

        // ② ⭐ الشائعة — بمقارنة case-insensitive
        if (in_array(mb_strtolower($password), self::COMMON, true)) {
            $fail('كلمة المرور دي من أشهر كلمات المرور المسرّبة — اختار غيرها.');

            return;
        }

        // ③ ⭐ جزء الإيميل جوه كلمة المرور
        //
        // بناخد الجزء **قبل** الـ`@` وبنشوف هل كلمة المرور
        // بتحتوي عليه. بنتجاهل الأجزاء القصيرة (٣ حروف أو أقل)
        // عشان ما نرفضش كلمات صدفة.
        if ($this->email) {
            $local = mb_strtolower(Str::before($this->email, '@'));

            if (mb_strlen($local) >= 3 && str_contains(mb_strtolower($password), $local)) {
                $fail('كلمة المرور ما ينفعش تحتوي على جزء من الإيميل.');

                return;
            }
        }

        // ④ ⭐ تشكيلة بسيطة — حرف كبير + صغير + رقم
        //
        // ⭐ مش رموز: كلمة مرور طويلة من حروف وأرقام أقوى من
        // كلمة قصيرة برموز، والقاعدة بتخلي الناس تحط `P@ssw0rd`
        // (وهي في القائمة الشائعة أصلاً).
        if (! preg_match('/[a-z]/', $password)
            || ! preg_match('/[A-Z]/', $password)
            || ! preg_match('/\d/', $password)
        ) {
            $fail('لازم تحتوي على حرف كبير وحرف صغير ورقم على الأقل.');
        }
    }
}
