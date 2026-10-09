<?php

namespace App\Models;

use App\Casts\Piastres;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id', 'branch_id', 'student_id', 'parent_id', 'subscription_id',
        'invoice_number', 'issue_date', 'due_date', 'subtotal', 'discount', 'tax',
        'total', 'paid_amount', 'balance_due', 'credited_amount', 'currency', 'status', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => Piastres::class,
            'discount' => Piastres::class,
            'tax' => Piastres::class,
            'total' => Piastres::class,
            'paid_amount' => Piastres::class,
            'balance_due' => Piastres::class,
            'credited_amount' => Piastres::class,
            'issue_date' => 'date',
            'due_date' => 'date',
        ];
    }

    public function student() { return $this->belongsTo(Student::class); }
    public function parent() { return $this->belongsTo(ParentModel::class, 'parent_id'); }
    public function subscription() { return $this->belongsTo(Subscription::class); }
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
         * ⭐⭐ الـ `sum()` بيرجّع **قرش** — والـ `total` بالتحت
         * **جنيه** (لأنه خاصية بتعدّي على الـ cast).
         *
         * فلو ما مرّرناش التوزيعات على `fromDatabase()`:
         *     650.00 (جنيه) - 20000 (قرش) = -19350  ⭐ كارثة
         *
         * @see \App\Casts\Piastres::fromDatabase
         */
        $paid = (float) Piastres::fromDatabase(
            $this->allocations()->active()->sum('amount')
        );

        $credited = (float) Piastres::fromDatabase(
            $this->creditApplications()->active()->sum('amount')
        );

        $total = (float) $this->total;
        $balance = max(0, $total - $paid - $credited);

        $status = match (true) {
            $paid + $credited <= 0 => 'issued',
            $balance <= 0 => 'paid',
            default => 'partially_paid',
        };

        $this->update([
            'paid_amount' => round($paid, 2),
            'credited_amount' => round($credited, 2),
            'balance_due' => round($balance, 2),
            'status' => $status,
        ]);

        return $this->fresh();
    }

    /**   كل ما اتدفع على هذه الفاتورة (مجموع التوزيعات النشطة) */
    public function paidViaAllocations(): float
    {
        // ⭐ `sum()` بالقرش — لازم `fromDatabase`
        return (float) Piastres::fromDatabase(
            $this->allocations()->active()->sum('amount')
        );
    }

    /**   تطبيقات الرصيد الدائن على هذه الفاتورة */
    public function creditApplications()
    {
        return $this->hasMany(CreditNoteApplication::class);
    }
}
