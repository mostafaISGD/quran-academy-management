<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\SettingVersion;
use App\Services\DailySubscriptionService;
use App\Support\SettingsRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐⭐⭐ **PR 3.12 — مركز الإعدادات (الخلفية).**
 *
 * ⭐ **قبل PR ده** جدول `settings` كان شكليًا: القيمة تتقن
 * كنص json وبتتفُكّ مصفوفة دائمًا، مفيش تعريف بالنوع ولا حدود،
 * مفيش سجل نسخ، والقراءة/الحذف موش بالع حاكم٠
 *
 * ⭐ والقواعد الحية كانت مكتوبة داخل كود الخدمات: «3 أيام قبل
 * انتهاء الاشتراك»، «EGP»، «16:00»، «الاستحقاق بعد 7 أو 14».
 *
 * ⭐ **القبول:**
 *
 * ① تغيير `subscriptions.expiry_warning_days` بغيّر التنبيه في
 *   الخدمة اليومية والداشبورد دهًّا.
 * ② اختبار grep يمنع رجوع القيم الحملة (hard-coded)
 *    داخل الخدمات.
 * ③ `SettingController` يتحقق من النوع والحدود، يسجّل
 *    (يغَصْ/جَدِيد/الْأَدَاء) ويحفظ نسخًا للرجْعَة، ويعسم
 *    القراءة والحذف بحدود المؤسسة.
 */
class SettingsCenterTest extends TestCase
{
    use RefreshDatabase;

    // ═══════════════════════════════════════════════════════════════
    // ① السجل — تعريفات بالأنواع والافتراضيات والحدود
    // ═══════════════════════════════════════════════════════════════

    public function test_the_registry_defines_types_limits_groups_and_defaults(): void
    {
        $org = $this->makeUser('موظف', 'reg-admin@academy.test')->organization_id;

        // ⭐ مفيش صف في القاعدة — القيمة بتطلع من تعريف السجل.
        $this->assertSame(3, settings()->get('subscriptions.expiry_warning_days', $org));

        $definition = SettingsRegistry::definition('subscriptions.expiry_warning_days');

        $this->assertSame('int', $definition['type']);
        $this->assertSame(3, $definition['default']);
        $this->assertSame(0, $definition['min']);
        $this->assertArrayHasKey('max', $definition);
        $this->assertArrayHasKey('group', $definition);
        $this->assertArrayHasKey('label', $definition);

        // ⭐ مفتاح غير معرّف = رفض (السجل هو المصدر المعتمد).
        $this->expectException(\InvalidArgumentException::class);
        settings()->get('not.a.real.key', $org);
    }

    public function test_the_registry_service_casts_types(): void
    {
        $org = $this->makeUser('موظف', 'cast-admin@academy.test')->organization_id;

        // ⭐ مفتاح نصي: 'EGP' تتخزّن نص وتيرجع نص (مش json_decode
        // اللي برجع null) — وتنص قيمّة بِس ما تتخنش.
        Setting::set('finance.default_currency', 'EGP', $org);
        $this->assertSame('EGP', settings()->get('finance.default_currency', $org));

        // ⭐ مفتاح رقمي: 7 int بتقرأ 7 (int) مش '7'.
        Setting::set('subscriptions.expiry_warning_days', 7, $org);
        $this->assertSame(7, settings()->get('subscriptions.expiry_warning_days', $org));
    }

    // ═══════════════════════════════════════════════════════════════
    // ② تغيير المفتاح بغيّر السلوك فْعًلا (القبول الأم)
    // ═══════════════════════════════════════════════════════════════

    public function test_changing_expiry_warning_days_changes_the_dashboard_alerts(): void
    {
        $staff = $this->makeUserWithRole('dash-reader', [
            'settings.update', 'settings.view', 'reports.view',
        ]);
        $org = $staff->organization_id;
        $program = $this->makeProgram();
        $this->makeSubscription($program, $this->makeStudent(), [
            'auto_renew' => false,
            'end_date' => now()->copy()->addDays(6)->toDateString(),
        ]);

        // ⭐ قبل التغيير (الافتراضي 3): اشتراك بينتهي بعد 6 يوم — **برا** النافذة.
        $before = $this->getJsonAs('/api/dashboard/summary', $staff)->assertOk();
        $this->assertSame(0, $before->json('alerts.subscriptions_expiring_soon'));

        // ⭐ بعد التغيير (7 أيام): نفس الاشتراك هنا يدخل النافذة.
        Setting::set('subscriptions.expiry_warning_days', 7, $org);
        $after = $this->getJsonAs('/api/dashboard/summary', $staff)->assertOk();
        $this->assertSame(
            1,
            $after->json('alerts.subscriptions_expiring_soon'),
            'تغيير المفتاح ما غيّرّش نافذة تنبيه الداشبورد',
        );
    }

    public function test_changing_expiry_warning_days_changes_the_daily_notifications(): void
    {
        $staff = $this->makeUserWithRole('daily-reader', ['settings.update']);
        $org = $staff->organization_id;
        $program = $this->makeProgram();
        $this->makeSubscription($program, $this->makeStudent(), [
            'auto_renew' => false,
            'end_date' => now()->copy()->addDays(6)->toDateString(),
        ]);

        // ⭐ النافذة الافتراضية 3: ما بيتمش إشعار «هينتهي» لاشتراك بعد 6 أيام.
        $low = app(DailySubscriptionService::class)->run();
        $this->assertSame([], $low['expiring_notified']);

        Setting::set('subscriptions.expiry_warning_days', 7, $org);

        $high = app(DailySubscriptionService::class)->run();
        $this->assertCount(
            1,
            $high['expiring_notified'],
            'رفع النافذة لـ7 ما وسّعش إشعار «هينتهي»',
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // ③ قراءة الإعدادات — المجموعة + الميتاداتا
    // ═══════════════════════════════════════════════════════════════

    public function test_the_settings_controller_reads_typed_values_with_metadata(): void
    {
        $staff = $this->makeUserWithRole('settings-reader', ['settings.view']);

        $r = $this->getJsonAs('/api/settings', $staff)->assertOk();

        // ⭐ مفتاح الإعداد نفسه فيه نقاط — فما نخسّ `->json('a.b')`
        // (النقاط محجوزة للتداخل)، بنسحب الرد كله وننط منه.
        $payload = $r->json();
        $row = $payload['subscriptions.expiry_warning_days'] ?? null;

        $this->assertIsArray($row, 'السجل بيرجّع مفتاح مُنظَّم بقيمة وميتاداتا');
        $this->assertSame(3, $row['value']);
        $this->assertSame('int', $row['type']);
        $this->assertArrayHasKey('label', $row);
        $this->assertArrayHasKey('group', $row);
        $this->assertArrayHasKey('default', $row);

        // ⭐ القراءة بمفتاح مفرد.
        $single = $this->getJsonAs('/api/settings?key=subscriptions.expiry_warning_days', $staff)->assertOk();
        $this->assertSame(3, $single->json('value'));
        $this->assertSame('subscriptions.expiry_warning_days', $single->json('key'));
    }

    // ═══════════════════════════════════════════════════════════════
    // ④ التخزين — تحقق النوع/الحدود + سجل (قديم/جديد/فَاعِل) + نسخ
    // ═══════════════════════════════════════════════════════════════

    public function test_the_settings_controller_updates_typed_values_and_logs_versions(): void
    {
        $admin = $this->makeUserWithRole('settings-writer', ['settings.update']);
        $org = $admin->organization_id;

        $this->postJsonAs('/api/settings', [
            'key' => 'subscriptions.expiry_warning_days',
            'value' => 5,
        ], $admin)->assertStatus(201);

        $this->assertSame(
            5,
            settings()->get('subscriptions.expiry_warning_days', $org),
            'القيمة ما اتخزّنتش',
        );

        // ⭐ السجل «قديم/جديد/فَاعِل» — أول نسخة: مفيش قديم → 5.
        $versions = SettingVersion::where('organization_id', $org)
            ->where('key', 'subscriptions.expiry_warning_days')
            ->orderBy('id')
            ->get();

        $this->assertCount(1, $versions, 'أول نسخة');
        $this->assertNull($versions[0]->old_value);
        $this->assertSame(5, $versions[0]->new_value);
        $this->assertSame($admin->id, $versions[0]->actor_id, 'الفَاعِل مش مسجل');

        // ⭐ تعديل تاني بنسخة جديدة مستقلة.
        $this->postJsonAs('/api/settings', [
            'key' => 'subscriptions.expiry_warning_days',
            'value' => 9,
        ], $admin)->assertStatus(201);

        $this->assertSame(9, settings()->get('subscriptions.expiry_warning_days', $org));
        $this->assertSame(
            2,
            SettingVersion::where('organization_id', $org)
                ->where('key', 'subscriptions.expiry_warning_days')
                ->count(),
        );
    }

    public function test_the_value_type_and_limits_are_enforced(): void
    {
        $admin = $this->makeUserWithRole('limits-writer', ['settings.update']);

        // ⭐ قيمة نصية في مفتاح رقمي = 422.
        $this->postJsonAs('/api/settings', [
            'key' => 'subscriptions.expiry_warning_days',
            'value' => 'next-week',
        ], $admin)->assertStatus(422);

        // ⭐ فوق الحد الأعلى = 422.
        $this->postJsonAs('/api/settings', [
            'key' => 'subscriptions.expiry_warning_days',
            'value' => 999,
        ], $admin)->assertStatus(422);

        // ⭐ مفتاح غير معرّف في السجل = 422.
        $this->postJsonAs('/api/settings', [
            'key' => 'random.thing.enabled',
            'value' => true,
        ], $admin)->assertStatus(422);

        $this->assertSame(0, SettingVersion::count(), 'رفوض سجّل نسخ');
    }

    public function test_writing_the_same_value_does_not_log_a_duplicate_version(): void
    {
        $admin = $this->makeUserWithRole('idem-writer', ['settings.update']);
        $org = $admin->organization_id;

        $this->postJsonAs('/api/settings', [
            'key' => 'subscriptions.expiry_warning_days',
            'value' => 5,
        ], $admin)->assertStatus(201);

        // ⭐ نفس القيمة تانية = نسخة مش مكرره (ما تلاسش التاريخ).
        $this->postJsonAs('/api/settings', [
            'key' => 'subscriptions.expiry_warning_days',
            'value' => 5,
        ], $admin)->assertStatus(201);

        $this->assertSame(
            1,
            SettingVersion::where('organization_id', $org)->count(),
            'نفس القيمة سجّلت نسخة تانية',
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // ⑤ المؤسسة — القراءة والحذف بتختص المؤسسة
    // ═══════════════════════════════════════════════════════════════

    public function test_the_settings_index_is_scoped_to_the_organization(): void
    {
        $adminA = $this->makeUserWithRole('org-a-writer', ['settings.update', 'settings.view']);
        $orgA = $adminA->organization_id;

        // ⭐ **مؤسسة تانية فعلًا** — الحصريات في كل الاختبارات
        // بالمؤسسة رقم 1؛ هنا الخدمة بـ org مختلفة.
        $otherOrg = \App\Models\Organization::create([
            'name' => 'أكاديمية تانية',
            'slug' => 'other-academy-312',
            'default_currency' => 'EGP',
            'default_timezone' => 'Africa/Cairo',
            'status' => 'active',
        ]);
        $adminB = $this->makeUserWithRole('org-b-reader', ['settings.view']);
        $adminB->update(['organization_id' => $otherOrg->id]);
        $orgB = (int) $otherOrg->id;

        $this->postJsonAs('/api/settings', [
            'key' => 'subscriptions.expiry_warning_days',
            'value' => 7,
        ], $adminA)->assertStatus(201);

        // ⭐ مؤسسة A قديمتها 7، ومؤسسة B لسه ترجع الافتراضي 3.
        $this->assertSame(7, settings()->get('subscriptions.expiry_warning_days', $orgA));
        $this->assertSame(3, settings()->get('subscriptions.expiry_warning_days', $orgB));

        // ⭐ والـAPI كذلك — بنقرا الرد كيلًا (المفتاح فيه نقاط).
        $list = $this->getJsonAs('/api/settings', $adminB)->assertOk();
        $payload = $list->json();
        $this->assertSame(3, $payload['subscriptions.expiry_warning_days']['value']);
    }

    public function test_the_settings_destroy_is_scoped_to_the_organization(): void
    {
        $adminA = $this->makeUserWithRole('org-a-del', ['settings.update', 'settings.view']);
        $orgA = $adminA->organization_id;

        $otherOrg = \App\Models\Organization::create([
            'name' => 'أكاديمية حذف تانية',
            'slug' => 'other-del-312',
            'default_currency' => 'EGP',
            'default_timezone' => 'Africa/Cairo',
            'status' => 'active',
        ]);
        $adminB = $this->makeUserWithRole('org-b-del', ['settings.update']);
        $adminB->update(['organization_id' => $otherOrg->id]);
        $orgB = (int) $otherOrg->id;

        $this->postJsonAs('/api/settings', ['key' => 'subscriptions.expiry_warning_days', 'value' => 7], $adminA)->assertStatus(201);
        $this->postJsonAs('/api/settings', ['key' => 'subscriptions.expiry_warning_days', 'value' => 2], $adminB)->assertStatus(201);

        // ⭐ مفتاح غير معرّف في القراءة المفردة = 404 (النقاط
        // تضاف query فما تختارش راوت تاني).
        $this->getJsonAs('/api/settings?key=non.existent.key', $adminA)->assertNotFound();

        $this->deleteJsonAs('/api/settings/subscriptions.expiry_warning_days', $adminA)->assertOk();

        $this->assertSame(3, settings()->get('subscriptions.expiry_warning_days', $orgA), 'الحذف بترجع الافتراضي');
        $this->assertSame(
            2,
            settings()->get('subscriptions.expiry_warning_days', $orgB),
            'الحذف مسح صف مؤسسة تانية',
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // ⑥ الرجوع لنسخة سابقة (rollback)
    // ═══════════════════════════════════════════════════════════════

    public function test_rollback_restores_the_previous_value(): void
    {
        $admin = $this->makeUserWithRole('rollback-admin', ['settings.update', 'settings.view']);
        $org = $admin->organization_id;
        $key = 'subscriptions.expiry_warning_days';

        $this->postJsonAs('/api/settings', ['key' => $key, 'value' => 5], $admin)->assertStatus(201);
        $this->postJsonAs('/api/settings', ['key' => $key, 'value' => 9], $admin)->assertStatus(201);

        // ⭐ الرجوع: 9 → 5 (القيمة الفورية قبل آخر تعديل).
        $r = $this->postJsonAs("/api/settings/{$key}/rollback", [], $admin)->assertOk();
        $this->assertSame(5, $r->json('value'));

        // ⭐ الرجوع تاني: 5 → الافتراضي 3 (أول نسخة كانت old=null).
        $r = $this->postJsonAs("/api/settings/{$key}/rollback", [], $admin)->assertOk();
        $this->assertSame(3, $r->json('value'));

        // ⭐ المسار محمي بصلاحية التعديل.
        $reader = $this->makeUserWithRole('rollback-viewer', ['settings.view']);
        $this->postJsonAs("/api/settings/{$key}/rollback", [], $reader)->assertStatus(403);
    }
}
