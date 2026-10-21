{{-- ⭐ PR 2.17 — إيصال استلام دفعة (PDF عربي RTL عبر mPDF). --}}
{{-- رقم الإيصال مشتق `PdfDocumentService::receiptNumber` — دالة صرفة، وليس مُعدَّداً. --}}
{{-- الموبال: الأرقام لاتينية رقم_format؛ التاريخ والوقت Y H:i بصيغة لاتينية. --}}
<!doctype html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: dejavusans; direction: rtl; font-size: 11pt; color: #18232b; }
        .doc-head { border-bottom: 2px solid #0f766e; padding-bottom: 8px; }
        .doc-title { font-size: 17pt; font-weight: bold; color: #0f766e; margin: 0; text-align: left; }
        .academy { font-size: 15pt; font-weight: bold; margin: 0; }
        .academy-meta { font-size: 10pt; color: #47555e; margin: 2px 0 0 0; }
        table.info { width: 100%; border-collapse: collapse; margin-top: 12px; }
        table.info td { padding: 4px 6px; font-size: 10.5pt; }
        table.info td.k { color: #47555e; width: 22%; }
        .amount-box { margin-top: 14px; border: 2px solid #0f766e; border-radius: 6px;
                      padding: 10px 12px; width: 60%; }
        .amount-label { font-size: 11pt; color: #47555e; margin: 0; }
        .amount-value { font-size: 19pt; font-weight: bold; color: #0f766e; margin: 2px 0 0 0;
                        direction: ltr; text-align: left; }
        .qr { text-align: center; margin-top: 16px; direction: ltr; }
        .footnote { margin-top: 14px; font-size: 9pt; color: #64748b; border-top: 1px solid #e2e8f0;
                    padding-top: 8px; }
        .num { text-align: left; direction: ltr; font-size: 10.5pt; }
    </style>
</head>
<body>

    <div class="doc-head">
        <table width="100%">
            <tr>
                <td width="62%" style="vertical-align: top;">
                    <table>
                        <tr>
                            <td width="46" height="46" style="background: #0f766e; color: #ffffff; text-align: center; font-size: 20pt; font-weight: bold; border-radius: 8px;">{{ $academy['logoInitial'] }}</td>
                            <td style="vertical-align: middle; padding-right: 10px;">
                                <p class="academy">{{ $academy['name'] }}</p>
                                <p class="academy-meta">
                                    @if ($academy['phone'] !== '')
                                        {{ $academy['phone'] }}
                                    @endif
                                    @if ($academy['email'] !== '')
                                        @if ($academy['phone'] !== '')
                                            &nbsp;•&nbsp;
                                        @endif
                                        {{ $academy['email'] }}
                                    @endif
                                </p>
                            </td>
                        </tr>
                    </table>
                </td>
                <td style="vertical-align: top;">
                    <h1 class="doc-title">إيصال استلام</h1>
                    <p class="academy-meta num">{{ $printNumber }}</p>
                </td>
            </tr>
        </table>
    </div>

    <table class="info">
        <tr>
            <td class="k">الدفعة عن</td>
            <td>{{ $payment->student?->full_name ?? '—' }}</td>
            <td class="k">تاريخ الدفع</td>
            <td class="num">{{ \Illuminate\Support\Str::substr((string) $payment->paid_at, 0, 16) }}</td>
        </tr>
        <tr>
            <td class="k">طريقة الدفع</td>
            <td>{{ $payment->payment_method }}</td>
            <td class="k">مرجع العملية</td>
            <td class="num">{{ $payment->transaction_reference ?? '—' }}</td>
        </tr>
        @if ($payment->invoice)
            <tr>
                <td class="k">الفاتورة المرتبطة</td>
                <td class="num">{{ $payment->invoice->invoice_number }}</td>
                <td class="k">حالة الدفعة</td>
                <td>{{ $payment->status }}</td>
            </tr>
        @endif
        @if ($payment->receivedBy)
            <tr>
                <td class="k">استلمها</td>
                <td>{{ $payment->receivedBy->name }}</td>
                <td class="k"></td>
                <td></td>
            </tr>
        @endif
    </table>

    <div class="amount-box">
        <p class="amount-label">المبلغ المستلم بالكلمات</p>
        <p class="amount-value">{{ number_format((float) $payment->amount, 2) }} {{ $payment->currency }}</p>
    </div>

    <div class="qr">
        <barcode type="QR" value="{{ $qrPayload }}" size="0.9" style="color:#0f766e;" />
        <p style="font-size: 9pt; color: #64748b;">امسح للتحقق من بيانات الدفعة</p>
    </div>

    <p class="footnote">
        إيصال مُولَّد إلكترونيًا بتاريخ <span class="num">{{ $issuedAt }}</span> —
        أي تعديل إلكتروني على مستند مالي يكون عبر قيد عكسي موثّق، لا بالمسح.
    </p>

</body>
</html>
