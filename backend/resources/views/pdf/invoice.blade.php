{{-- ⭐ PR 2.17 — مستند الفاتورة (PDF عربي RTL عبر mPDF). --}}
{{-- كل التفاصيل من الإعدادات المؤسسية + صف الفاتورة نفسه. --}}
{{-- مواعيد ومبالغ بأرقام لاتينية رقم_format + تواريخ Y-m-d. --}}
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
        .badge { display: inline-block; padding: 3px 12px; border: 1px solid #0f766e;
                 border-radius: 4px; font-size: 10pt; }
        table.info { width: 100%; border-collapse: collapse; margin-top: 10px; }
        table.info td { padding: 4px 6px; font-size: 10.5pt; }
        table.info td.k { color: #47555e; width: 22%; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 14px; }
        table.items th { background: #0f766e; color: #fff; padding: 7px 6px; font-size: 10.5pt;
                         border: 1px solid #0d5f58; }
        table.items td { padding: 7px 6px; border: 1px solid #d9e2e7; font-size: 10.5pt; }
        .num { text-align: left; direction: ltr; font-size: 10.5pt; }
        table.totals { width: 60%; border-collapse: collapse; margin-top: 14px; margin-left: auto; }
        table.totals td { padding: 5px 8px; font-size: 10.5pt; border: none; }
        table.totals tr.grand td { background: #0f766e; color: #fff; font-weight: bold;
                                   font-size: 12pt; border-radius: 3px; }
        table.totals td.v { text-align: left; direction: ltr; }
        .qr { text-align: center; margin-top: 18px; direction: ltr; }
        .footnote { margin-top: 16px; font-size: 9pt; color: #64748b; border-top: 1px solid #e2e8f0;
                    padding-top: 8px; }
    </style>
</head>
<body>

    <div class="doc-head">
        <table width="100%">
            <tr>
                <td width="62%" style="vertical-align: top;">
                    <p class="academy">{{ $academy['name'] }}</p>
                    <p class="academy-meta">
                        @if ($academy['address'] !== '')
                            {{ $academy['address'] }}
                            @if ($academy['phone'] !== '')
                                &nbsp;•&nbsp;{{ $academy['phone'] }}
                            @endif
                            @if ($academy['email'] !== '')
                                &nbsp;•&nbsp;{{ $academy['email'] }}
                            @endif
                        @else
                            @if ($academy['phone'] !== '')
                                {{ $academy['phone'] }}
                            @endif
                            @if ($academy['email'] !== '')
                                @if ($academy['phone'] !== '')
                                    &nbsp;•&nbsp;
                                @endif
                                {{ $academy['email'] }}
                            @endif
                        @endif
                    </p>
                </td>
                <td style="vertical-align: top;">
                    <h1 class="doc-title">فاتورة</h1>
                    <p class="academy-meta num">{{ $printNumber }}</p>
                </td>
            </tr>
        </table>
    </div>

    <table class="info">
        <tr>
            <td class="k">الطالب</td>
            <td>{{ $invoice->student?->full_name ?? '—' }}</td>
            <td class="k">تاريخ الإصدار</td>
            <td class="num">{{ \Illuminate\Support\Str::substr((string) $invoice->issue_date, 0, 10) }}</td>
        </tr>
        <tr>
            <td class="k">تاريخ الاستحقاق</td>
            <td class="num">{{ \Illuminate\Support\Str::substr((string) $invoice->due_date, 0, 10) }}</td>
            <td class="k">حالة الفاتورة</td>
            <td>{{ $invoice->status }}</td>
        </tr>
    </table>

    @if ($invoice->items->isNotEmpty())
        <table class="items">
            <tr>
                <th width="50%">البيان</th>
                <th width="10%">الكمية</th>
                <th width="20%">سعر الوحدة</th>
                <th width="20%">الإجمالي</th>
            </tr>
            @foreach ($invoice->items as $item)
                <tr>
                    <td>{{ $item->description }}</td>
                    <td class="num">{{ number_format((float) $item->quantity, 2) }}</td>
                    <td class="num">{{ number_format((float) $item->unit_price, 2) }}</td>
                    <td class="num">{{ number_format((float) $item->total, 2) }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    <table class="totals">
        <tr>
            <td>المجموع قبل الخصم</td>
            <td class="v">{{ number_format((float) $invoice->subtotal, 2) }}
                {{ $invoice->currency }}</td>
        </tr>
        <tr>
            <td>الخصم</td>
            <td class="v">{{ number_format((float) $invoice->discount, 2) }}</td>
        </tr>
        <tr>
            <td>الضريبة</td>
            <td class="v">{{ number_format((float) $invoice->tax, 2) }}</td>
        </tr>
        <tr class="grand">
            <td>الإجمالي المستحق</td>
            <td class="v">{{ number_format((float) $invoice->total, 2) }}
                {{ $invoice->currency }}</td>
        </tr>
        <tr>
            <td>المدفوع</td>
            <td class="v">{{ number_format((float) $invoice->paid_amount, 2) }}</td>
        </tr>
        <tr>
            <td>المتبقي</td>
            <td class="v">{{ number_format((float) $invoice->balance_due, 2) }}</td>
        </tr>
    </table>

    <div class="qr">
        <barcode type="QR" value="{{ $qrPayload }}" size="0.9" style="color:#0f766e;" />
        <p style="font-size: 9pt; color: #64748b;">امسح للتحقق من بيانات الفاتورة</p>
    </div>

    <p class="footnote">
        مستند مُولَّد إلكترونيًا بتاريخ <span class="num">{{ $issuedAt }}</span> —
        يرجى معالجة أي استفسار عبر إدارة الأكاديمية.
    </p>

</body>
</html>
