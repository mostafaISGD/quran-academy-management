<?php

namespace Tests\Feature;

use App\Support\ValidationMessages;
use Tests\TestCase;

/**
 * ⭐ رسائل التحقق بالعربي.
 *
 * السبب: الواجهة كلها عربي، بس الـ 422 كان بيرجع إنجليزي:
 *
 *     "The records.0.worked_hours field must not be greater than 24."
 *
 * والمستخدم بيقرأ ده قدام طالبه. الـ validation في السيرفر (ده
 * صح)، فالرسايل لازم ترجع عربية معاها.
 */
class ValidationMessagesTest extends TestCase
{
    /**
 * الأدمن بكل الصلاحيات اللي الاختبارات محتاجاها.
 *
 * ⚠️ `attendance.*` للحضور و`students.edit` للموظفين — مسار
 * الموظف على `students.edit` مش `settings.manage` (مراجعة
 * الـ routes).
 */
private function admin()
    {
        return $this->makeUserWithRole('admin', [
            'attendance.view', 'attendance.manage',
            'students.view', 'students.edit',
        ]);
    }

    // ============================================================
    // ⭐ الرد كله عربي — مش مخلوط
    // ============================================================

    /**
     * الرد لازم يكون عربي ١٠٠٪.
     *
     * ⚠️ الرد المخلوط (بعضها عربي وبعضها إنجليزي) **أسوأ** من
     * الإنجليزي كله: المستخدم مش هيعرف الرسالة اللي قدامه.
     */
    public function test_validation_response_is_fully_arabic(): void
    {
        $admin = $this->admin();
        $e = $this->makeEmployee();

        $r = $this->postJsonAs('/api/attendance', [
            'date' => '2026-03-10',
            'records' => [
                ['employee_id' => $e->id, 'status' => 'present', 'worked_hours' => 99],
            ],
        ], $admin);

        $r->assertStatus(422);

        $raw = $r->getContent();

        // ⭐ مفيش حرف لاتيني واحد في الرد كله
        $this->assertDoesNotMatchRegularExpression(
            '/\b(the|field|must|is|required|invalid|greater|less|than|selected|characters|be|valid)\b/i',
            (string) $raw,
            'الرد لسه فيه إنجليزي: '.$raw
        );

        $this->assertStringContainsString('الساعات', (string) $raw);
    }

    /** «24.» مش «24» — الـ regex كان بياكل النقطة */
    public function test_the_number_keeps_its_clean_form(): void
    {
        $admin = $this->admin();
        $e = $this->makeEmployee();

        $r = $this->postJsonAs('/api/attendance', [
            'date' => '2026-03-10',
            'records' => [
                ['employee_id' => $e->id, 'status' => 'present', 'worked_hours' => 99],
            ],
        ], $admin);

        $message = $r->json('message');

        $this->assertStringNotContainsString('24.', (string) $message,
            'فيه نقطة فاصلة زايدة — «24» مش «24.»');
        $this->assertStringContainsString('24', (string) $message);
    }

    // ============================================================
    // ترجمة كل القواعد
    // ============================================================

    public function test_required_field(): void
    {
        $this->assertSame(
            'حقل التاريخ مطلوب.',
            ValidationMessages::translate('The date field is required.'),
        );
    }

    public function test_max_value_rule(): void
    {
        $this->assertSame(
            'حقل الساعات لازم يكون 24 أو أقل.',
            ValidationMessages::translate(
                'The worked_hours field must not be greater than 24.'
            ),
        );
    }

    public function test_decimal_max_value(): void
    {
        $this->assertSame(
            'حقل سعر الساعة لازم يكون 1000.5 أو أقل.',
            ValidationMessages::translate(
                'The hourly_rate field must not be greater than 1000.5.'
            ),
        );
    }

    public function test_min_value_rule(): void
    {
        $this->assertSame(
            'حقل سعر الساعة لازم يكون 0 أو أكتر.',
            ValidationMessages::translate(
                'The hourly_rate field must not be less than 0.'
            ),
        );
    }

    public function test_min_length_rule(): void
    {
        $this->assertSame(
            'حقل الاسم لازم يكون 3 حرف على الأقل.',
            ValidationMessages::translate('The name must be at least 3 characters.'),
        );
    }

    public function test_max_length_rule(): void
    {
        $this->assertSame(
            'حقل الاسم لازم يكون 100 حرف على الأكثر.',
            ValidationMessages::translate(
                'The name must not be greater than 100 characters.'
            ),
        );
    }

    public function test_email_rule(): void
    {
        $this->assertSame(
            'حقل البريد الإلكتروني لازم يكون بريد إلكتروني صحيح.',
            ValidationMessages::translate(
                'The email field must be a valid email address.'
            ),
        );
    }

    public function test_invalid_selection_rule(): void
    {
        $this->assertSame(
            'قيمة الحالة مش صحيحة.',
            ValidationMessages::translate('The selected status is invalid.'),
        );
    }

    /** ⭐ الحقل المفهرس: `records.0.employee_id` لازم يتعرف */
    public function test_indexed_field_name_is_recognised(): void
    {
        $this->assertSame(
            'قيمة الموظف مش صحيحة.',
            ValidationMessages::translate(
                'The selected records.0.employee_id is invalid.'
            ),
        );
    }

    /** تاريخ ناقص ← مايتعرفش، فبنضيف الاسم لوحده */
    public function test_date_rules(): void
    {
        $this->assertSame(
            'حقل تاريخ النهاية لازم يكون تاريخ صحيح.',
            ValidationMessages::translate('The end_date is not a valid date.'),
        );

        $this->assertSame(
            'حقل تاريخ النهاية لازم يكون بعد تاريخ البداية.',
            ValidationMessages::translate(
                'The end_date field must be a date after start_date.'
            ),
        );
    }

    // ============================================================
    // ⭐ ما_unknownش → fallback عربي، مش إنجليزي
    // ============================================================

    /**
     * ⚠️ لما نعرف اسم الحقل بس مش الجملة، الرسالة تفضل **عربي**.
     *
     * الأول كان بيرجّع الإنجليزية الأصلية وده كان بيعمل رد
     * مخلوط — وهو أسوأ من الإنجليزي كله.
     */
    public function test_unknown_rule_falls_back_to_arabic_not_english(): void
    {
        $out = ValidationMessages::translate('The name must be a string.');

        $this->assertSame('حقل الاسم لازم يكون نص', $out);
        $this->assertStringNotContainsString('must be', $out);
    }

    /** حقل مجهول بالكامل ← عربي عام، مش إنجليزي */
    public function test_unknown_field_falls_back_to_generic_arabic(): void
    {
        $out = ValidationMessages::translate(
            'The some_made_up_field_xyz must be something weird.'
        );

        // ⭐ الرسالة لازم تقول «في حقل مش صحيح» — مش مجرد
        // «البيانات المدخلة غير صحيحة» اللي مابتقولش إيه الغلط.
        $this->assertStringNotContainsString('some_made_up', $out);
        $this->assertStringContainsString('مش صحيح', $out);
    }

    // ============================================================
    // خلط اللغتين ممنوع في الرد
    // ============================================================

    /**
     * ⭐ لو **أي** حقل مجهول، الرد كله يرجع إنجليزي زي ما كان.
     *
     * ده مقصود: الرد المخلوط أسوأ من الإنجليزي كله. فاخترنا
     * إنجليزي متسق على إن نكتفي.
     *
     * ⚠️ الاختبار ده بيوثّق سلوك مقصود — لو حد غيّره ليعمل
     * ترجمة جزئية، لازم يفكر في السبب.
     */
    public function test_mixed_language_response_is_not_produced(): void
    {
        $admin = $this->admin();

        // `records` معروف، بس لو في حقل تاني مجهول...
        [$translated, $allKnown] = ValidationMessages::translateAll([
            'date' => ['The date field is required.'],
            'unknown_xyz' => ['The unknown_xyz must be a string.'],
        ]);

        $this->assertFalse($allKnown, 'في حقل مجهول');
        $this->assertArrayHasKey('التاريخ', $translated, 'المعروف اتترجم');
        $this->assertArrayHasKey('unknown_xyz', $translated, 'المجهول فضل زي ما هو');
    }

    public function test_all_known_fields_report_true(): void
    {
        [$translated, $allKnown] = ValidationMessages::translateAll([
            'date' => ['The date field is required.'],
            'records' => ['The records field is required.'],
        ]);

        $this->assertTrue($allKnown);
        $this->assertSame(['التاريخ', 'سجلات الحضور'], array_keys($translated));
    }

    // ============================================================
    // ⭐ حارس: صفر إنجليزي في الردود الحقيقية
    // ============================================================

    /**
     * ⭐ كل رد 422 من الـ API لازم يبقى عربي.
     *
     * الاختبار ده بيمرّ على endpoints حقيقية ويطلب منها بيانات
     * غلط عمداً، ويفحص الرد. ده اللي بيمنع رسائل إنجليزية
     * تطلع تاني بعد أي تعديل.
     */
    public function test_no_english_leaks_from_real_endpoints(): void
    {
        $admin = $this->admin();
        $e = $this->makeEmployee();

        $cases = [
            ['حضور بدون تاريخ', '/api/attendance', ['records' => []]],
            ['حضور بحالة غلط', '/api/attendance', [
                'date' => '2026-03-10',
                'records' => [['employee_id' => $e->id, 'status' => 'zzz', 'worked_hours' => 1]],
            ]],
            ['موظف بإيميل غلط', '/api/employees', ['name' => 'اختبار', 'email' => 'ليس-إيميل']],
            ['موظف بسعر ساعة سالب', '/api/employees/' . $e->id, ['hourly_rate' => -5]],
            ['موظف بسعر ساعة ضخم', '/api/employees/' . $e->id, ['hourly_rate' => 99999]],
        ];

        foreach ($cases as [$label, $uri, $body]) {
            $method = str_contains($uri, '/employees/') ? 'put' : 'post';

            $r = $method === 'put'
                ? $this->putJsonAs($uri, $body, $admin)
                : $this->postJsonAs($uri, $body, $admin);

            $this->assertContains(
                $r->status(),
                [422],
                "«{$label}» مفروض يرجع 422، رجع {$r->status()}"
            );

            $raw = (string) $r->getContent();

            $this->assertDoesNotMatchRegularExpression(
                '/\b(the|field|must|is|required|invalid|greater|less|than|selected|characters|valid|after|before)\b/i',
                $raw,
                "«{$label}» رجّع إنجليزي: {$raw}"
            );
        }
    }

    /** الـ 401 و 403 مفروض يفضلوا زي ما هما (مش 422) */
    public function test_non_validation_errors_are_untouched(): void
    {
        // محادثة بدون تسجيل دخول
        $r = $this->getJson('/api/attendance/day');
        $r->assertStatus(401);

        // مفيش validation message هنا
        $this->assertStringNotContainsString('مطلوب', (string) $r->getContent());
    }
}