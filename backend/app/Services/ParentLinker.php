<?php

namespace App\Services;

use App\Models\ParentModel;
use App\Models\Student;
use App\Models\StudentPhone;
use Illuminate\Support\Facades\DB;

/**
 * responsible عن ربط أرقام phones الخاصة بولي الأمر بجدول parents + student_parents.
 *
 * القاعدة: نفس الرقم = نفس ولي الأمر في الداتابيز.
 * لو الأم فاطمة (010111) عندها 3 طلاب، يبقى صف واحد في parents
 * و 3 صفوف في student_parents (كل واحد بعلاقته مع طالبه).
 */
class ParentLinker
{
    /**
     * يبحث عن ولي أمر بنفس الرقم، أو ينشئ واحد جديد.
     *
     * @return array{parent_id: int, created: bool}
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

        // 1) هل في ولي أمر بنفس الرقم؟
        $existing = ParentModel::where('phone', $phone)->first();

        if ($existing) {
            // الاسم يتثبّت على أول قيمة (لا نعدّله بعد كده)
            if ($email && !$existing->email) {
                $existing->update(['email' => $email]);
            }

            $this->link($studentId, $existing->id, $relationship);

            return ['parent_id' => $existing->id, 'created' => false];
        }

        // 2) مافيش → ننشئ واحد جديد
        $parent = ParentModel::create([
            'organization_id' => Student::find($studentId)?->organization_id ?? 1,
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

        $this->link($studentId, $parent->id, $relationship);

        return ['parent_id' => $parent->id, 'created' => true];
    }

    /**
     * يربط الولي بالطالب في student_parents.
     * لو الربط موجود بساقبل نعدّل الـ relationship.
     */
    public function link(int $studentId, int $parentId, ?string $relationship = null): void
    {
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
            return;
        }

        // أول ولي أمر للطالب = الأساسي، والباقي ثانويين
        $isFirst = !DB::table('student_parents')
            ->where('student_id', $studentId)
            ->exists();

        DB::table('student_parents')->insert([
            'student_id' => $studentId,
            'parent_id' => $parentId,
            'relationship' => $relationship,
            'is_primary' => $isFirst,
            'can_manage' => true,
            'can_pay' => true,
            'can_receive_notifications' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // لو مافيش ولي أمر أساسي للطالب بعد (حالة تحديث) نحدّد واحد
        $hasPrimary = DB::table('student_parents')
            ->where('student_id', $studentId)
            ->where('is_primary', true)
            ->exists();

        if (!$hasPrimary) {
            $firstLink = DB::table('student_parents')
                ->where('student_id', $studentId)
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
     * فكّ الربط بين الطالب وولي الأمر.
     * لو الولي مابقاش مرتبط بأي طالب تاني، بيتشال من جدول parents.
     */
    public function unlink(int $studentId, int $parentId): void
    {
        DB::table('student_parents')
            ->where('student_id', $studentId)
            ->where('parent_id', $parentId)
            ->delete();

        $stillLinked = DB::table('student_parents')
            ->where('parent_id', $parentId)
            ->exists();

        if (!$stillLinked) {
            ParentModel::where('id', $parentId)->delete();
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