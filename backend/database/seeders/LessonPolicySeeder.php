<?php

namespace Database\Seeders;

use App\Models\LessonPolicy;
use App\Models\Organization;
use Illuminate\Database\Seeder;

use Database\Seeders\Concerns\RefusesToRunInProduction;

/**
 * ⭐⭐⭐ **PR 3.2 — زرع سياسات الحصص الافتراضية.**
 *
 * ⭐ **الافتراضات الآمنة** (من `LessonPolicy::defaults()` — مصدر
 * واحد): الحصة المكتملة هي اللي بتخصم من الطالب وبتدي أجر
 * المعلم؛ والغياب والإلغاء **ما بيخصموش** و**ما بيدفعوش**؛
 * والتعويض مسموح في الغياب (حد 4 شهرياً / نافذة 30 يوماً).
 *
 * ⭐ **idempotent**: بيشتغل على كل المؤسسات الموجودة، و
 * `firstOrCreate` — يعني التشغيل التاني ما بيعملش تكرار.
 */
class LessonPolicySeeder extends Seeder
{
    use RefusesToRunInProduction;

    public function run(): void
    {
        $this->guardProduction('LessonPolicySeeder');

        foreach (Organization::all() as $organization) {
            foreach (LessonPolicy::defaults() as $outcome => $values) {
                LessonPolicy::firstOrCreate(
                    [
                        'organization_id' => $organization->id,
                        'outcome' => $outcome,
                        'scope' => 'global',
                        'program_id' => null,
                    ],
                    $values,
                );
            }
        }
    }
}
