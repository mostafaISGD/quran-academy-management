<?php

namespace App\Models;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * ⭐ مجموعة أونلاين — فيها **عدد أقصى**، وفوقه **قائمة انتظار**.
 *
 * ─────────────────────────────────────────────────────────────
 * ليه كيان لوحدها ومش مستوى؟
 *
 * المستوى بيتقفل مع البرنامج، والمجموعة بالكتابة بتفتح وتنقفل.
 * ونفس المستوى ممكن ياخده معلمين أكتر. فالتلاتة دول بيختلفوا.
 *
 * ─────────────────────────────────────────────────────────────
 * ⚠️ حدود دلوقتي
 *
 * الحصة في النظام لسه طالب واحد (`lessons.student_id`). فالمجموعة
 * = قائمة طلاب + سعة + انتظار. ربط الحصص بالمجموعة شغل تاني.
 *
 * ─────────────────────────────────────────────────────────────
 * ⭐ الأرقام دي **متحسبش وبتخزّنش** — بتتحسب في كل مرة.
 *
 * لو خزّنّا «فيه ٣ مقاعد فاضية»، أول ما حد يدخل أو يخرج الرقم
 * بيبقى غلط لحد ما حد يعمل «تحديث» يدوي. هنا كل رقم معناه
 * واحد في الشاشة وفي الـ API: `GroupClass::occupancy()`.
 */
class GroupClass extends Model
{
    use SoftDeletes;

    /** أسماء الأيام بالترتيب اللي بتستقبله الحقول (٠ = الأحد) */
    public const WEEKDAYS = [
        0 => 'الأحد',
        1 => 'الإثنين',
        2 => 'الثلاثاء',
        3 => 'الأربعاء',
        4 => 'الخميس',
        5 => 'الجمعة',
        6 => 'السبت',
    ];

    protected $fillable = [
        'organization_id', 'program_id', 'level_id', 'teacher_id', 'name',
        'capacity', 'package_id', 'meeting_url', 'meeting_provider',
        'weekday', 'start_time', 'end_time',
        'status', 'sort_order', 'description',
    ];

    /** ⭐ نفس سبب `WaitingListEntry` — القيمة الافتراضية مش بترجع للصف الجديد */
    protected $attributes = [
        'status' => 'active',
    ];

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'weekday' => 'integer',
            'sort_order' => 'integer',
            'start_time' => 'string',
            'end_time' => 'string',
        ];
    }

    // ============================================================
    // العلاقات
    // ============================================================

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(Level::class);
    }

    /**
     * ⭐ الباقة على **المجموعة** — أي طالب يدخل بياخدها.
     *
     * ⚠️ اختياري (`belongsTo` مش `hasOne`) — المجموعة ممكن
     * تكون من غير باقة، وساعتها بتاخد اشتراك من باقة البرنامج
     * الشهرية. ده اللي كان بيحصل قبل ما نضيف العمود.
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'package_id');
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    /** الأعضاء — كلهم (سواء داخلين أو سابوا) */
    public function members(): HasMany
    {
        return $this->hasMany(GroupMember::class);
    }

    /** الأعضاء **الداخلين** دلوقتي — دول اللي بياخدوا المقاعد */
    public function activeMembers(): HasMany
    {
        return $this->hasMany(GroupMember::class)->where('status', 'active');
    }

    /** Members اللي سابوا — عشان نعرف تاريخ */
    public function formerMembers(): HasMany
    {
        return $this->hasMany(GroupMember::class)->where('status', 'left');
    }

    public function waitingList(): HasMany
    {
        return $this->hasMany(WaitingListEntry::class);
    }

    /**
     * ⭐ المنتظرين بس — بالحالة وبالترتيب.
     *
     * `where('status', 'waiting')` **مش** اختياري: من غيرها
     * `waiting_count` هيسمّي اللي دخلوا واللي رفضوا كإنهم
     * «مستنيين» — فالتنبيه هيشتغل على مجموعة فاضية.
     */
    public function waiting(): HasMany
    {
        return $this->hasMany(WaitingListEntry::class)
            ->where('status', 'waiting')
            ->waitingOrder();
    }

    // ============================================================
    // ⭐ الأرقام — التعريف الوحيد
    // ============================================================

    /**
     * ⭐ أرقام المجموعة في مكان واحد.
     *
     * أي شاشة أو API تبين الرقم، تاخده من هنا. لو حسبناهم في
     * تلات أماكن، هياخدوا تلات أرقام مختلفة.
     *
     * @return array{
     *     capacity: int|null, members: int, waiting: int,
     *     seats_left: int|null, is_full: bool, has_space: bool
     * }
     */
    public function occupancy(): array
    {
        $members = $this->activeMembers_count ?? $this->activeMembers()->count();
        $waiting = $this->waiting_count ?? $this->waiting()->count();

        // ⭐ `capacity = null` معناها **مفيش حد** — مش صفر
        $seatsLeft = $this->capacity === null
            ? null
            : max(0, $this->capacity - $members);

        return [
            'capacity' => $this->capacity,
            'members' => $members,
            'waiting' => $waiting,
            'seats_left' => $seatsLeft,
            // ⭐ مفيش حد ⇒ مفيش «امتلأت»
            'is_full' => $this->capacity !== null && $members >= $this->capacity,
            // ⭐ «فيه مقعد فاضي» = فيه حد **فعلاً** يدخل
            'has_space' => $this->capacity === null || $members < $this->capacity,
        ];
    }

    /** ⭐ فيه مقعد فاضي؟ — ده اللي بيولّع التنبيه */
    public function hasFreeSeat(): bool
    {
        return $this->occupancy()['has_space'];
    }

    /** ⭐ فيه حد مستني وفي نفس الوقت فيه مقعد فاضي؟ ← دي الحالة اللي بتلفت نظرك */
    public function hasWaitingAndSpace(): bool
    {
        $o = $this->occupancy();

        return $o['waiting'] > 0 && $o['has_space'];
    }

    // ============================================================
    // الاسم على الشاشة
    // ============================================================

    /**
     * ⭐ سطر الميعاد **خام** — «الخميس 18:00 — 19:00».
     *
     * ⚠️ ليه خام ومش «الخميس ٧:٠٠ م»؟
     *
     * كان بنعمله بـ Carbon و`->locale('ar')`، وطلع «6:00 PM» —
     * إنجليزي. السبب إن Carbon محتاج بيانات اللغة محمّلة، وده
     * بيختلف من بيئة لأخرى.
     *
     * ⭐ **الواجهة هي اللي بتعمل التنسيق** (`scheduleLine()` في
     * `lib/format.ts`) — عندها المتصفح فيه البيانات الصح مضمونة.
     *
     * الـ `weekday_label` فاضل عربي عادي، وده مش بيحتاج تنسيق.
     */
    public function scheduleLabel(): ?string
    {
        if ($this->weekday === null && ! $this->start_time) {
            return null;
        }

        $day = $this->weekday !== null
            ? (self::WEEKDAYS[$this->weekday] ?? null)
            : null;

        $from = $this->start_time ? substr($this->start_time, 0, 5) : null;
        $to = $this->end_time ? substr($this->end_time, 0, 5) : null;

        $range = $from ? ($to ? "{$from} — {$to}" : $from) : null;

        return collect([$day, $range])->filter()->implode(' ');
    }

    /** بتستقبل طلبات انضمام جديدة ولا لأ — للعرض العام */
    public function isOpenForWaitlist(): bool
    {
        // ⭐ **`has_space` مش مهمة هنا!** حتى لو المجموعة ممتلئة،
        // حد ممكن يستنى. ده أصلاً معنى قائمة الانتظار.
        return $this->status === 'active';
    }

    // ============================================================
    // Queries
    // ============================================================

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /**
     * ⭐ الترتيب: المجموعات اللي ليها ميعاد بتتقدّم — هي اللي
     * بتشتغل دلوقتي. وبعدها اللي مفيها ميعاد لسه بتتكوّن.
     *
     * المجموعات اللي فيها ميعاد بتتقدّم — هي اللي بتشتغل دلوقتي.
     * وبعدها اللي مفيها ميعاد لسه بتتكوّن.
     */
    public function scopeDisplayOrder(Builder $query): Builder
    {
        return $query->orderByRaw('CASE WHEN weekday IS NULL THEN 1 ELSE 0 END')
            ->orderBy('weekday')
            ->orderBy('start_time')
            ->orderBy('sort_order')
            ->orderBy('name');
    }

    /**
     * ⭐ الأرقام محمّلة مرة واحدة — مش استعلام لكل مجموعة.
     *
     * من غير الكده صفحة فيها ١٠ مجموعات = ٢٠ استعلام. الـ
     * `occupancy()` بيشتغل من الأرقام المحمّلة لو موجودة.
     */
    public function scopeWithOccupancy(Builder $query): Builder
    {
        return $query->withCount([
            'activeMembers as activeMembers_count',
            'waiting as waiting_count',
        ]);
    }
}

