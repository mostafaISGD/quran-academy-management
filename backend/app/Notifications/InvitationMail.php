<?php

namespace App\Notifications;

use App\Models\Invitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * ⭐⭐⭐ إيميل الدعوة — **بالعربي بالكامل**.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐ ليه `ShouldQueue`
 *
 * ⭐ الإيميل **مش** جزء من المعاملة. لو SMTP واقع، الحساب والدعوة
 * لازم يفضلوا اتعملوا، والإيميل يتعاد بعدين.
 *
 * ⭐ وقبل `ShouldQueue`، `InvitationService::sendMail()` كانت
 * بتعمل `try/catch` — وده شغل layer. مع الـqueue، بقى **الـqueue
 * نفسه** هو اللي بيحاول تاني، والـ`failed_jobs` بيسجّل الفشل.
 *
 * ⭐⚠️ **الـqueue لازم تشتغل في الإنتاج** — `QUEUE_CONNECTION=redis`.
 * من غير كده `ShouldQueue` بيرمي
 * `SyncQueueRequiredException` في طلب واحد، والدعوة تتموت معاه.
 *
 * @see \App\Services\InvitationService::sendMail
 */
class InvitationMail extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * ⭐⭐⭐ **التوكن الخام جاي في الـNotification object.**
     *
     * ⭐ ده مش تعارض مع «التوكن مش بيتخزّن»:
     *
     *   • **مش** بيتخزّن في **الداتابيز** — لا في `invitations`
     *     ولا في `failed_jobs` ولا في أي `cache`.
     *   • الـobject ده **عيش في الذاكرة** لحد ما الإيميل يتسلّم،
     *     وبعدها يروح مع الـjob.
     *
     * ⭐⚠️ **بس** لو الـqueue على **قاعدة بيانات**، الـjob payload
     * **بيتسلسل** في `jobs` — فالتوكن هيتخزّن صريح!
 *
     * ⭐ فده سبب إضافي إن `QUEUE_CONNECTION=redis` **مش** اختياري
     * هنا. مع Redis، الـpayload في الذاكرة بس.
     *
     * @param  string  $plainToken  التوكن الخام — **مرة واحدة**
     */
    public function __construct(
        private readonly Invitation $invitation,
        private readonly string $plainToken,
        private readonly ?string $name = null,
    ) {}

    /**
     * ⭐⭐ عبر `onMail` بس — مش `toDatabase`.
     *
     * ⭐ لو الـnotification اتخزّن في `notifications` كان التوكن
     * **هيتمشّي معاه**. فبنقصر القناة على `mail` بس.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = $this->acceptUrl();

        return (new MailMessage)
            // ⭐⭐ `subject` عربي — الـMailMessage بيفترض إنجليزي
            ->subject('دعوة للانضمام لأكاديمية القرآن')
            ->greeting('أهلًا ' . ($this->name ?: 'بك'))
            ->line('لو إنت مش مستني الدعوة دي، تجاهلها ' . '— مافيش حد هيقدر يدخل من غيرك.')
            ->line('اضغط على الزر تحت عشان تعمل كلمة مرور لحسابك وتبدأ.')
            ->action('اعبط كلمة المرور', $url)
            // ⭐⭐ التاريخ **بالصيغة العربية** — التطبيق كله عربي
            ->line('⏳ الرابط صالح لحد **'.$this->expiryLabel().'**. بعد كده هيبقى محتاج دعوة جديدة.')
            ->line('لو إنت مش مستني الدعوة دي، تجاهلها — مافيش حد هيقدر يدخل من غيرك.')
            ->salutation('أكاديمية القرآن');
    }

    /**
     * ⭐ رابط القبول.
     *
     * ⭐ الـ`APP_URL` جوه الرابط، فالتطبيق عنده نطاق واحد بيشتغل.
     */
    private function acceptUrl(): string
    {
        $token = urlencode($this->plainToken);

        return rtrim((string) config('app.frontend_url', config('app.url')), '/')
            .'/accept-invitation?token='.$token;
    }

    /**
     * ⭐⭐ تاريخ الانتهاء بالعربي.
     *
     * ⭐ `translatedFormat` بيستعمل locale النشط، والاختبار
     * بيفحص إن التاريخ **ظاهر** في الإيميل — مهم جداً، لأن
     * «لو الرابط dies بعدين» بدون تاريخ مافيش ليه معنى للمستخدم.
     */
    private function expiryLabel(): string
    {
        return $this->invitation->expires_at->translatedFormat('l j F Y الساعة H:i');
    }

    /**
     * ⭐ **للتسجيل بس** — مافيش أي سطر فيه التوكن.
     */
    public function toArray(object $notifiable): array
    {
        return [
            'invitation_id' => $this->invitation->id,
            'expires_at' => (string) $this->invitation->expires_at,
        ];
    }
}
