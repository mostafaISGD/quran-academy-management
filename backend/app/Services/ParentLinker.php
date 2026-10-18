<?php

namespace App\Services;

use App\Models\ParentModel;
use App\Models\Student;
use App\Models\StudentPhone;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * responsible عن ربط أرقام phones الخاصة بولي الأمر بجدول parents + student_parents.
 *
 * القاعدة: نفس الرقم = نفس ولي الأمر في الداتابيز.
 * لو الأم فاطمة (010111) عندها 3 طلاب، يبقى صف واحد في parents
 * و 3 صفوف في student_parents (كل واحد بعلاقته مع طالبه).
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐⭐⭐ **الرقم المكرر = رابط معلّق، مش رابط مؤكد** (PR 1.11)
 *
 * الكود القديم كان: يدوّر على الرقم → يلاقي ولي أمر → **يربط**
 * على طول. فأي حد يدخل رقم أم لمي في صفحة أرقام طالب، يصير أبوه
 * على طول — مع `can_manage` و`can_pay`.
 *
 * ⭐ والرقم **مش هوية**: شقق في مصر بتشارك نفس الرقم، وأرقام
 * المتاجر بتتكرر. فالربط التلقائي = انتحال صفة ورقية.
 *
 * ⭐ القاعدة الجديدة:
 *
 *   • رقم **جديد** → ولي أمر جديد + رابط **مؤكد** (الموظف هو اللي
 *     إدخل البيانات، وهو شايف اللي قدامه).
 *   • رقم **موجود** → الرابط الجديد **معلّق**، لحد ما الموظف
 *     يأكّد إن ده نفس الشخص (`verify()`). واسم ولي الأمر
 *     الموجود بيظهرله في شاشة التأكيد.
 */
class ParentLinker
{
    public const STATUS_PENDING = 'pending_verification';

    public const STATUS_VERIFIED = 'verified';

    /**
     * يبحث عن ولي أمر بنفس الرقم، أو ينشئ واحد جديد.
     *
     * ⭐ الرقم الموجود ⇒ رابط `pending_verification` (مش مؤكد).
     * الرقم الجديد  ⇒ رابط `verified`.
     *
     * @return array{parent_id: int, created: bool, status: string}
     */
    public function findOrCreate(
        int $studentId,
        string $phoneNumber,
        string $name,
        ?string $relationship = null,
        ?string $email = null,
        ?string $countryCode = null,
    ): array {
        $phone = $this->normalize($phoneNumber, $countryCode);

        // ⭐ `findOrFail` مش `find`: طالب مش موجود = خطأ صريح،
        // مش «خلي organization_id = 1» (الكود القديم كان بيخلي
        // ولي أمر يتنسب لمنظمة من فراغ).
        $student = Student::findOrFail($studentId);

        // ① هل في ولي أمر بنفس الرقم؟
        $existing = ParentModel::where('phone', $phone)->first();

        if ($existing) {
            // الاسم يتثبّت على أول قيمة (لا نعدّله بعد كده)
            if ($email && !$existing->email) {
                $existing->update(['email' => $email]);
            }

            // ⭐⭐ **معلّق** — وده الفرق كله عن النسخة القديمة
            $status = $this->link($studentId, $existing->id, $relationship);

            return ['parent_id' => $existing->id, 'created' => false, 'status' => $status];
        }

        // ② مافيش → ننشئ واحد جديد (دخله الموظف من أول مرة)
        $parent = ParentModel::create([
            'organization_id' => $student->organization_id,
            'user_id' => null,
            'name' => $name,
            'phone' => $phone,
            'alternate_phone' => null,
            'email' => $email,
            'country_code' => $countryCode ?? '+20',
            'preferred_language' => 'ar',
            'notes' => null,
            'status' => 'active',
        ]);

        // ⭐ جديد = الموظف شايف البيانات ⇒ **مؤكد**
        $this->link($studentId, $parent->id, $relationship, self::STATUS_VERIFIED);

        return [
            'parent_id' => $parent->id,
            'created' => true,
            'status' => self::STATUS_VERIFIED,
        ];
    }

    /**
     * يربط الولي بالطالب في student_parents.
     * لو الربط موجود بساقبل نعدّل الـ relationship.
     *
     * ⭐ `status = pending_verification` **مفيش صلاحيات**. ده
     * اللي بيمنع الرقم المكرر من إنه يدي وصول.
     *
     * ⭐ `is_primary` بتفضل على الرابط **المؤكد** بس: ما ينفعش
     * ولي أمر معلّق يبقى «الأب الأساسي» لطالب من غير مراجعة.
     *
     * @return string حالة الرابط بعد التنفيذ
     */
    public function link(
        int $studentId,
        int $parentId,
        ?string $relationship = null,
        string $status = self::STATUS_PENDING,
    ): string {
        $existing = DB::table('student_parents')
            ->where('student_id', $studentId)
            ->where('parent_id', $parentId)
            ->first();

        if ($existing) {
            if ($relationship && $existing->relationship !== $relationship) {
                DB::table('student_parents')
                    ->where('student_id', $studentId)
                    ->where('parent_id', $parentId)
                    ->update(['relationship' => $relationship]);
            }

            return $existing->status;
        }

        $verified = $status === self::STATUS_VERIFIED;

        // ⭐ أول ولي أمر **مؤكد** للطالب = الأساسي
        $isFirst = !DB::table('student_parents')
            ->where('student_id', $studentId)
            ->where('status', self::STATUS_VERIFIED)
            ->exists();

        DB::table('student_parents')->insert([
            'student_id' => $studentId,
            'parent_id' => $parentId,
            'relationship' => $relationship,
            'status' => $status,
            'is_primary' => $verified && $isFirst,
            // ⭐⭐ القيم الافتراضية كانت `true` للكل — وبتدي
            // صلاحيات إدارة ودفع قبل أي تأكيد. بقت مربوطة بالحالة.
            'can_manage' => $verified,
            'can_pay' => $verified,
            'can_receive_notifications' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $status;
    }

    /**
     * ⭐ أكّد الربط — الموظف شايف اسم ولي الأمر الموجود والاسم
     * الجديد اللي حد أرسله، وهو اللي بيحسم.
     *
     * ⭐ التأكيد بيمنح `can_manage` و`can_pay` — لأن الموظف هو
     * اللي أكّد إن ده ولي الأمر فعلاً.
     */
    public function verify(int $studentId, int $parentId, User $actor): void
    {
        $updated = DB::table('student_parents')
            ->where('student_id', $studentId)
            ->where('parent_id', $parentId)
            ->update([
                'status' => self::STATUS_VERIFIED,
                'verified_at' => now(),
                'verified_by' => $actor->id,
                'can_manage' => true,
                'can_pay' => true,
                'updated_at' => now(),
            ]);

        if ($updated === 0) {
            return;
        }

        // ⭐ ولاّاه مفيش أساسي مؤكد؟ يبقى هو
        $hasPrimary = DB::table('student_parents')
            ->where('student_id', $studentId)
            ->where('status', self::STATUS_VERIFIED)
            ->where('is_primary', true)
            ->exists();

        if (! $hasPrimary) {
            DB::table('student_parents')
                ->where('student_id', $studentId)
                ->where('parent_id', $parentId)
                ->update(['is_primary' => true]);
        }

        app(AuditLogService::class)->log(
            'parent_link_verified',
            'student',
            $studentId,
            null,
            ['parent_id' => $parentId],
            null,
            $actor->id,
        );
    }

    /** ⭐ ارفض الربط المعلّق — الصف بيتشال خالص */
    public function reject(int $studentId, int $parentId, User $actor): void
    {
        $this->unlink($studentId, $parentId);

        app(AuditLogService::class)->log(
            'parent_link_rejected',
            'student',
            $studentId,
            null,
            ['parent_id' => $parentId],
            null,
            $actor->id,
        );
    }

    /**
     * ⭐ الروابط المعلّقة لكل طالب — دي شاشة التأكيد عند الموظف.
     *
     * ⭐ الاسم موجود فعلاً عشان الموظف **يقارن** بين الاسم اللي في
     * `parents` واللي حد أرسله — مقارنة الأرقام مش بتثبت هوية.
     */
    public function pendingForStudent(int $studentId)
    {
        return DB::table('student_parents')
            ->where('student_id', $studentId)
            ->where('status', self::STATUS_PENDING)
            ->orderBy('created_at')
            ->get()
            ->map(fn ($link) => [
                'parent_id' => (int) $link->parent_id,
                'name' => ParentModel::where('id', $link->parent_id)->value('name'),
                'phone' => ParentModel::where('id', $link->parent_id)->value('phone'),
                'relationship' => $link->relationship,
                'entered_at' => $link->created_at,
            ]);
    }

    /**
     * فكّ الربط بين الطالب وولي الأمر.
     * لو الولي مابقاش مرتبط بأي طالب تاني، بيتشال من جدول parents.
     */
    public function unlink(int $studentId, int $parentId): void
    {
        DB::table('student_parents')
            ->where('student_id', $studentId)
            ->where('parent_id', $parentId)
            ->delete();

        // ⭐⭐ الباقي مرتبط بأي طالب تاني؟ فسيبه. ولو الطالب ده مابقى
        // ليه ولي أمر أساسي **مؤكد**، يبقى أقرب واحد مؤكد.
        $stillLinked = DB::table('student_parents')
            ->where('parent_id', $parentId)
            ->exists();

        if (!$stillLinked) {
            ParentModel::where('id', $parentId)->delete();
            return;
        }

        $primary = DB::table('student_parents')
            ->where('student_id', $studentId)
            ->where('status', self::STATUS_VERIFIED)
            ->where('is_primary', true)
            ->exists();

        if (!$primary) {
            $firstLink = DB::table('student_parents')
                ->where('student_id', $studentId)
                ->where('status', self::STATUS_VERIFIED)
                ->orderBy('created_at')
                ->first();

            if ($firstLink) {
                DB::table('student_parents')
                    ->where('student_id', $studentId)
                    ->where('parent_id', $firstLink->parent_id)
                    ->update(['is_primary' => true]);
            }
        }
    }

    /**
     * يُستدعى بعد حذف رقم هاتف لولي أمر.
     */
    public function cleanupForPhone(StudentPhone $phone): void
    {
        if (!$phone->is_parent || !$phone->parent_name) {
            return;
        }

        $normalized = $this->normalize($phone->phone_number, $phone->student?->country_code);
        $parent = ParentModel::where('phone', $normalized)->first();

        if ($parent) {
            $this->unlink($phone->student_id, $parent->id);
        }
    }

    /**
     * أولياء أمر الطالب مع العلاقات.
     */
    public function forStudent(int $studentId)
    {
        $links = DB::table('student_parents')
            ->where('student_id', $studentId)
            ->orderByDesc('is_primary')
            ->orderBy('created_at')
            ->get()
            ->keyBy('parent_id');

        if ($links->isEmpty()) {
            return collect();
        }

        return ParentModel::whereIn('id', $links->keys())
            ->get()
            ->map(function ($parent) use ($links) {
                $link = $links->get($parent->id);

                return [
                    'id' => $parent->id,
                    'name' => $parent->name,
                    'phone' => $parent->phone,
                    'email' => $parent->email,
                    'status' => $parent->status,
                    'relationship' => $link->relationship ?? null,
                    'is_primary' => (bool) ($link->is_primary ?? false),
                    'students_count' => $parent->students()->count(),
                ];
            })
            ->sortByDesc('is_primary')
            ->values();
    }

    /**
     * توحيد شكل الرقم: بدون + أو 0 في الأول.
     */
    public function normalize(string $phoneNumber, ?string $countryCode = '+20'): string
    {
        $number = preg_replace('/[^0-9]/', '', $phoneNumber);

        $countryDigits = ltrim($countryCode ?: '+20', '+');

        // شيل كود الدولة لو موجود
        if (str_starts_with($number, $countryDigits)) {
            $number = substr($number, strlen($countryDigits));
        }

        // شيل الصفر في الأول
        $number = ltrim($number, '0');

        return $countryDigits . $number;
    }

    /**
     * الإكمال التلقائي: بحث في أولياء الأمر بالرقم أو الاسم.
     */
    public function search(string $query, int $limit = 10)
    {
        $digits = preg_replace('/[^0-9]/', '', $query);

        return ParentModel::query()
            ->when(strlen($digits) >= 3, fn ($q) => $q->where('phone', 'like', "%{$digits}%"))
            ->when(strlen($digits) < 3, fn ($q) => $q->where('name', 'like', "%{$query}%"))
            ->with(['students:id,first_name,middle_name,last_name,student_code'])
            ->limit($limit)
            ->get()
            ->map(function ($parent) {
                $parent->students_count = $parent->students->count();
                return $parent;
            });
    }
}