<?php

namespace App\Console\Commands;

use App\Services\FinanceReconcileService;
use Illuminate\Console\Command;

/**
 * PR 2.9 — `finance:reconcile` (يومي 3 صباحاً).
 *
 * الأرقام بتتلف: حد يعدل عمود من غير الخدمة، سطر مزروع،
 * حركة من غير مصدر. الخدمة بتقارن المحفوظ بمصادره وتكتب
 * تنبيهات مفتوحة في `finance_alerts` — نفس التنبيه ما يتكررش.
 */
class FinanceReconcileCommand extends Command
{
    protected $signature = 'finance:reconcile {--org= : معرّف مؤسسة واحدة}';

    protected $description = 'تسوية المالية: فواتير/توزيعات، دفتر الطالب، حركات الحسابات، أرباح المعلمين';

    public function handle(FinanceReconcileService $service): int
    {
        $org = $this->option('org') !== null && $this->option('org') !== ''
            ? (int) $this->option('org')
            : null;

        $counts = $service->reconcile($org);

        $total = array_sum($counts);

        if ($total === 0) {
            $this->info('التسوية سليمة — مفيش انحرافات.');

            return self::SUCCESS;
        }

        foreach ($counts as $kind => $count) {
            if ($count > 0) {
                $this->warn($kind.': '.$count.' تنبيه');
            }
        }

        $this->info('التسوية اكتملت — إنذارات جديدة: '.$total);

        return self::SUCCESS;
    }
}
