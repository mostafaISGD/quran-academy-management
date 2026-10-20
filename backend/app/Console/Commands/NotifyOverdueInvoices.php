<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\Notification;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * ⭐ تنبيه الفواتير المتأخرة.
 *
 * ⭐ يشتغل يومياً الساعة 09:00 ويبعث تنبيهات للأهالي والإدارة.
 *
 * القواعد:
 * - الفاتورة `issued` أو `partially_paid` ومعدية `due_date`
 * - نبعث تنبيه للأهل (لو فيهم user) + للإدارة
 * - الفترات: 3، 7، 14 يوم متأخرة — مش نكرر لنفس الفاتورة نفس الفترة
 */
class NotifyOverdueInvoices extends Command
{
    protected $signature = 'invoices:notify-overdue
        {--date= : تاريخ التشغيل (YYYY-MM-DD) — افتراضي اليوم}
        {--dry-run : عرض اللي هيحصل من غير ما نبعث}';

    protected $description = 'بعث تنبيهات للفواتير المتأخرة (3، 7، 14 يوم)';

    public function handle(): int
    {
        $date = $this->option('date') ? Carbon::parse($this->option('date')) : Carbon::today();
        $dryRun = $this->option('dry-run');

        $this->info("📅 تنبيه الفواتير المتأخرة لـ {$date->toDateString()}");

        // الفترات اللي بنبعت فيها
        $periods = [3, 7, 14];

        $totalSent = 0;

        foreach ($periods as $days) {
            $targetDate = $date->copy()->subDays($days);

            $invoices = Invoice::query()
                ->whereIn('status', ['issued', 'partially_paid'])
                ->whereDate('due_date', $targetDate)
                // ⭐ PR 2.16 — `student.parent` علاقة مش موجودة على
                // `Student` (الصحيح `parents()` جمع) — كان ArrayException
                // قبل أول فاتورة. والأولياء بيتجيبوا في الرّسالة نفسها.
                ->with(['student.user', 'student.parents.user'])
                ->get();

            $this->line("  📋 {$days} يوم متأخر: {$invoices->count()} فاتورة");

            foreach ($invoices as $invoice) {
                $sent = $this->sendOverdueNotification($invoice, $days, $dryRun);
                $totalSent += $sent;
            }
        }

        $this->newLine();
        $this->info("✅ تم بعث {$totalSent} تنبيه");

        return self::SUCCESS;
    }

    private function sendOverdueNotification(Invoice $invoice, int $daysOverdue, bool $dryRun): int
    {
        $sent = 0;
        $key = "overdue_{$daysOverdue}d_invoice_{$invoice->id}";

        // ⭐ نتحقق لو بعتها الفترة دي قبل كده (باستخدام cache أو فحص notifications table)
        $alreadySent = \Cache::has($key) ||
            Notification::where('event_type', 'invoice_overdue')
                ->where('payload->invoice_id', $invoice->id)
                ->where('payload->days_overdue', $daysOverdue)
                ->exists();

        if ($alreadySent) {
            $this->line("    ⏭️ فاتورة {$invoice->invoice_number}: تم بعث تنبيه {$daysOverdue} يوم من قبل");
            return 0;
        }

        $recipients = [];

        // 1. أولياء الأمر المرتبطين بالطالب (ربط مؤكد فقط) — لو منهم user
        //
        // ⭐⭐ PR 2.16 — الكود القديم كان يستعمل علاقة `parent`
        // **غير الموجودة** على `Student`، والصحيح صيغة الجمع
        // `parents()` عبر جدول `student_parents` — فالأمر كان يرمي
        // RelationNotFoundException مع أول فاتورة لها طالب، والخلل
        // ده كان مستخبي لأن الأمر من غير اختبار.
        $parentUsers = $invoice->student
            ? $invoice->student->parents()
                ->wherePivot('status', 'verified')
                ->with('user')
                ->get()
                ->pluck('user')
                ->filter()
            : collect();
        foreach ($parentUsers as $parentUser) {
            $recipients[] = [
                'user_id' => $parentUser->id,
                'type' => 'parent',
            ];
        }

        // 2. الطالب (لو في user)
        if ($invoice->student?->user) {
            $recipients[] = [
                'user_id' => $invoice->student->user->id,
                'type' => 'student',
            ];
        }
        // 3. الإدارة (كل admins)
        $admins = User::role('admin')->get(['id']);
        foreach ($admins as $admin) {
            $recipients[] = [
                'user_id' => $admin->id,
                'type' => 'admin',
            ];
        }

        if (empty($recipients)) {
            $this->line("    ⚠️ فاتورة {$invoice->invoice_number}: مفيش recipients");
            return 0;
        }

        if ($dryRun) {
            $this->line("    🔍 [DRY RUN] فاتورة {$invoice->invoice_number} ({$invoice->balance_due} {$invoice->currency}) متأخرة {$daysOverdue} يوم — هنبعت لـ " . count($recipients) . " مستلمين");
            return count($recipients);
        }

        foreach ($recipients as $recipient) {
            Notification::create([
                'user_id' => $recipient['user_id'],
                'student_id' => $invoice->student_id,
                'event_type' => 'invoice_overdue',
                'channel' => 'in_app',
                'payload' => [
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'amount_due' => (float) $invoice->balance_due,
                    'currency' => $invoice->currency,
                    'due_date' => $invoice->due_date->toDateString(),
                    'days_overdue' => $daysOverdue,
                    'recipient_type' => $recipient['type'],
                ],
                'sent_at' => now(),
            ]);
        }

        // ⭐ نحفظ في cache عشان ما نكررش في نفس اليوم
        \Cache::put($key, true, now()->addDay());

        return count($recipients);
    }
}