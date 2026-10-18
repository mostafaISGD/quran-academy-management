<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ⭐ PR 1.12 — موافقة ولي الأمر على حساب الطالب.
 *
 * ⭐ السجل ** بيفضل** بعد السحب — السحب `revoked_at` مش `delete`.
 * «مين سحل إمتى» لازم يبقى ليه جواب بعد سنة.
 *
 * @see \App\Services\StudentAccountEligibility
 */
class ParentalConsent extends Model
{
    /** ⭐ موافقة على إنشاء حساب مستقل للطالب */
    public const SCOPE_ACCOUNT = 'account_activation';

    /** ⭐ موافقة على متابعة التقدم والحضور */
    public const SCOPE_PROGRESS = 'view_progress';

    /** ⭐ موافقة على السداد والمصاريف */
    public const SCOPE_PAYMENTS = 'payments';

    protected $fillable = [
        'student_id',
        'parent_id',
        'scope',
        'granted',
        'granted_at',
        'granted_by',
        'revoked_at',
        'revoked_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'granted' => 'boolean',
            'granted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Student, ParentalConsent> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @return BelongsTo<ParentModel, ParentalConsent> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(ParentModel::class, 'parent_id');
    }

    /** ⭐ لسه سارية؟ */
    public function isActive(): bool
    {
        return $this->granted && $this->revoked_at === null;
    }
}