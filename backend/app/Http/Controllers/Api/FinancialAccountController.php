<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccountMovement;
use App\Models\FinancialAccount;
use App\Models\PeriodLock;
use App\Services\AuditLogService;
use App\Services\FinancialLedgerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ⭐⭐ الخزائن والحسابات + دفتر الحركات.
 *
 * ⭐ **الصفحة دي بتجيب إيه للمستخدم؟**
 *
 * قبل كده لو الأدمن عايز يعرف «معانا فلوس كام»، مافيش مكان
 * بيقوله. دلوقتي: كل حساب برصيده المحسوب، وكل حركة وراها،
 * وتحويل بين الحسابات.
 *
 * ⭐ **الرصيد بيتحسب، مش بيتقرا من عمود.** عشان كده
 * `present()` بتنادي `balance()` اللي بتعمل `SUM` — فأي
 * تصحيح على حركة قديمة بيظهر في الرصيد على طول.
 */
class FinancialAccountController extends Controller
{
    // ============================================================
    // ① الحسابات
    // ============================================================

    /**
     * كل الخزائن والحسابات مع أرصدتها.
     *
     * ⭐ `status` و `currency` بيتفلتروا — مش كل صفحة محتاجة
     * تحسب `SUM` لكل حساب.
     */
    public function index(Request $request)
    {
        $accounts = FinancialAccount::where('organization_id', $request->user()->organization_id)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = $request->string('search');
                $q->where('name', 'like', "%{$s}%");
            })
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $accounts->map(fn ($a) => $this->present($a, $request))->all(),
            'meta' => [
                'total' => $accounts->count(),
                /**
                 * ⭐ إجمالي **الجنيه** بس.
                 *
                 * ⚠️ المجموع هنا مش رقم محاسبي — الحسابات ممكن
                 * تعمل currencies مختلفة (القاعدة: كل حساب عملة
                 * واحدة). فجمعهم بيحتاج سعر صرف، والسعر بيتغيّر.
                 *
                 * فبنقول ده صراحةً في الرد بدل ما نعرض رقم
                 * مضلّل. الصفحة بتعرض الإجمالي **لكل عملة لوحدها**.
                 */
                'by_currency' => $accounts
                    ->groupBy('currency')
                    ->map(fn ($rows) => round($rows->sum(fn ($a) => $a->balance()), 2))
                    ->all(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'type' => 'required|in:cash,bank,wallet,gateway,other',
            'currency' => 'required|string|size:3',
            /**
             * ⭐ رصيد افتتاحي — مش رصيد حالي.
             *
             * التسمية مقصودة عشان اللي بيدخل الرقم فاهم إنه
             * «كان معانا كام أول يوم»، مش «دلوقتي معانا كام».
             */
            'opening_balance' => 'nullable|numeric',
            'status' => 'nullable|in:active,inactive',
            'notes' => 'nullable|string|max:500',
        ]);

        $data['organization_id'] = $request->user()->organization_id;
        $data['opening_balance'] = $data['opening_balance'] ?? 0;

        $account = FinancialAccount::create($data);

        app(AuditLogService::class)->logCreate(
            'financial_account',
            $account->id,
            ['name' => $account->name, 'type' => $account->type, 'opening_balance' => $account->opening_balance],
            $request,
        );

        return response()->json([
            'message' => 'اتعمل الحساب',
            'data' => $this->present($account->fresh(), $request),
        ], 201);
    }

    public function update(Request $request, FinancialAccount $account)
    {
        abort_if($account->organization_id !== $request->user()->organization_id, 404);

        $data = $request->validate([
            'name' => 'sometimes|required|string|max:120',
            'type' => 'sometimes|required|in:cash,bank,wallet,gateway,other',
            'status' => 'nullable|in:active,inactive',
            'notes' => 'nullable|string|max:500',
        ]);

        /**
         * ⭐⭐⭐ **`opening_balance` و `currency` مش قابلين للتعديل — عن قصد.**
         *
         * ⭐ **السبب:** الرصيد الافتتاحي جزء من **تاريخ الحساب**.
         * لو حد غيّره، الأرصدة القديمة هتتغيّر بأثر رجعي
         * والضريبة جت. التصحيح الصح = **تسوية** في الدفتر (سطر
         * وسبب)، مش تعديل رقم قديم.
         *
         * ⚠️⚠️ **ليه الفحص يدوي مش في `validate()`:**
         *
         * `validate()` بيسيب **الحقول اللي مش معروفة** في
         * الطلب من غير ما يرمي. يعني لو اكتفينا إنهوا الحقل
         * من الـ rules، حد يبعت `opening_balance` والطلب هينجح
         * بـ **200** والحقل يتجاهل بصمت.
         *
         * التجاهل الصامت أسوأ من الرفض الصريح: الـ API يقول
         * «اتحفظ» والمستخدم معتبر إن الرصيد اتغيّر.
         *
         * فالرفض لازم يكون **مفهوم** ومنOPY.
         *
         * @see \Tests\Feature\AccountsApiTest::test_the_opening_balance_cannot_be_edited_after_creation
         */
        foreach (['opening_balance', 'currency'] as $frozen) {
            if ($request->has($frozen)) {
                return response()->json([
                    'message' => $frozen === 'opening_balance'
                        ? 'الرصيد الافتتاحي مش قابل للتعديل بعد الإنشاء — اعمل تسوية في الدفتر'
                        : 'عملة الحساب مش قابلة للتعديل بعد الإنشاء',
                ], 422);
            }
        }

        $old = $account->only(array_keys($data));
        $account->update($data);

        app(AuditLogService::class)->logUpdate(
            'financial_account',
            $account->id,
            $old,
            $data,
            $request,
        );

        return response()->json([
            'message' => 'اتحفظ الحساب',
            'data' => $this->present($account->fresh(), $request),
        ]);
    }

    /**
     * ⭐ حساب واحد + حركاته.
     */
    public function show(Request $request, FinancialAccount $account)
    {
        abort_if($account->organization_id !== $request->user()->organization_id, 404);

        return response()->json([
            'data' => $this->present($account, $request),
        ]);
    }

    // ============================================================
    // ② دفتر الحركات
    // ============================================================

    /**
     * ⭐ حركات حساب — أو حركات **كل** الحسابات.
     *
     * ⚠️ ملاحظة مهمة: الفلتر بالحساب **اختياري**. من غيره
     * الصفحة بتعرض «كل حركة فلوس في الأكاديمية» — وده العرض
     * الافتراضي المفيد للأدمن («فين الفلوس تتحرك؟»).
     */
    public function movements(Request $request)
    {
        $query = AccountMovement::query()
            ->where('organization_id', $request->user()->organization_id)
            ->with('account:id,name,type')
            ->when($request->filled('account_id'), fn ($q) => $q->where('financial_account_id', $request->integer('account_id')))
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->string('category')))
            ->when($request->filled('direction'), fn ($q) => $q->where('direction', $request->string('direction')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('from'), fn ($q) => $q->where('movement_date', '>=', $request->string('from')))
            ->when($request->filled('to'), fn ($q) => $q->where('movement_date', '<=', $request->string('to')))
            // ⭐ البحث: الوصف أو المرجع
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = $request->string('search');
                $q->where(function ($sub) use ($s) {
                    $sub->where('notes', 'like', "%{$s}%")
                        ->orWhere('reference_type', 'like', "%{$s}%");
                });
            })
            // الأحدث فوق — عشان الأدمن يشوف آخر حركة فوراً
            ->orderByDesc('movement_date')
            ->orderByDesc('id');

        $perPage = $request->integer('per_page') ?: 100;
        $movements = $query->paginate($perPage);

        return response()->json([
            'data' => $movements->getCollection()
                ->map(fn ($m) => $this->presentMovement($m))
                ->all(),
            'meta' => [
                'current_page' => $movements->currentPage(),
                'last_page' => $movements->lastPage(),
                'per_page' => $movements->perPage(),
                'total' => $movements->total(),

                /**
                 * ⭐ إجماليات الفترة — عشان الشاشة تعرض
                 * «دخل الفترة / خرج الفترة» من غير ما agregه في
                 * المتصفح (صفحة ١٠٠ صف مش كل الـ database).
                 */
                'totals' => [
                    'in' => round((float) $query->clone()
                        ->where('direction', 'in')
                        ->where('status', 'posted')
                        ->sum('amount'), 2),
                    'out' => round((float) $query->clone()
                        ->where('direction', 'out')
                        ->where('status', 'posted')
                        ->sum('amount'), 2),
                ],
            ],
        ]);
    }

    // ============================================================
    // ③ التحويل
    // ============================================================

    /**
     * ⭐ تحويل بين حسابين — **حركتان مرتبطتان**.
     *
     * مش إيراد ومش مصروف. وشوف `FinancialLedgerService::transfer`.
     */
    public function transfer(Request $request)
    {
        $data = $request->validate([
            'from_account_id' => 'required|integer|exists:financial_accounts,id',
            'to_account_id' => 'required|integer|exists:financial_accounts,id',

            /**
             * ⭐ المبلغ **دايماً موجب**.
             *
             * السبب: `from` و `to` بيقولوا الاتجاه. لو قبلنا
             * رقم سالب كنا هنعمل اتجاهين متضادين من غير ما
             * حد ياخد باله.
             */
            'amount' => 'required|numeric|min:0.01',

            'transfer_date' => 'nullable|date',
            'reference' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:500',
        ], [
            'amount.min' => 'المبلغ لازم يكون أكبر من صفر',
        ]);

        if ($data['from_account_id'] === $data['to_account_id']) {
            return response()->json([
'message' => 'المصدر والوجهة لازم يكونوا حسابين مختلفين',
            ], 422);
        }

        $orgId = $request->user()->organization_id;

        $from = FinancialAccount::where('organization_id', $orgId)
            ->findOrFail($data['from_account_id']);
        $to = FinancialAccount::where('organization_id', $orgId)
            ->findOrFail($data['to_account_id']);

        try {
            $transfer = app(FinancialLedgerService::class)->transfer(
                from: $from,
                to: $to,
                amount: (float) $data['amount'],
                transferDate: $data['transfer_date'] ?? null,
                notes: $data['notes'] ?? null,
                user: $request->user(),
                reference: $data['reference'] ?? null,
            );
        } catch (\RuntimeException $e) {
            // ⭐ رصيد مش مكفي / فترة مقفولة / مفيش سعر صرف
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'عمل التحويل',
            'data' => [
                'id' => $transfer->id,
                'amount' => (float) $transfer->amount,
                'currency' => $transfer->currency,
                'exchange_rate' => $transfer->exchange_rate !== null ? (float) $transfer->exchange_rate : null,
                'target_currency' => $transfer->target_currency,
                'received_amount' => $transfer->receivedAmount(),
                'from' => $this->present($from->fresh(), $request),
                'to' => $this->present($to->fresh(), $request),
            ],
        ], 201);
    }

    /**
     * ⭐⭐ عكس حركة — **المدير بس** (`finance.reverse`).
     *
     * السطر الأصلي **ما بيتلمسش**؛ بيتعمل سطر عكسي جديد.
     */
    public function reverse(Request $request, AccountMovement $movement)
    {
        $data = $request->validate([
            'reason' => 'required|string|max:255',
        ], [
            'reason.required' => 'سبب العكس إجباري — من غيره محدش هيعرف ليه',
        ]);

        abort_if($movement->organization_id !== $request->user()->organization_id, 404);

        try {
            $reversal = app(FinancialLedgerService::class)->reverse(
                $movement,
                $data['reason'],
                $request->user(),
            );
        } catch (\LogicException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            // ⭐ الفترة مقفولة — دي حالة محاسبية مش خطأ تقني
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'اتعكس السطر',
            'data' => $this->presentMovement($reversal),
        ], 201);
    }

    // ============================================================
    // ④ قفل الفترات
    // ============================================================

    /**
     * الفترات المقفولة.
     */
    public function locks(Request $request)
    {
        $locks = PeriodLock::where('organization_id', $request->user()->organization_id)
            ->with(['account:id,name', 'lockedBy:id,name', 'unlockedBy:id,name'])
            ->orderByDesc('period_start')
            ->get()
            ->map(fn ($l) => [
                'id' => $l->id,
                'scope' => $l->scope,
                'account' => $l->account ? ['id' => $l->account->id, 'name' => $l->account->name] : null,
                'period_start' => $l->period_start->toDateString(),
                'period_end' => $l->period_end->toDateString(),
                'label' => $l->label,
                'reason' => $l->reason,
                'is_active' => $l->isActive(),
                'locked_by' => $l->lockedBy?->name,
                'locked_at' => $l->locked_at?->toIso8601String(),
                'unlocked_by' => $l->unlockedBy?->name,
                'unlocked_at' => $l->unlocked_at?->toIso8601String(),
                'unlock_reason' => $l->unlock_reason,
            ]);

        return response()->json(['data' => $locks]);
    }

    /**
     * ⭐ قفل فترة — **`finance.periods.lock`**.
     *
     * ⚠️ القفل **مش** بيمنع التحصيل — بيمنع **التسجيل**.
     * شوف `FinancialLedgerService::recordFor`.
     */
    public function lockPeriod(Request $request)
    {
        $data = $request->validate([
            'scope' => 'nullable|in:all,account,payroll,expenses',
            'account_id' => 'nullable|integer|exists:financial_accounts,id',
            'start' => 'required|date',
            'end' => 'nullable|date',
            'reason' => 'nullable|string|max:500',
        ]);

        $orgId = $request->user()->organization_id;

        // ⭐ من غير `end`، بيقفل **يوم واحد** بس
        $start = $data['start'];
        $end = $data['end'] ?? $data['start'];

        if ($end < $start) {
            return response()->json([
                'message' => 'تاريخ النهاية قبل تاريخ البداية',
            ], 422);
        }

        $scope = $data['scope'] ?? 'all';

        // ⭐ `scope = account` من غير حساب = بلاش
        if ($scope === 'account' && empty($data['account_id'])) {
            return response()->json([
                'message' => 'اختار الحساب اللي عايز تقفله',
            ], 422);
        }

        $lock = PeriodLock::lock(
            organizationId: $orgId,
            start: $start,
            end: $end,
            scope: $scope,
            accountId: $data['account_id'] ?? null,
            reason: $data['reason'] ?? null,
            userId: $request->user()->id,
        );

        app(AuditLogService::class)->logCreate(
            'period_lock',
            $lock->id,
            ['scope' => $scope, 'start' => $start, 'end' => $end, 'reason' => $data['reason'] ?? null],
            $request,
        );

        return response()->json(['message' => 'اتقفلت الفترة', 'data' => ['id' => $lock->id]], 201);
    }

    /**
     * ⭐ فتح فترة — **المدير بس** (`finance.periods.unlock`).
     *
     * ⭐ قفل `locked_by` **بيفضل محفوظ** — بنضيف `unlocked_by`
     * مش بنمسح القفل. السبب: «مين فتح وإمتى» معلومة محاسبية.
     */
    public function unlockPeriod(Request $request, PeriodLock $lock)
    {
        $data = $request->validate([
            'reason' => 'required|string|max:255',
        ], [
            'reason.required' => 'سبب الفتح إجباري',
        ]);

        abort_if($lock->organization_id !== $request->user()->organization_id, 404);

        if (! $lock->isActive()) {
            return response()->json([
                'message' => 'الفترة دي مفتوحة أصلاً',
            ], 422);
        }

        $lock->unlock($data['reason'], $request->user()->id);

        app(AuditLogService::class)->logUpdate(
            'period_lock',
            $lock->id,
            ['is_active' => true],
            ['is_active' => false, 'reason' => $data['reason']],
            $request,
        );

        return response()->json(['message' => 'اتفتحت الفترة']);
    }

    // ============================================================
    // العرض
    // ============================================================

    private function present(FinancialAccount $a, Request $request): array
    {
        // ⭐ الفترة (لو مبعوتة) عشان الأرقام تطلع «صحيحة»؟
        // الرصيد دايماً **الحالي** — الفترة بتتحسب للحركات بس
        $from = $request->query('from');
        $to = $request->query('to');

        return [
            'id' => $a->id,
            'name' => $a->name,
            'type' => $a->type,
            'type_label' => $this->typeLabel($a->type),
            'currency' => $a->currency,
            'status' => $a->status,
            'notes' => $a->notes,

            'opening_balance' => (float) $a->opening_balance,

            // ⭐ الرصيد **دايماً** محسوب من الحركات
            'balance' => $a->balance($from, $to),

            // ⭐ إجمالي الفترة — مش رصيد الفترة
            'total_in' => $a->totalIn($from, $to),
            'total_out' => $a->totalOut($from, $to),

            'movements_count' => $a->movements()->count(),
            'pending_count' => $a->movements()->where('status', 'pending')->count(),

            /**
             * ⭐ هل الفترة دي (من/لـ) مقفولة؟
             *
             * عشان الشاشة تقدر تحطّ 🔒 على الأرقام وتقول «دي فترة
             * مقفولة» بدل ما تعرضها عادي والأدمن يفكر إنها قابلة
             * للتعديل.
             */
            'locked' => $from && $to
                ? app(FinancialLedgerService::class)->isLocked($a, $from)
                : false,
        ];
    }

    private function presentMovement(AccountMovement $m): array
    {
        return [
            'id' => $m->id,
            'account' => $m->account ? [
                'id' => $m->account->id,
                'name' => $m->account->name,
                'type' => $m->account->type,
            ] : null,
            'direction' => $m->direction,
            'category' => $m->category,
            'category_label' => $this->categoryLabel($m->category),
            'amount' => (float) $m->amount,
            'currency' => $m->currency,
            'reference_type' => $m->reference_type,
            'reference_id' => $m->reference_id,
            'movement_date' => $m->movement_date->toDateString(),
            'status' => $m->status,
            'notes' => $m->notes,

        // ⭐ العكس — عشان الشاشة تعرض «معكوس» بدل ما
            // السطر الأصلي يبان كأنه عادي
            'is_reversed' => $m->isReversed(),
            'reversal_of' => $m->reverses_movement_id,
            'has_reversal' => $m->hasReversal(),
        ];
    }

    private function typeLabel(string $type): string
    {
        return match ($type) {
            'cash' => 'نقدية',
            'bank' => 'بنك',
            'wallet' => 'محفظة',
            'gateway' => 'بوابة دفع',
            default => 'أخرى',
        };
    }

    /**
     * ⭐ أسماء الفئات بالعربي.
     *
     * ⭐ **مش بترجّع enum خام** — الواجهة بتعرضها للمستخدم، و
     * `teacher_payment` جواها underscore و إنجليزي.
     */
    private function categoryLabel(string $category): string
    {
        return match ($category) {
            'income' => 'تحصيل',
            'expense' => 'مصروف',
            'teacher_payment' => 'دفعة لمعلم',
            'refund' => 'استرداد',
            'transfer_in' => 'تحويل وارد',
            'transfer_out' => 'تحويل صادر',
            'fee' => 'عمولة',
            'adjustment' => 'تسوية',
            default => $category,
        };
    }
}
