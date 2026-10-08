<?php

namespace Database\Seeders;

use App\Models\FinancialAccount;
use App\Models\ExchangeRate;
use Illuminate\Database\Seeder;

/**
 * ⭐ الحسابات المالية — «فين الفلوس».
 *
 * ⭐ **ليه ده Seeder مش بيانات اختبارية؟**
 *
 * الحسابات **إعداد** مش بيانات: خزينة نقدية وحساب بنك ومحفظة
 * — دي موجودة في أي أكاديمية، وأول حاجة محتاجها
 * `FinancialLedgerService` عشان يسجّل حركة.
 *
 * ⚠️ **ليه مهم إنه يبقى في Git:** من غير الحسابات دي، أي دفعة
 * ⚠️ الحسابات دي **إعداد مش بيانات**: خزينة نقدية وحساب بنك
 * ومحفظة — موجودة في أي أكاديمية، وأول حاجة `FinancialLedgerService`
 * محتاجها عشان يسجّل حركة.
 *
 * من غيرها، أي دفعة أو مصروف **مالوش مكان يروح**: يا إما
 * بنشيل الحركة (فلوس بتختفي في التقارير) يا إما بنرمي exception
 * (والتحصيل بيقف). فلو أي حد بينسخ المشروع لازم يلاقي حسابات.
 *
 * ملاحظة: كل الحسابات هنا **رصيد افتتاحي صفر**. الفلوس الحقيقية
 * بتدخل من أول تحصيل أو من جرد.
 */
class FinancialAccountsSeeder extends Seeder
{
    public function run(): void
    {
        $orgId = 1;

        /**
         * ⭐ الحسابات الأساسية بأسمائها العربية.
         *
         * `sort` مهم: `defaultAccount()` بياخد أول حساب نشط — فلو
         * ترتيبهم اتغيّر، التحصيل بيروح لمكان مختلف. الترتيب هنا
         * هو **ترتيب الترتيب الحقيقي**: خزينة قبل بنك قبل محفظة.
         */
        $accounts = [
            [
                'name' => 'الخزينة الرئيسية',
                'type' => 'cash',
                'currency' => 'EGP',
                'notes' => 'النقد فيAcademia — أول حساب استقبال للتحصيل',
            ],
            [
                'name' => 'الحساب البنكي',
                'type' => 'bank',
                'currency' => 'EGP',
                'notes' => 'تحويلات واردة وصادرة',
            ],
            [
                'name' => 'محفظة فودافون كاش',
                'type' => 'wallet',
                'currency' => 'EGP',
                'notes' => null,
            ],
            [
                'name' => 'محفظة إنستاباي',
                'type' => 'wallet',
                'currency' => 'EGP',
                'notes' => null,
            ],
            [
                'name' => 'بوابة الدفع الإلكتروني',
                'type' => 'gateway',
                'currency' => 'EGP',
                // ⭐ العمولات بتتخصم من هنا — وده سبب وجود الحساب
                'notes' => 'العمولات بتتخصم من المبلغ الواصل — بتتسجل تلقائياً',
            ],
        ];

        foreach ($accounts as $i => $row) {
            FinancialAccount::updateOrCreate(
                ['organization_id' => $orgId, 'name' => $row['name']],
                [
                    'type' => $row['type'],
                    'currency' => $row['currency'],
                    'opening_balance' => 0,
                    'status' => 'active',
                    'notes' => $row['notes'],
                ],
            );
        }

        /**
         * ⭐ سعر صرف مرجعي للدولار.
         *
        * التحويل بين حساب أمريكي وجنيه من غير سعر مسجل
         * بيرمي exception. فبنحط سعر **مرجعي** مبدئي — الأدمن
         * يعدّله من الإعدادات أول ما يعرف السعر الحقيقي.
         *
         * ⚠️ الرقم ده **مش دعوة للتحويل**. هو بيخلّي النظام ما
         * يقعش لو حد حوّل بالغلط — والمرجع الحقيقي لسعر اليوم.
         */
        ExchangeRate::updateOrCreate(
            [
                'from_currency' => 'USD',
                'to_currency' => 'EGP',
                'valid_date' => now()->startOfYear()->toDateString(),
            ],
            ['rate' => 50, 'created_by' => null],
        );

        $this->command?->info(
            '   → '.FinancialAccount::count().' حساب · سعر صرف مرجعي'
        );
    }
}
