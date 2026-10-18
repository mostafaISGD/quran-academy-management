<?php

namespace App\Exceptions;

use Exception;
use Symfony\Component\HttpFoundation\Response;

/**
 * ⭐⭐⭐ خطأ في **دعوة** — وبيبقى جاهز، مش «جرّب تاني».
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐ ليه class لوحده
 *
 * ⭐ الـ`410 Gone` جواب **محدد**: التوكن راح ولن يرجع.
 * (`expired` · `cancelled` · `superseded` · `unknown`)
 *
 * ⭐ والردود التانية **بتحصل غلط**:
 *
 *   • `403` بيدفع العميل يعمل retry loop طول اليوم. توكن ميت مش
 *     هينفع، فيعملعشرة آلاف طلب.
 *   • `404` بيقول «مافيش دعوة» — وده بيكشف حاجة: إن الـtoken
 *     **كان موجود**. مع `410` لكل حاجة، مافيش تسريب خالص.
 *   • `422` معناها «الطلب غلط» — وهو غلط. الطلب **صحيح**، التوكن
 *     بس راح.
 *
 * @see \App\Services\InvitationService
 */
class InvitationGoneException extends Exception
{
    public const EXPIRED = 'expired';

    public const CANCELLED = 'cancelled';

    public const SUPERSEDED = 'superseded';

    public const UNKNOWN = 'unknown';

    public const ALREADY_ACCEPTED = 'already_accepted';

    /**
 * ⭐⭐ الرد العربي لكل حالة.
 *
 * ⭐⭐ **اللي ثابت هو الـstatus مش الرسالة** — وده المهم.
 *
 * الخمسة كلهم `410` عمداً، لأن الـ`403` بيدفع العميل يعمل retry
 * loop طول اليوم (توكن ميت مش هينفع، فيعمل آلاف الطلبات)،
 * والـ`404` بيقول «مفيش دعوة» — وده سؤال مش جواب.
 *
 * ⭐ **ليه رسائل مختلفة مقبولة هنا** ومتسألناش عنها زي الـ
 * password reset؟
 *
 * ⭐⭐ لأن التوكن **غير قابل للتخمين**: 64 byte عشوائي = 2^512.
 * في `forgotPassword` كنا بنرجّع رسالة **واحدة** لأن هناك
 * الـinput هو **الإيميل** — وهو معلومة المستخدم، فالفرق بين
 * «مش موجود» و«متوقف» كان **تسريب حقيقي** بيقول للمهاجم إن
 * الحساب موجود أصلاً.
 *
 * ⭐ هنا العكس: اللي بيحاول يخمن التوكن مش هيلاقي حاجة —
 * الـ2^512 معناها مساحة مستحيلة. واللي عنده توكن **حقيقي**
 * (وصله أو اتشال من إيميله) يستفيد من معرفة السبب: «منتهية» غير
 * «ملغاة» غير «اتبعت واحدة جديدة».
 *
 * ⭐⚠️ وده شرط: لو الـtoken بقى قصير أو قابل للتخمين، الرسائل
 * هترجع تسمى تسريب. الـ512 بت هي اللي بتخلّي الفصل ده آمن.
 */
    private const MESSAGES = [
        self::EXPIRED => 'رابط الدعوة انتهت صلاحيته. اطلب واحدة جديدة.',
        self::CANCELLED => 'رابط الدعوة مش صالح. اطلب واحدة جديدة.',
        self::SUPERSEDED => 'تم إرسال دعوة جديدة. استخدم أحدث رابط.',
        self::ALREADY_ACCEPTED => 'الدعوة دي اتقبلت خلاص. سجّل دخولك.',
        self::UNKNOWN => 'رابط الدعوة مش صالح. اطلب واحدة جديدة.',
    ];

    public function __construct(private readonly string $reason = self::UNKNOWN)
    {
        parent::__construct(self::MESSAGES[$reason] ?? self::MESSAGES[self::UNKNOWN]);
    }

    public static function of(string $reason): self
    {
        return new self($reason);
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function status(): int
    {
        return Response::HTTP_GONE;
    }
}
