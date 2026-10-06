<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * باقة اشتراك — **مشتركة** بين البرامج.
 *
 * ⭐ الباقة مبنية على **مدة الحصة** + **عدد الحصص**، مش على
 * البرنامج. «٤ حصص × ٣٠ دقيقة» معناها أربع حصص نص ساعة، سواء
 * تحفيظ ولا تجويد ولا تلاوة. الربط بالبرنامج بيحصل عند
 * الاشتراك (`subscriptions.program_id`).
 *
 * الفئات:
 *   traditional = تقليدي (فردي)
 *   golden      = ذهبي
 *   group       = مجموعات (حصة جماعية — أرخص)
 *   single      = الحصة الواحدة (نظام `per_lesson` القديم)
 */
class SubscriptionPlan extends Model
{
    use SoftDeletes;

    /** ترتيب العرض: ٣٠ قبل ٤٥ قبل ٦٠، وتقليدي قبل ذهبي قبل مجموعات */
    public const CATEGORY_LABELS = [
        'traditional' => 'تقليدي',
        'golden' => 'ذهبي',
        'group' => 'مجموعات',
        'single' => 'حصة مفردة',
    ];

    /** ترتيب الفئات في العرض */
    public const CATEGORY_ORDER = ['traditional', 'golden', 'group', 'single'];

    protected $fillable = [
        'organization_id', 'program_id', 'name', 'billing_type', 'price',
        'currency', 'lessons_count', 'lesson_duration_minutes', 'duration_days',
        'status', 'category', 'sort_order', 'description',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'lessons_count' => 'integer',
            'lesson_duration_minutes' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    /** nullable دلوقتي — الباقات الجديدة مش مربوطة ببرنامج */
    public function program()
    {
        return $this->belongsTo(Program::class);
    }

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }

    // ===== الفئة =====

    public static function categoryLabel(?string $category): string
    {
        return self::CATEGORY_LABELS[$category] ?? '—';
    }

    /** الفئات اللي بتاعتنا الجديدة (مش `single` القديم) */
    public function isShared(): bool
    {
        return $this->program_id === null;
    }

    /** حصة جماعية — بتعرض بلون مختلف في شاشة الأسعار */
    public function isGroup(): bool
    {
        return $this->category === 'group';
    }

    /** اسم الفئة بال العربي — عشان الـ view يبقى سطر واحد */
    public function getCategoryLabelAttribute(): string
    {
        return self::categoryLabel($this->category);
    }

    /**
     * ⭐ الباقات النشطة، مرتبة للعرض.
     *
     * الترتيب: الفئة (تقليدي ← ذهبي ← مجموعات ← مفردة) × المدة
     * (٣٠ ← ٤٥ ← ٦٠) × عدد الحصص (٤ ← ٨ ← ١٢ ← ١٦).
     */
    public function scopeDisplayOrder(Builder $query)
    {
        return $query
            ->orderByRaw(
                "CASE category
                    WHEN 'traditional' THEN 1
                    WHEN 'golden'      THEN 2
                    WHEN 'group'       THEN 3
                    ELSE 4
                 END"
            )
            ->orderBy('lesson_duration_minutes')
            ->orderBy('lessons_count');
    }
}