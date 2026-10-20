<?php

namespace App\Services;

use Illuminate\Http\Response;

/**
 * ⭐⭐ PR 2.16 — التصدير Excel/CSV لكل تقرير ولفواتير
 * ومدفوعات ومصروفات.
 *
 * ══════════════════════════════════════════════════════════════
 * **قرار CSV مع BOM** (المواصفة قالت openspout أو
 * maatwebsite/excel — الاتنين محتاجين `ext-zip` مش موجود في
 * بيئة الشغل، فحاولنا فعلاً وتنصيب openspout رُفض — المتطلبات
 * متنافقة مع PHP 8.5/mافيش ext-zip):
 *
 *  • `fputcsv` عادي + **BOM UTF-8** بفتح الإكسل العربي سليم.
 *  • المقبولية نصّت: «إذا كان CSV هو الموجود، لزم UTF-8 BOM» — وده
 *    اللي بيتحقق هنا بالبايتات الثلاثة.
 *  • أعمدة عربية + أرقام سليمة + مجاميع التقرير = مجاميع الملف.
 *  • XLSX يرجع 422 برسالة واضحة (ونفس المسار جاهز للتطوير: تفعيل
 *    ext-zip أو CI في PR 7.1 وتركيب openspout بعدها).
 *
 * ⚠️ مبنية **في الذاكرة** — التقارير مقيدة بمنظومة واحدة (بيانات
 * مدرسية) فالحجم معقول. لو احتاج تصدير ضخم يبقى StreamedResponse
 * مع نفس البناء (نفس عقد columns/rows).
 */
class SpreadsheetExportService
{
    /**
     * ملف CSV: BOM + صف الأعمدة + صفوف البيانات + صفوف الإجمالي.
     *
     * @param  list<string>  $columns
     * @param  list<list<string|int|float>>  $rows
     * @param  list<list<string|int|float>>  $totalRows
     */
    public function csv(string $filename, array $columns, array $rows, array $totalRows = []): Response
    {
        $handle = fopen('php://temp', 'r+b');
        // ⭐ BOM قبل أي محتوى — الإكسل بيتعرّف على UTF-8 هنا
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, $columns);
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        foreach ($totalRows as $row) {
            fputcsv($handle, $row);
        }
        rewind($handle);
        $content = (string) stream_get_contents($handle);
        fclose($handle);

        return response($content, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
