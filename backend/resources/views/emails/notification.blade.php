{{-- ⭐ PR 4.3 — قالب البريد الموحّد: عربي RTL بهوية الأكاديمية. --}}
{{-- CSS مضمّن وجداول — عملاء البريد يتجاهلون <style> وأحياناً Flex. --}}
{{-- كل نص من الـpayload يمر على {{ }} (هروب تلقائي) — لا HTML خارجي. --}}
<!doctype html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="x-apple-disable-message-reformatting">
    <title>{{ $title }}</title>
</head>
<body style="margin:0;padding:0;background-color:#f1f5f9;direction:rtl;text-align:right;font-family:Tahoma,Arial,sans-serif;color:#18232b;">

    {{-- نص المعاينة في صندوق البريد --}}
    <div style="display:none;max-height:0;overflow:hidden;opacity:0;">
        {{ \Illuminate\Support\Str::limit($messageBody, 120) }}
    </div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f1f5f9;padding:24px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;background-color:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e2e8f0;">

                    {{-- ⭐ الترويسة: شعار حرفي + اسم الأكاديمية (بلا صور خارجية) --}}
                    <tr>
                        <td style="background-color:#0f766e;padding:22px 24px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td width="48" valign="middle">
                                        <div style="width:48px;height:48px;line-height:48px;text-align:center;background-color:#ffffff;color:#0f766e;font-size:22px;font-weight:bold;border-radius:10px;">
                                            {{ mb_substr((string) $organizationName, 0, 1) }}
                                        </div>
                                    </td>
                                    <td valign="middle" style="padding-right:14px;">
                                        <div style="color:#ffffff;font-size:18px;font-weight:bold;">{{ $organizationName }}</div>
                                        <div style="color:#ccfbf1;font-size:12px;padding-top:4px;">إشعار رسمي</div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- ⭐ شريط ذهبي رفيع — لمسة الهوية الثانية --}}
                    <tr>
                        <td style="height:4px;background-color:#d4af37;font-size:0;line-height:0;">&nbsp;</td>
                    </tr>

                    <tr>
                        <td style="padding:28px 28px 8px;">
                            @if ($recipientName)
                                <p style="margin:0 0 6px;font-size:14px;color:#64748b;">أهلاً {{ $recipientName }}،</p>
                            @endif
                            <h1 style="margin:0 0 16px;font-size:20px;line-height:1.5;color:#0f766e;">{{ $title }}</h1>
                            <div style="font-size:15px;line-height:1.9;color:#334155;">
                                {!! nl2br(e($messageBody)) !!}
                            </div>
                        </td>
                    </tr>

                    @if ($actionUrl)
                        <tr>
                            <td style="padding:8px 28px 28px;">
                                <a href="{{ $actionUrl }}" style="display:inline-block;background-color:#0f766e;color:#ffffff;text-decoration:none;font-size:14px;font-weight:bold;padding:11px 22px;border-radius:8px;">
                                    عرض التفاصيل
                                </a>
                            </td>
                        </tr>
                    @else
                        <tr><td style="height:20px;font-size:0;line-height:0;">&nbsp;</td></tr>
                    @endif

                    {{-- التذييل --}}
                    <tr>
                        <td style="background-color:#f8fafc;border-top:1px solid #e2e8f0;padding:18px 28px;">
                            <p style="margin:0 0 4px;font-size:12px;line-height:1.7;color:#64748b;">
                                وصلتك هذه الرسالة لأن حسابك مفعّل في {{ $organizationName }}.
                            </p>
                            <p style="margin:0;font-size:12px;line-height:1.7;color:#94a3b8;">
                                يمكنك تعديل قنوات الإشعارات من صفحة «تفضيلات الإشعارات» في حسابك.
                            </p>
                        </td>
                    </tr>
                </table>

                <p style="margin:14px 0 0;font-size:11px;color:#94a3b8;">
                    {{ $organizationName }} — رسالة آلية، لا ترد عليها مباشرة.
                </p>
            </td>
        </tr>
    </table>
</body>
</html>
