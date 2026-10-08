<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐⭐ الأساس المالي — الخزائن والحسابات + دفتر الحركات.
 *
 * ⭐ **المشكلة اللي بتحلها:**
 *
 * قبل كده الدفعة والمصروف ودفعة المعلم كل واحد في **جدول
 * منفصل**، ومفيش مكان واحد بيقول «فين الفلوس دلوقتي». فلو
 * الأدمن قال «معانا ٥٠ ألف»، مفيش حاجة في الداتابيز تقدر
 * تتأكد أو تعترض.
 *
 * ⭐ `account_movements` هو **المصدر الوحيد للحقيقة**:
 * كل قرش داخل أو خارجAcademy بيتسجل هنا كسطر واحد، والرصيد
 * **بيتحسب** = رصيد افتتاحي + مجموع الحركات. مش رقم بيتعدّل
 * بإيد.
 *
 * ─────────────────────────────────────────────────────────────
 *
 * ⚠️⚠️ **قرار المبالغ — اقراه قبل ما تلمس حاجة:**
 *
 * الخطة طلبت `bigint` (أصغر وحدة = قرش). اختارنا نمشي بيها
 * **بس على الجداول الجديدة بس**، والجداول القديمة فاضلة
 * `decimal(12,2)`.
 *
 * السبب: التحويل للـ bigint على داتا موجودة = migration يضرب كل
 * مبلغ ×١٠٠ + تحديث كل طبقة العرض. ده شغل لوحده، ولما نعمله
 * يكون الأساس مبني ومجرّب.
 *
 * ⚠️ **النتيجة لازم نتفق عليها:** هيبقى في **رقمين** في نفس
 * السيستم. عشان كده كل الحركات بتتحوّل لـ `decimal` عند
 * الكتابة في `FinancialLedgerService` — فالمصدر الواحد ده
 * **مش مختلط** مهما كان الجدول الأصلي. ده اللي يخلّي التحويل
 * لـ bigint بعدين **عملية واحدة في مكان واحد**.
 *
 * @see \App\Services\FinancialLedgerService
 */
return new class extends Migration
{
    public function up(): void
    {
        // ============================================================
        // ① الخزائن والحسابات — «فين الفلوس»
        // ============================================================
        Schema::create('financial_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();

            $table->string('name');

            /**
             * ⭐ النوع بيحدد إزاي بيتعامل معاه.
             *
             * `cash` خزينة نقدية — بتتحقق يدوياً
             * `bank` حساب بنكي
             * `wallet` محفظة إلكترونية (فودافون كاش / إنستاباي)
             * `gateway` بوابة دفع
             */
            $table->enum('type', ['cash', 'bank', 'wallet', 'gateway', 'other'])
                ->default('cash');

            /**
             * ⚠️ **لكل حساب عملة واحدة** (قرار الخطة صريح).
             *
             * السبب: رصيد حساب بالمورو وبريال مع بعض = لازم نجمع
             * رقم من عملتين، والجمع ده **غلط** لأنه محتاج سعر صرف
             * في كل نقطة. التحويل بين العملات بيتم بسجل خاص
             * (`account_transfers`) بيحفظ سعر الصرف الفعلي.
             */
            $table->char('currency', 3);

            /**
             * ⚠️ رصيد افتتاحي، **مش رصيد حالي**.
             *
             * الفرق مهم: الرصيد الحالي بيتحسب من الحركات.
             * الافتتاحي ده بس «كان معانا كام أول يوم». لو خلطنا
             * التلاتة، أي تصحيح في فلوس قديمة كان هيكسر الرصيد
             * من غير ما نعرف.
             */
            $table->decimal('opening_balance', 14, 2)->default(0);

            $table->enum('status', ['active', 'inactive'])->default('active');

            // ⭐ ملاحظة حرة — «الحساب ده بيتقفل آخر الشهر» مثلاً
            $table->string('notes', 500)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'status']);
            // ⭐ فتحة على العملة — التقارير بتجمّع بـ `currency`
            $table->index(['organization_id', 'currency']);
        });

        // ============================================================
        // ② دفتر الحركات — المصدر الوحيد للحقيقة
        // ============================================================
        Schema::create('account_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('financial_account_id')->constrained('financial_accounts')->cascadeOnDelete();

            /**
             * ⭐ نوع الحركة.
             *
             * ⚠️ **`transfer_in`/`transfer_out` هما اللي بيقفلوا ثغرة
             * التحويل**: التحويل **مش إيراد ومش مصروف** — هو
             * حركتين. لو سجلناه كـ `income` كان التقرير هيقول
             * «الدخل زاد» وهو مجرد فلوس بتتحرك من مكان لمكان.
             */
            $table->enum('direction', ['in', 'out']);

            /**
             * التصنيف الدقيق — سبب الحركة دي.
             *
             * `income` تحصيل من طالب
             * `expense` مصروف تشغيلي
             * `teacher_payment` دفعة لمعلم
             * `refund` استرداد لطالب
             * `transfer_in`/`transfer_out` تحويل بين حساباتنا
             * `fee` عمولة بوابة دفع (بتي من جوه المبلغ)
             * `adjustment` تسوية جرد — الفرق اللي لقيناه
             */
            $table->enum('category', [
                'income', 'expense', 'teacher_payment', 'refund',
                'transfer_in', 'transfer_out', 'fee', 'adjustment',
            ]);

            /**
             * ⭐ المبلغ **دايماً موجب**. الاتجاه في `direction`.
             *
             * السبب: مبلغ سالب في جدول حركات = معادلة رصيد لازم
             * تاخد إشارة، وأول خطأ في أي تقرير هيدي نتيجة غلط.
             * فبنخزّن `abs()` والاتجاه منفصل.
             */
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3);

            /**
             * ⭐ الحركة بتتعلّق بإيه — «المصدر».
             *
             * مش FK عادي لسبب: الحركة ممكن تيجي من `payment`
             * أو `expense` أو `teacher_payment` أو `refund` أو
             * حتى جرد يدوي. فبنخزّن النوع + الرقم.
             *
             * ⚠️ **مفيش FK مقصود** — لو `payment` اتمسح، الحركة
             * المالية لازم **تفضل**. هي تاريخ مش بيانات مكرّرة.
             */
            $table->string('reference_type', 40)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();

            /**
             * تاريخ الحركة المالية — **مش** `created_at`.
             *
             * الفرق مهم: الدفعة اتسجلت امبارح بس(actual الفاتورة
             * تاريخها ١HX). التقارير بتتبني على تاريخ الحركة
             * المالي، مش على امتى اتكتب السطر.
             */
            $table->date('movement_date');

            /**
             * ⚠️ `posted` = الحالة المحاسبية.
             *
             * `pending` حركة اتسجلت بس مش معتمدة لسه — ما بتدخلش
             * الرصيد.
             * `posted` معتمدة — بتدخل الرصيد.
             *
             * ⚠️⚠️ **مفيش حالة «معكوس» عن قصد.**
             *
             * أول تصميم كان فيه `reversed` وكننا بنحطها على السطر
             * الأصلي لما يتعكس. ده غلط من اتنين:
             *
             *   ١. **بيخالف القاعدة ١** — وضع علامة على سجل تاريخي
             *      هو تعديل عليه.
             *   ٢. **معادلة الرصيد كانت بتطلع غلط** — لو الرصيد
             *      بيستبعد `reversed`، والأصلي اتقفل والعكسي
             *      اتسجّل `posted`، النتيجة = ناقص المبلغ مرتين.
             *
             * فالعكس بقى: **الأصل ما يتلمسش خالص**، والسطر الجديد
             * بنفس المبلغ والاتجاه المضاد. +1000 ناقص 1000 = صفر،
             * من غير أي استثناء في المعادلة.
             *
             * ⭐ فالمصدر للحالة إن الحركة «معكوسة» هو وجود سطر
             * عكسي (`reverses_movement_id`) — مش `status`.
             */
            $table->enum('status', ['pending', 'posted'])->default('posted');

            /**
             * الحركة اللي عكستها — للربط بس.
             */
            $table->foreignId('reverses_movement_id')->nullable()
                ->constrained('account_movements')->nullOnDelete();

            // ⭐ رابط التحويل لو كانت حركة تحويل
            $table->foreignId('account_transfer_id')->nullable()
                ->constrained('account_transfers')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            /**
             * ⭐⭐ **الفهارس اللي بتخلّي التقاريرشغالة بسرعة.**
             *
             * `balance` — أي استعلام «رصيد حساب» بيروح هنا.
             * `movement_date` — التقارير اليومية والشهرية والسنوية بتقصّ بيه.
             */
            $table->index(['financial_account_id', 'movement_date']);
            $table->index(['organization_id', 'movement_date']);
            // ⭐ التوصيل بالمصدر — «كل حركات الفاتورة دي»
            $table->index(['reference_type', 'reference_id']);
            $table->index(['status']);
        });

        // ============================================================
        // ③ التحويلات بين الحسابات
        // ============================================================
        Schema::create('account_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();

            // ⭐ الحسابين: من وإلى
            $table->foreignId('from_account_id')->constrained('financial_accounts');
            $table->foreignId('to_account_id')->constrained('financial_accounts');

            $table->decimal('amount', 14, 2);
            $table->char('currency', 3);

            /**
             * ⭐ لو العملات مختلفة: سعر الصرف **الفعلي وقت التحويل**.
             *
             * القاعدة ٣: «كل مبلغ يتخزن بعملته الأصلية ومعاه سعر
             * الصرف وقت العملية، ولا نعيد الحساب بأثر رجعي».
             * يعني لو سعر الصرف اتغير بكرة، رصيد امبارح ما يتغيّرش.
             */
            $table->decimal('exchange_rate', 12, 6)->nullable();
            $table->char('target_currency', 3)->nullable();

            $table->enum('status', ['pending', 'completed', 'cancelled'])->default('pending');
            $table->date('transfer_date');

            $table->string('reference', 100)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'transfer_date']);
        });

        // ============================================================
        // ④ أسعار الصرف
        // ============================================================
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            // ⭐ **من/إلى** مش عملة واحدة — أي زوج
            $table->char('from_currency', 3);
            $table->char('to_currency', 3);
            $table->decimal('rate', 12, 6);

            /**
             * تاريخValidity — السعر ليوم معيّن.
             *
             * السبب: التقرير القديم لازم يبقى على سعر يومه، مش على
             * السعر الحالي. فبنخزّن تاريخ مع كل سعر.
             */
            $table->date('valid_date');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // ⭐ آخر سعر لزوج معيّن — استعلام التحويل اليومي
            $table->index(['from_currency', 'to_currency', 'valid_date']);
        });
    }

    public function down(): void
    {
        // ⚠️ الترتيب مهم — `account_movements` فيه FK لـ
        // `account_transfers`، فلازم ينزل الأول
        Schema::dropIfExists('exchange_rates');
        Schema::dropIfExists('account_transfers');
        Schema::dropIfExists('account_movements');
        Schema::dropIfExists('financial_accounts');
    }
};