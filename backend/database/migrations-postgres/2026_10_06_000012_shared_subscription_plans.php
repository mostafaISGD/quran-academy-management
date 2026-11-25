<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PRICES = [
        'traditional' => ['تقليدي', [30 => [200, 350, 500, 650], 45 => [280, 500, 700, 900], 60 => [350, 620, 880, 1100]]],
        'golden' => ['ذهبي', [30 => [400, 700, 1000, 1300], 45 => [600, 1000, 1400, 1800], 60 => [700, 1200, 1800, 2200]]],
        'group' => ['مجموعات', [60 => [150, 250, 350, 450]]],
    ];

    public function up(): void
    {
        DB::statement('ALTER TABLE subscription_plans ALTER COLUMN program_id DROP NOT NULL');
        Schema::table('subscription_plans', function (Blueprint $table): void {
            $table->enum('category', ['traditional', 'golden', 'group', 'single'])->default('traditional');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->index(['category', 'sort_order'], 'plans_category_sort_idx');
            $table->index(['status', 'program_id'], 'plans_status_program_idx');
        });

        DB::table('subscription_plans')->where('billing_type', 'per_lesson')->update(['category' => 'single']);
        DB::table('subscription_plans')->whereNotNull('program_id')->update(['status' => 'inactive']);

        $org = DB::table('organizations')->orderBy('id')->value('id') ?? DB::table('organizations')->insertGetId([
            'name' => 'الأكاديمية', 'slug' => 'academy', 'default_currency' => 'EGP',
            'default_timezone' => 'Africa/Cairo', 'status' => 'active',
        ]);

        foreach ($this->plans($org) as $plan) {
            DB::table('subscription_plans')->insert($plan + ['created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        $org = DB::table('organizations')->orderBy('id')->value('id');
        $generatedIds = [];
        foreach ($this->plans($org) as $plan) {
            $ids = DB::table('subscription_plans')->where($plan)->pluck('id');
            if ($ids->count() > 1) {
                throw new RuntimeException('يوجد أكثر من باقة تطابق بيانات الباقة الأصلية.');
            }
            if ($ids->isNotEmpty()) {
                $generatedIds[] = $ids->first();
            }
        }

        $plans = DB::table('subscription_plans')->whereNull('program_id');
        if ((clone $plans)->whereNotIn('id', $generatedIds)->exists() ||
            DB::table('subscriptions')->whereIn('plan_id', $generatedIds)->exists()) {
            throw new RuntimeException('يلزم مراجعة الباقات المشتركة والاشتراكات المرتبطة قبل التراجع.');
        }

        $plans->whereIn('id', $generatedIds)->delete();
        Schema::table('subscription_plans', function (Blueprint $table): void {
            $table->dropIndex('plans_category_sort_idx');
            $table->dropIndex('plans_status_program_idx');
            $table->dropColumn(['category', 'sort_order']);
        });
        DB::table('subscription_plans')->update(['status' => 'active']);
        DB::statement('ALTER TABLE subscription_plans ALTER COLUMN program_id SET NOT NULL');
    }

    private function plans(?int $org): iterable
    {
        $categoryOrder = 0;
        foreach (self::PRICES as $category => [$label, $durations]) {
            $categoryOrder++;
            foreach ($durations as $minutes => $prices) {
                foreach ($prices as $index => $price) {
                    $count = 4 * ($index + 1);
                    yield [
                        'organization_id' => $org, 'program_id' => null,
                        'name' => "{$minutes} دقيقة - {$count} حصص ({$label})",
                        'billing_type' => 'monthly', 'price' => $price, 'currency' => 'EGP',
                        'lessons_count' => $count, 'lesson_duration_minutes' => $minutes,
                        'status' => 'active', 'category' => $category,
                        'sort_order' => $categoryOrder * 10 + intdiv($minutes, 15) - 1,
                        'description' => $category === 'group' ? 'حصة جماعية بسعر مخفّض' : null,
                    ];
                }
            }
        }
    }

};
