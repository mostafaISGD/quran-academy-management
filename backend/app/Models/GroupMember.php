<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ⭐ عضو في مجموعة.
 *
 * ⭐ الصف **مش بيتحذف** لما الطالب يخرج — بيتحوّل لـ `left`.
 *
 * السبب: لو حذفناه، ضاع تاريخ «كان في المجموعة من شهر،
 * وطلّع في شهر». ده بتاع التسعير والقرارات. الصف بيفضل
 * عشان نعرف مين كان فين ومتى.
 *
 * المقاعد الفاضية بتحسب من `status = active` بس — فالخارج مش
 * بياخد مقعد.
 */
class GroupMember extends Model
{
    protected $fillable = [
        'group_class_id', 'student_id', 'source', 'status',
        'joined_at', 'left_at', 'notes',
    ];

    /** ⭐ نفس سبب `WaitingListEntry` — القيمة الافتراضية مش بترجع للصف الجديد */
    protected $attributes = [
        'status' => 'active',
        'source' => 'manual',
    ];

    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
        ];
    }

    public function groupClass(): BelongsTo
    {
        return $this->belongsTo(GroupClass::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeFormer($query)
    {
        return $query->where('status', 'left');
    }

    /**
     * ⭐ إضافة عضو — idempotent (بتعملها مرة واحدة بس).
     *
     * ليش؟ عشان الأدمن يضغط «أضف» مرتين بالغلط. من غير ده هيبقى
     * في صفين، والصف التاني بيخالف القيد `unique` فيطلع ٥٠٠
     * بدل ما يضيف من غير ما يعمل حاجة.
     *
     * ⭐ لو الطالب كان `left` قبل كده ورجع، بنرجّعه `active`
     * من غير ما نعمل صف جديد — عشان التاريخ يفضل متصل.
     */
    public static function admit(
        GroupClass $group,
        Student $student,
        string $source = 'manual',
        ?string $notes = null,
    ): self {
        $existing = static::where('group_class_id', $group->id)
            ->where('student_id', $student->id)
            ->first();

        if ($existing) {
            // ⭐ رجع للمجموعة — نلغي الخروج بدل ما نعمل صف جديد
            $existing->update([
                'status' => 'active',
                'left_at' => null,
                'source' => $source,
                'notes' => $notes ?? $existing->notes,
            ]);

            return $existing->fresh();
        }

        return static::create([
            'group_class_id' => $group->id,
            'student_id' => $student->id,
            'source' => $source,
            'status' => 'active',
            'joined_at' => now(),
            'notes' => $notes,
        ]);
    }

    /**
     * ⭐ الخروج — الصف بيفضل، الحالة بتتغيّر.
     *
     * `left_at` بيتسجل **أول** مرة بس. لو الطالب رجع وخرج تاني،
     * مش هنبوس تاريخ أول خروج — عشان يفضل «كان أول مرة في
     * ٢٠٢٦/٠١/٠٥» صحيح.
     */
    public function markLeft(?string $reason = null): self
    {
        $this->update([
            'status' => 'left',
            'left_at' => $this->left_at ?? now(),
            'notes' => $reason ?? $this->notes,
        ]);

        return $this;
    }
}
