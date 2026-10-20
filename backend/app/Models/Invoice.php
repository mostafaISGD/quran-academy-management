<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id', 'branch_id', 'student_id', 'parent_id', 'subscription_id',
        'invoice_number', 'issue_date', 'due_date', 'list_amount', 'subtotal', 'discount',
        'discount_reason', 'promo_code_id', 'tax',
        'total', 'paid_amount', 'balance_due', 'credited_amount', 'currency', 'status', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => MoneyCast::class,
            'list_amount' => MoneyCast::class,
            'discount' => MoneyCast::class,
            'tax' => MoneyCast::class,
            'total' => MoneyCast::class,
            'paid_amount' => MoneyCast::class,
            'balance_due' => MoneyCast::class,
            'credited_amount' => MoneyCast::class,
            'issue_date' => 'date',
            'due_date' => 'date',
        ];
    }

    public function student() { return $this->belongsTo(Student::class); }
    public function parent() { return $this->belongsTo(ParentModel::class, 'parent_id'); }
    public function subscription() { return $this->belongsTo(Subscription::class); }

    /** ⭐ الكود الترويجي المطبق على الفاتورة — تتبّع تكلفة الخصومات (PR 2.14). */
    public function promoCode() { return $this->belongsTo(PromoCode::class); }
    public function items() { return $this->hasMany(InvoiceItem::class); }
    public function payments() { return $this->hasMany(Payment::class); }

    /**
     * ⭐ توزيعات الدفعات النشطة على هذه الفاتورة.
     *
     * ⭐ دي اللي بتتحدد منها "إيه اللي اتدفع على هذه الفاتورة" —
     * مش عمود `paid_amount`.
     */
    public function allocations()
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    /**
     * ⭐⭐ إعادة حساب المدفوع والمتبقي من التوزيعات النشطة
     * والرصيد الدائن المطبّق.
     *
     * ⭐ **القاعدة 5 في الخطة:** «لا نخزّن المدفوع والمتبقي على
     * الاشتراك أو الفاتورة كأرقام مستقلة».
     *
     * ⭐ `balance_due = total - paid_amount - credited_amount`
     */
    public function recalculateFromAllocations(): self
    {
        /**
         * ⭐⭐ PR 2.7 — الموديل **مابقاش يكتب بنفسه** (قاعدة 9).
         *
         * ⭐ قبلها الحساب كان كله هنا — يعني فيه **كاتب تاني** لحالة
         * الفاتورة جنب `InvoiceService`. والنتيجة المعروفة: نسختين
         * من الصيغة بتتطور مختلف (واحدة بـ`round` والتانية لأ).
         *
         * ⭐ الحساب بقى في `InvoiceService::recalculateFromAllocations()`
         * والMethod دي بتفوّض — كل النداءات القديمة شغالة زي ما هي.
         *
         * @see \App\Services\InvoiceService::recalculateFromAllocations
         */
        return app(\App\Services\InvoiceService::class)
            ->recalculateFromAllocations($this);
    }

    /**   كل ما اتدفع على هذه الفاتورة (مجموع التوزيعات النشطة) */
    public function paidViaAllocations(): float
    {
        // ⭐ `sum()` بالقرش — لازم `fromDatabase`
        return (float) MoneyCast::fromAggregate(
            $this->allocations()->active()->sum('amount')
        );
    }

    /**   تطبيقات الرصيد الدائن على هذه الفاتورة */
    public function creditApplications()
    {
        return $this->hasMany(CreditNoteApplication::class);
    }

    // ============================================================
    // ⭐⭐ `overdue` **محسوب** — مش مكتوب (PR 2.6)
    // ============================================================

    /**
     * ⭐⭐⭐ «متأخرة» = **محسوبة** من `due_date`، مش تخزينها في `status`.
     *
     * ═══════════════════════════════════════════════════════════
     * ⭐ ليه؟ (قاعدة 10 من `AGENTS.md`)
     *
     * «متأخرة» **حقيقة مش بتتغير** — بتتغير مع الوقت. ولو خزّنّاها
     * في `status`، يبقى لازم حد يعدّل كل فاتورة كل يوم الصبح
     * (cron بيلمس كل الصفوف)، وأي فاتورة فاتCron تعدّي.
     *
     * ⭐ والأخطر: **فاتورة جديدة بسبب `due_date` في الماضي** —
     * لازم تدخل متأخرة من أول يوم. مع `status` المخزّن، تنسى
     * تعملها `overdue` وتفضل `issued`.
     *
     * ⭐ **`status` المخزّن بيقول حاجة تانية**: هل الفاتورة اتدفعت
     * أو اتلغت. `overdue` سؤال مختلف خالص.
     *
     * @see InvoiceService::writeState() الحالة المخزّنة
     */
    public function isOverdue(): bool
    {
        // ⭐ الفاتورة المدفوعة أو الملغاة **مش** متأخرة — مهما كان
        // التاريخ. اتأخرت، اتقفلت، خلاص.
        if (in_array($this->status, ['paid', 'void'], true)) {
            return false;
        }

        if ($this->balance_due <= 0) {
            return false;
        }

        // ⭐ مافيش تاريخ استحقاق = مافيش تأخير
        if ($this->due_date === null) {
            return false;
        }

        return $this->due_date->isPast();
    }

    /**
     * ⭐ الفواتير المتأخرة — **محسوبة في SQL**.
     *
     * ⚠️ مش `where('status', 'overdue')` — دي حالة **مخزّنة** أو
     * مفيش كده. المتأخر محسوب.
     */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', now()->toDateString())
            ->whereIn('status', ['issued', 'partially_paid', 'draft'])
            ->where(function (Builder $q) {
                // ⭐ `CAST` — العمود `bigint` بالقرش، ومقارنة
                // `> 0` على نص في SQLite بتفتّش **نصياً** فـ`"0.00"`
                // بتعدّي. الـCAST بيرجّعها رقم.
                $q->whereNull('balance_due')
                    ->orWhereRaw('CAST(balance_due AS INTEGER) > 0');
            });
    }
}
