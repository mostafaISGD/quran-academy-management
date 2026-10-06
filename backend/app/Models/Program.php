<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * البرنامج التعليمي.
 *
 * البرنامج = تعريف الخدمة التعليمية اللي بتقدمها الأكاديمية، وما
 * يرتبط بيها من مستويات ومعلمين وباقات. مش مكان إدارة الاشتراكات
 * ولا الجدول ولا المالية — دي أقسامها.
 */
class Program extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id', 'name', 'slug', 'description', 'image_url', 'color', 'status',
    ];

    protected $appends = ['levels_count', 'teachers_count', 'students_count', 'plans_count'];

    // ===== العلاقات =====

    public function organization() { return $this->belongsTo(Organization::class); }

    /** المراحل/المستويات — موجودة فعلاً و هي basicRequirements الأساسية */
    public function levels() { return $this->hasMany(Level::class)->orderBy('sort_order'); }

    public function subscriptionPlans() { return $this->hasMany(SubscriptionPlan::class); }

    /** المعلمين القادرين على تدريس البرنامج */
    public function teachers()
    {
        return $this->belongsToMany(Teacher::class, 'program_teacher')
            ->withPivot(['is_primary', 'rate_multiplier', 'notes'])
            ->withTimestamps();
    }

    /** التصنيفات — النوع والمجال والفئة والمستوى */
    public function categories()
    {
        return $this->belongsToMany(ProgramCategory::class, 'program_category')
            ->withPivot('label')
            ->withTimestamps();
    }

    /** الطلاب — عن طريق الاشتراكات، مش رابط مباشر */
    public function students()
    {
        return $this->belongsToMany(Student::class, 'subscriptions')
            ->where('subscriptions.status', 'active')
            ->distinct();
    }

    public function lessons() { return $this->hasMany(Lesson::class); }
    public function subscriptions() { return $this->hasMany(Subscription::class); }

    // ===== عدادات للـ cards والملف =====

    public function getLevelsCountAttribute(): int
    {
        return $this->levels()->count();
    }

    public function getTeachersCountAttribute(): int
    {
        return $this->teachers()->count();
    }

    /**
     * طلاب البرنامج — اشتراك نشط أو موقوف.
     *
     * مهم: التعريف ده لازم يكون واحد في كل شاشات البرنامج (كارت +
     * ملف + قائمة)، وإلا المستخدم هيشوف رقمين مختلفين لنفس الحاجة.
     * المنتهي والملغي مش طالب في البرنامج.
     *
     * ⚠️ ده partitioning مش جمع: لو جمعنا `count(distinct)` لكل حالة
     * على حدة، الطالب اللي عنده اشتراك **نشط** وآخر **موقوف**
     * هيتحسب مرتين (٢ بدل ١). فبنقسم الطلاب على حالات، كل طالب في
     * حالة واحدة بس — النشطة تسبق الموقوفة — عشان
     * `active + paused === total` دايمًا.
     */
    public function studentStatusBreakdown(): array
    {
        $rows = \Illuminate\Support\Facades\DB::table('subscriptions')
            ->where('program_id', $this->id)
            ->whereIn('status', ['active', 'paused'])
            // طالب واحد = صف واحد، و«النشطة» تسبق «الموقوفة»
            ->selectRaw('student_id, max(case when status = ? then 1 else 0 end) as is_active', ['active'])
            ->groupBy('student_id')
            ->get(['is_active']);

        $active = $rows->filter(fn ($r) => (int) $r->is_active === 1)->count();
        $paused = $rows->count() - $active;

        return ['active' => $active, 'paused' => $paused, 'total' => $active + $paused];
    }

    public function getStudentsCountAttribute(): int
    {
        return $this->studentStatusBreakdown()['total'];
    }

    /**
     * ⭐ عدد الباقات **المخصّصة للبرنامج ده** (مش المشتركة).
     *
     * الباقات الـ ٢٨ بقت **مشتركة** (`program_id = null`) — فالرقم
     * ده بيحسب الباقات القديمة اللي ليها برنامج بس. عشان كده
     * الواجهة **مش بتعرضه**؛ بتودّي لصفحة الأسعار المشتركة.
     *
     * سيبناه موجود لأن `ProgramCountConsistencyTest` بيحمي توافق
     * الرقم بين القائمة والملف — وسندة لو حصل تعديل بعدين.
     */
    public function getPlansCountAttribute(): int
    {
        return $this->subscriptionPlans()->where('status', 'active')->count();
    }

    /**
     * فحص تطابق ربط المعلمين بالواقع.
     *
     * البرنامج بيقول «المعلم ده يدرّس البرنامج ده» — بس الحقيقة في
     * جدول `lessons`. فلو，两者 مختلفين يبقى الـ pivot بيكدب، والكارت
     * بيعرض معلّمين مش بيلقوا حصة في البرنامج.
     *
     * نوعين من عدم التطابق:
     *  - `unlinked`  معلم عنده حصص في البرنامج ومش مسجّل عليه
     *  - `idle`      معلم مسجّل عليه ومفيش له أي حصة في البرنامج
     *
     * الاتنين مش أخطاء — ممكن يكون تعلّمه لسه بدأ، أو خلّص. لكن
     * لازم الأدمن يشوفها ويقرّر.
     */
    public function teacherLinkHealth(): array
    {
        // المعلمون اللي عندهم حصص فعلاً في البرنامج ده
        $withLessons = \Illuminate\Support\Facades\DB::table('lessons')
            ->where('program_id', $this->id)
            ->whereNotNull('teacher_id')
            ->selectRaw('teacher_id, count(*) as lessons_count')
            ->groupBy('teacher_id')
            ->get()
            ->keyBy(fn ($r) => (int) $r->teacher_id);

        $linked = $this->teachers()->get();

        $unlinkedIds = $withLessons->keys()
            ->reject(fn ($id) => $linked->contains('id', $id))
            ->values();

        $idleIds = $linked
            ->reject(fn ($t) => $withLessons->has((int) $t->id))
            ->pluck('id')
            ->values();

        return [
            'unlinked' => $unlinkedIds,
            'idle' => $idleIds,
            'total' => $unlinkedIds->count() + $idleIds->count(),
        ];
    }

    /** ملخص الحفظ — عشان ملف البرنامج يعرض «فيه تقدم» ولا لأ */
    public function memorizationSummary(): array
    {
        // الحفظ بيتسجل بـ from_ayah/to_ayah مش بعدد صفحات، فنحسب
        // الآيات من الفرق. الصفحة ≈ ٢ آية في المصحف.
        $row = \Illuminate\Support\Facades\DB::table('memorization_records')
            ->join('lessons', 'lessons.id', '=', 'memorization_records.lesson_id')
            ->where('lessons.program_id', $this->id)
            ->selectRaw('count(*) as records')
            ->selectRaw('coalesce(sum(memorization_records.to_ayah - memorization_records.from_ayah + 1), 0) as ayahs')
            ->selectRaw('count(distinct memorization_records.student_id) as students')
            ->selectRaw('count(distinct memorization_records.surah_id) as surahs')
            ->first();

        $ayahs = (int) ($row->ayahs ?? 0);

        return [
            'records' => (int) ($row->records ?? 0),
            'ayahs' => $ayahs,
            'pages' => (int) round($ayahs / 2),
            'students' => (int) ($row->students ?? 0),
            'surahs' => (int) ($row->surahs ?? 0),
        ];
    }
}
