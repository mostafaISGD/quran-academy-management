<?php

namespace App\Services;

use App\Models\LessonPolicy;

/**
 * ⭐⭐⭐ **PR 3.2 — حلّ سياسة الحصة + التقاط اللقطة.**
 *
 * ⭐ **ترتيب الحل**: صف **البرنامج** (لو في واحد) يغلب صف
 * **الأكاديمية** العام. ولو مفيش ولا واحد — الافتراض الآمن.
 *
 * ⭐ **ليه لقطة؟** لأن «الإدارة تتحكم في كل وقت» مش معناها إن
 * التاريخ يتفسّر من جديد: الحصة اللي اتقبلت تحت سياسة معينة
 * تفضل محسوبة على نفس الأساس. فالتعديل بيغيّر **المستقبل**
 * بس، واللقطة على الحصة هي تجمّد القواعد وقت تطبيقها.
 */
final class LessonPolicyService
{
    /**
     * ⭐ **ضمان وجود السياسات** — يزرع الصفوف الناقصة بالافتراضات
     * الآمنة.
     *
     * ⭐ **ليه؟** عشان التعديل لازم يلاقي صفاً (الـAPI بيعدّل صف
     * بمعرّف) — ولو أكاديمية اتنقلت لقاعدة من غير الـseeder، ما
     * فيش صف بيتعدّل. فالزرع هنا **ذاتي وآمن** (idempotent) بدل
     * ما نرجع افتراضات عابرة ما تتعدّلش.
     */
    public function ensure(int $organizationId): void
    {
        foreach (LessonPolicy::defaults() as $outcome => $values) {
            LessonPolicy::firstOrCreate(
                [
                    'organization_id' => $organizationId,
                    'outcome' => $outcome,
                    'scope' => 'global',
                    'program_id' => null,
                ],
                $values,
            );
        }
    }

    /**
     * ⭐ السياسة المطبَّقة لنتيجة + برنامج.
     *
     * @return array<string, mixed> لقطة جاهزة للتخزين
     */
    public function resolve(int $organizationId, string $outcome, ?int $programId = null): array
    {
        $policy = $this->findPolicy($organizationId, $outcome, $programId);

        if ($policy !== null) {
            return $policy->snapshot();
        }

        // ⭐ مفيش صف مخزَّن — الافتراض الآمن (نفس مصدر الـseeder).
        $defaults = LessonPolicy::defaults()[$outcome] ?? LessonPolicy::defaults()['completed'];

        return array_merge([
            'policy_id' => null,
            'outcome' => $outcome,
            'scope' => 'global',
            'program_id' => null,
        ], $defaults);
    }

    /**
     * ⭐ البحث عن الصف: برنامج أولاً، بعدين العام.
     */
    public function findPolicy(int $organizationId, string $outcome, ?int $programId = null): ?LessonPolicy
    {
        $query = LessonPolicy::where('organization_id', $organizationId)
            ->where('outcome', $outcome);

        if ($programId !== null) {
            $scoped = (clone $query)->where('scope', 'program')
                ->where('program_id', $programId)
                ->first();

            if ($scoped !== null) {
                return $scoped;
            }
        }

        return $query->where('scope', 'global')->whereNull('program_id')->first();
    }
}
