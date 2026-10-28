<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * ⭐⭐⭐ **PR 4.3 — البريد الإشعار الرسمي (عربي RTL بهوية الأكاديمية).**
 *
 * ⭐ قبل PR ده كان `MailProvider` يستخدم `Mail::raw` — نص عادي
 * بلا اتجاه ولا ألوان ولا هوية، ورسالة «من الأكاديمية» تبدو
 * كإشعار نظام لا كخطاب مؤسسة.
 *
 * ⭐⭐ القالب هنا **واحد لكل الإشعارات**: الهوية ثابتة، والمتغير
 * عنوان/نص/اسم مستلم/زر اختياري. لا HTML يأتي من خارج القالب —
 * النص يمر على `e()` في الـBlade، فلا حقن.
 */
class NotificationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $title,
        public string $messageBody,
        public ?string $recipientName = null,
        public ?string $organizationName = null,
        public ?string $actionUrl = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->title !== '' ? $this->title : 'إشعار من الأكاديمية',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.notification',
            with: [
                'title' => $this->title,
                'messageBody' => $this->messageBody,
                'recipientName' => $this->recipientName,
                'organizationName' => $this->organizationName ?? 'الأكاديمية',
                'actionUrl' => $this->actionUrl,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
