<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Http\Request;

/**
 * تسجيل النشاط في audit_logs.
 *
 * الجدول كان موجود بس فاضي — فيه بيانات من الـ seeder بس ومفيش كود
 * بيكتب فيه. الخدمة دي هي اللي بتتوصل في الـ controllers.
 *
 * ليش مش observer على الـ models؟ لأن عايزين نعرف بالظبط إمتى نكتب:
 * مش كل تغيير في الداتابيز لازم يتسجل، بس الإجراءات اللي المستخدم
 * بيعملها عمداً (أنشأ طالب، عدّل فاتورة، سجّل دخول).
 */
class AuditLogService
{
    /**
     * تسجيل إجراء.
     *
     * @param  string  $action      create | update | delete | login | refund
     * @param  string  $entityType  student | teacher | subscription | invoice | payment | employee | user
     * @param  int     $entityId    معرّف الصف المتأثر
     * @param  mixed   $oldValue    القيم القديمة (للتعديل)
     * @param  mixed   $newValue    القيم الجديدة
     * @param  int|null $userId      من نفّذ الإجراء — لازم نمرره صراحةً
     *                               في تسجيل الدخول لأن المستخدم لسه
     *                               مش متحقّق (audit_logs.user_id NOT NULL)
     */
    public function log(
        string $action,
        string $entityType,
        int $entityId,
        mixed $oldValue = null,
        mixed $newValue = null,
        ?Request $request = null,
        ?int $userId = null,
    ): ?AuditLog {
        $userId ??= $request?->user()?->id ?? auth()->id();

        // سجل النشاط ما يشترشش على العملية الأصلية — لو مفيش
        // مستخدم (أمر  Artisan مثلاً) بنتخطّى بدل ما نكسر الطلب
        if ($userId === null) {
            return null;
        }

        return AuditLog::create([
            'user_id' => $userId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'old_value' => $oldValue !== null ? json_encode($oldValue, JSON_UNESCAPED_UNICODE) : null,
            'new_value' => $newValue !== null ? json_encode($newValue, JSON_UNESCAPED_UNICODE) : null,
            'ip_address' => $request?->ip(),
            'created_at' => now(),
        ]);
    }

    /** اختصار للإنشاء */
    public function logCreate(string $entityType, int $entityId, mixed $newValue = null, ?Request $request = null): ?AuditLog
    {
        return $this->log('create', $entityType, $entityId, null, $newValue, $request);
    }

    /** اختصار للتعديل — بيبعت القديم والجديد */
    public function logUpdate(string $entityType, int $entityId, mixed $oldValue, mixed $newValue, ?Request $request = null): ?AuditLog
    {
        return $this->log('update', $entityType, $entityId, $oldValue, $newValue, $request);
    }

    /** اختصار للحذف */
    public function logDelete(string $entityType, int $entityId, mixed $oldValue = null, ?Request $request = null): ?AuditLog
    {
        return $this->log('delete', $entityType, $entityId, $oldValue, null, $request);
    }
}
