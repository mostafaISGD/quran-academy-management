<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ⭐ سطر في **قائمة الانتظار**.
 *
 * ⭐ الاسم + الموبايل — **من غير حساب**.
 *
 * السبب: أكتر الناس اللي بتطلب في المجموعات هم اللي لسه ما
 * عندهمش اشتراك. لو ربطنا السطر بـ `students` كإلزامي، اللي
 * هييجي جديد مش هيقدر يسجّل خالص — وهو بالظبط اللي إحنا
 * عايزينه.
 *
 * `student_id` و `parent_id` **اختياريين**: لو الشخص ده مسجّل
 * في النظام من قبل، نربطه ونعرف. لو لأ، نعرفه بالاسم
 * والموبايل بس — وده كافي نتصل بيه.
 */
class WaitingListEntry extends Model
{
    protected $fillable = [
        'organization_id', 'group_class_id', 'name', 'phone',
        'parent_phone', 'current_level', 'package_id', 'proposed_group_id',
        'student_id', 'parent_id', 'status', 'notes',
        'entered_at', 'joined_at', 'admitted_by',
    ];

    /**
     * ⭐ قيم افتراضية **في الموديل** مش بس في قاعدة البيانات.
     *
     * السبب: SQLite (و MySQL كمان) مش بيرجّع القيمة الافتراضية
     * للصف اللي اتعمل دلوقتي. فلو عملنا `create()` من غير `status`
     * وقرأنا `$entry->status` فورًا، هنلاقي `null` — مش «waiting».
     *
     * الفرق ده بيقتل الاختبارات وكمان الواجهة: أي كود يقول
     * `if ($entry->status === 'waiting')` هيشتغل غلط.
     */
    protected $attributes = [
        'status' => 'waiting',
    ];

    protected function casts(): array
    {
        return [
            'entered_at' => 'datetime',
            'joined_at' => 'datetime',
        ];
    }

    public function groupClass(): BelongsTo
    {
        return $this->belongsTo(GroupClass::class);
    }

    /** الطالب — بس لو كان مسجّل في النظام */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(ParentModel::class);
    }

    /** الباقة المطلوبة */
    public function package(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'package_id');
    }

    /** المجموعة المقترحة */
    public function proposedGroup(): BelongsTo
    {
        return $this->belongsTo(GroupClass::class, 'proposed_group_id');
    }

    /** مين إدخله */
    public function admittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admitted_by');
    }

    // ============================================================
    // ⭐ الترتيب
    // ============================================================

    /**
     * ⭐ ترتيب الانتظار: **وقت التسجيل** ثم `id`.
     *
     * `entered_at` ممكن يتكرر (ثانية واحدة فيها ٥ أسطر)، فبنضم
     * `id` عشان الترتيب يبقى **ثابت**. من غير `id`، لو اتسجل
     * اتنين في نفس الثانية، الترتيب كان هيعتمد على ترتيب
     * قاعدة البيانات وهيتغيّر من استعلام للتاني — والأول في
     * القائمة لازم يفضل الأول.
     */
    public function scopeWaitingOrder(Builder $query): Builder
    {
        return $query->orderBy('entered_at')->orderBy('id');
    }

    public function scopeWaiting(Builder $query): Builder
    {
        return $query->where('status', 'waiting');
    }

    public function scopeJoined(Builder $query): Builder
    {
        return $query->where('status', 'joined');
    }

    /** ⭐ لسه مستني — مش داخل ومش رافض */
    public function isWaiting(): bool
    {
        return $this->status === 'waiting';
    }

    /**
     * ⭐ «أول واحد في الانتظار» — مين اللي داخل جاي عليه؟
     *
     * لو مفيش حد، بنرجّع `null` وده عادي — كل المجموعات ممكن
     * تكون فاضية.
     */
    public static function nextInLine(GroupClass $group): ?self
    {
        return static::where('group_class_id', $group->id)
            ->where('status', 'waiting')
            ->waitingOrder()
            ->first();
    }

    /**
     * ⭐ «دخل» — اتعلّم إنه جاي.
     *
     * ⚠️ **مش** بيعمل طالب ولا اشتراك — القرار إن الأدمن يدخّله
     * ويكمّل بنفسه. إحنا بنعلّم السطر بس.
     */
    public function markJoined(?User $by = null): self
    {
        $this->update([
            'status' => 'joined',
            'joined_at' => now(),
            'admitted_by' => $by?->id,
        ]);

        return $this;
    }

    /** رفض — السطر بيفضل عشان محدش يسجّل تاني على طول */
    public function markDeclined(?string $reason = null): self
    {
        $this->update([
            'status' => 'declined',
            'notes' => $reason ?? $this->notes,
        ]);

        return $this;
    }

    /**
     * ⭐ «رقمه في الطابور» — بيتحسب، مش محفوظ.
     *
     * فلو فيه ٥ مستنين، ده الثالث ورقمه ٣. ده نفس الحساب اللي
     * في الشاشة والـ API — معرفش يختلفوا.
     */
    public function positionInLine(): int
    {
        if (! $this->isWaiting()) {
            return 0;
        }

        $queue = static::where('group_class_id', $this->group_class_id)
            ->where('status', 'waiting');

        // ⚠️ `entered_at` ممكن يبقى **null** في سطور قديمة اتعملت قبل
        // ما نتأكد إن الحقل ده بيتملا. المقارنة `<` مع `null` بترمي
        // `Illegal operator and value combination` (500 في الصفحة كلها).
        //
        // القاعدة: السطر اللي مالوش وقت دخول بنعتبره **الأقدم** —
        // وكل السطور ليها وقت بعده. وترتيبهم amongst بعضهم بـ `id`.
        if ($this->entered_at === null) {
            return (clone $queue)
                ->whereNull('entered_at')
                ->where('id', '<', $this->id)
                ->count() + 1;
        }

        $enteredAt = $this->entered_at;
        $id = $this->id;

        return $queue
            ->where(fn (Builder $q) => $q
                ->whereNull('entered_at')
                ->orWhere(fn (Builder $before) => $before
                    ->where('entered_at', '<', $enteredAt)
                    // ⭐ نفس الوقت ⇒ الأصغر `id` هو اللي قبل
                    ->orWhere(fn (Builder $same) => $same
                        ->where('entered_at', $enteredAt)
                        ->where('id', '<', $id))))
            ->count() + 1;
    }
}
