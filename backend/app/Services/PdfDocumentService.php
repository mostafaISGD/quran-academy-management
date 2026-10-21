<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Setting;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * ⭐⭐⭐ **PR 2.17 — مستندات PDF عربية رسمية (mPDF).**
 *
 * فاتورة الطالب وإيصال الاستلام — المستند الرسمي القابل للطباعة
 * يترجل منه كل ما يحتاج الولي/الإدارة:
 *
 * - **تفاصيل الأكاديمية من الإعدادات**: `academy_name/phone/
 *   email/address` عبر `Setting::get(..., $organizationId)` —
 *   قراءة **مؤسسية** (PR 2.8) مش قيمة مؤسسة تانية.
 * - **الرقم التسلسلي**: `invoice_number` نفسه (مولود من
 *   `InvoiceService::nextNumber` بـ`lockForUpdate`) — طبعاً
 *   مافيش رقم تاني يتولد هنا من غير قفل.
 * - **QR اختياري**: باركود متجه بـmPDF (نص رقم + مبلغ) —
 *   تحقق سريع على الطباعة.
 *
 * ⭐ **لماذا mPDF؟** تشكيل الحروف العربية + bidi RTL حقيقي من PHP
 * خالص. البدائل: dompdf لا يُشكّل الحروف العربية (حروف مقطوعة)،
 * وwkhtmltopdf يحتاج ثنائياً خارجياً على الجهاز. الخط هنا
 * `dejavusans` (يضم الحروف العربية) ومحرك التشكيل الداخلي
 * يرسم الوصل واللامات صح.
 *
 * ⭐ **قيد البيئة (موثّق):** البيئة دي بلا `ext-gd` — اتنصب
 * بـ`--ignore-platform-req=ext-gd`. القوالب كلها نص + QR متجه
 * — مفيش أي مسار صور نقطية بيروح لـGD. لو لاحقاً فيه شعار نقطي
 * (PNG) يتطلب — لازم جهاز فيه GD (أو شعار SVG؛ mPDF 8.x
 * ما زال محدود في دعمه لـSVG).
 *
 * ⭐ **الأرقام اللاتينية**: المواصفة طلبت أرقام لاتينية صريحة
 * جنب العربي — فكل المبالغ والتواريخ والأرقام تكون من
 * `number_format` وتنسيق `Y-m-d` (مش أرقام عربيه-هنديه).
 */
final class PdfDocumentService
{
    /** A4 بهوامش متماثلة — تناسق القالب. */
    private const PAGE_MARGIN_MM = 14;

    public function invoicePdf(Invoice $invoice): string
    {
        $invoice->loadMissing(['student', 'items']);

        $academy = $this->academy($invoice->organization_id);
        $formattedNumber = (string) $invoice->invoice_number;

        $mpdf = $this->newPdf($academy);
        // ⭐ ميتاداتا عربي قابلة للبحث: UTF-16BE بلا BOM مضغوط —
        // بايتات «فاتورة» لقّيتها نصاً وصفاً في نفس PDF (اختبار).
        $mpdf->SetTitle($academy['name'].' — فاتورة '.$formattedNumber);
        // ⭐ Subject سلسلة ASCII خالصة الرقم — لاتينية قابلة للبحث.
        $mpdf->SetSubject($formattedNumber);
        $mpdf->SetAuthor($academy['name']);
        $mpdf->SetCreator('Quran Academy');

        $mpdf->WriteHTML(
            view('pdf.invoice', [
                'invoice' => $invoice,
                'academy' => $academy,
                'printNumber' => $formattedNumber,
                'qrPayload' => sprintf(
                    'INV:%s|TOT:%s %s',
                    $formattedNumber,
                    (string) $invoice->total,
                    (string) $invoice->currency,
                ),
                'issuedAt' => now()->format('Y-m-d H:i'),
            ])->render(),
        );

        return $mpdf->Output('', Destination::STRING_RETURN);
    }

    public function receiptPdf(Payment $payment): string
    {
        $payment->loadMissing(['student', 'invoice', 'receivedBy']);

        $academy = $this->academy($payment->organization_id);
        $receiptNumber = self::receiptNumber($payment);

        $mpdf = $this->newPdf($academy);
        $mpdf->SetTitle('إيصال استلام'.($academy['name'] !== '' ? ' — '.$academy['name'] : ''));
        $mpdf->SetSubject($receiptNumber);
        $mpdf->SetAuthor($academy['name']);
        $mpdf->SetCreator('Quran Academy');

        $mpdf->WriteHTML(
            view('pdf.receipt', [
                'payment' => $payment,
                'academy' => $academy,
                'printNumber' => $receiptNumber,
                'qrPayload' => sprintf(
                    'RCP:%s|AMT:%s %s',
                    $receiptNumber,
                    (string) $payment->amount,
                    (string) $payment->currency,
                ),
                'issuedAt' => now()->format('Y-m-d H:i'),
            ])->render(),
        );

        return $mpdf->Output('', Destination::STRING_RETURN);
    }

    /**
     * ⭐ رقم الإيصال — **دالة صرفة في صف الدفعة** (`id` + سنة
     * `paid_at`): `RCP-YYYY-######`. مش مخزّن — لأن أي خزّن
     * بيستهلك عدّاد عند **كل طباعة** (كل استيراد = فجوات
     * أرقام)، والدالة المصفّية تسلسلي بحد ذاتها وثابتة.
     */
    public static function receiptNumber(Payment $payment): string
    {
        return sprintf(
            'RCP-%s-%06d',
            substr((string) $payment->paid_at, 0, 4),
            (int) $payment->id,
        );
    }

    /**
     * تفاصيل الأكاديمية من **إعدادات المؤسسة**. الاسم fallback
     * عليه اسم المؤسسة نفسها إذا الإعداد فارغ (بدون ما نسمّيه
     * نصاً كاذباً في مستند رسمي).
     *
     * @return array{name: string, phone: string, email: string, address: string}
     */
    private function academy(?int $organizationId): array
    {
        $name = Setting::get('academy_name', null, $organizationId);

        if (! is_string($name) || trim($name) === '') {
            $org = $organizationId === null ? null : Organization::find($organizationId);
            $name = $org?->name ?? 'الأكاديمية';
        }

        return [
            'name' => (string) $name,
            'phone' => (string) Setting::get('academy_phone', '', $organizationId),
            'email' => (string) Setting::get('academy_email', '', $organizationId),
            'address' => (string) Setting::get('academy_address', '', $organizationId),
        ];
    }

    /**
     * تكوين mPDF: A4 بمحرك تشكيل عربي.
     * default font `dejavusans` = خط مضمّن يرسم العربي فعلياً.
     *
     * @param array{name: string, phone: string, email: string, address: string} $academy
     */
    private function newPdf(array $academy): Mpdf
    {
        return new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'default_font' => 'dejavusans',
            'default_font_size' => 11,
            'margin_left' => self::PAGE_MARGIN_MM,
            'margin_right' => self::PAGE_MARGIN_MM,
            'margin_top' => self::PAGE_MARGIN_MM,
            'margin_bottom' => self::PAGE_MARGIN_MM,
        ]);
    }
}
