<?php

namespace Tests\Feature;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * ⭐⭐⭐ **اختبار grep — يمنع رجوع القيم الحبيبة (hard-coded)
 * للقواعد التجارية داخل كود الأعمال.**
 *
 * ⭐ **القبول في PR 3.12:** «اختبار grep يمنع رجوع الأرقام
 * الحبيبة للقواعد التجارية في الخدمات».
 *
 * القيم دي نزحناها إلى `SettingsRegistry` (مركز الإعدادات)،
 * ودي هي القائمة المحظورة بعد النزح:
 *
 * • `'EGP'` — افتراضي العملة (راح للسجل `finance.default_currency`).
 * • `addDays(3|7|14)` — نوافذ الانتهاء/الاستحقاق/التنبيه
 *   (راحت للسجل: `subscriptions.expiry_warning_days` و
 *   `invoices.due_days`).
 * • `'16:00'` — التوقيت الافتراضي (في السجل `lessons.default_start_time`).
 * • `EXPIRY_WARNING_DAYS` — الثابت القديم شيلنا؛ يستبدله مفتاح
 *   `subscriptions.expiry_warning_days`.
 * • `?? 30` في المدة الافتراضية (في السجل `lessons.default_duration_minutes`).
 *
 * ⭐ استثناء: الكود تحت `@deprecated` (كود قديم غير نشط) —
 * وما سرد أي كود عمل تجاري رجع من غير النزح.
 */
class SettingsGrepTest extends TestCase
{
    /** @var array<int, string> الأنماط المحظورة في كود الأعمال */
    private const BANNED = [
        '/[\'"]EGP[\'"]/',
        '/[\'"]16:00[\'"]/',
        '/addDays\((3|7|14)\)/',
        '/EXPIRY_WARNING_DAYS/',
        '/lesson_duration_minutes \?\? 30\b/',
        '/duration_minutes\'[^\n]*\?\? ?30\b/',
    ];

    /** @var array<int, string> مسارات كود الأعمال المفحوصة */
    private const SCOPES = [
        'app/Services',
        'app/Console',
        'app/Http/Controllers/Api/SubscriptionController.php',
        'app/Http/Controllers/Api/StudentController.php',
        'app/Http/Controllers/Api/PricingController.php',
        'app/Http/Controllers/Api/ReportController.php',
    ];

    public function test_no_hard_coded_business_defaults_return_in_the_code(): void
    {
        $violations = [];

        foreach (self::SCOPES as $scope) {
            $fullPath = base_path($scope);

            if (is_file($fullPath)) {
                $violations = array_merge($violations, $this->scanFile($fullPath));
            } else {
                $files = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($fullPath, RecursiveDirectoryIterator::SKIP_DOTS),
                );

                /** @var \SplFileInfo $file */
                foreach ($files as $file) {
                    if ($file->isFile() && $file->getExtension() === 'php') {
                        $violations = array_merge($violations, $this->scanFile($file->getPathname()));
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "رجعت قيمة محببة في كود الأعمال — نزحها إلى مركز الإعدادات (SettingsRegistry):\n".implode("\n", $violations),
        );
    }

    /**
     * ⭐ فحص الملف: أسطر التعليق (docblock) مستبعدة — القيمة في
     * نص شرحّي بلا كود بلميس، والكود تحت `@deprecated`
     * (غير نشط) مسموح.
     *
     * @return array<int, string>
     */
    private function scanFile(string $path): array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        $violations = [];

        foreach ($lines as $index => $line) {
            $trimmed = trim($line);

            // ⭐ أسطر التعليق: `//`، وdocblock مفتوح `/*` أو `*` —
            // القيمة قد تكون في نص الشرح بلا كود.
            if ($trimmed === '' || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*')) {
                continue;
            }

            foreach (self::BANNED as $pattern) {
                if (preg_match($pattern, $trimmed) === 1) {
                    // ⭐ كود قديم @deprecated مسموح (غير نشط).
                    if ($this->hasDeprecatedMarkerAbove($lines, $index)) {
                        continue;
                    }

                    $rel = str_replace(base_path().'/', '', $path);
                    $violations[] = "{$rel}:{$index}: {$trimmed}";
                }
            }
        }

        return $violations;
    }

    /**
     * ⭐ هل يهنك `@deprecated` أعلى السطر (حتى 40 سطر)؟
     *
     * @param  array<int, string>  $lines
     */
    private function hasDeprecatedMarkerAbove(array $lines, int $index): bool
    {
        $from = max(0, $index - 40);

        for ($i = $index; $i >= $from; $i--) {
            if (str_contains($lines[$i], '@deprecated')) {
                return true;
            }
        }

        return false;
    }
}
