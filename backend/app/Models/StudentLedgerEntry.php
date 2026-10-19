<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

class StudentLedgerEntry extends Model
{
    public const UPDATED_AT = null;

    /**
     * ⭐⭐ الحارس — عكس دورة حياة السطر التاريخي.
     *
     * السطر ده **توقيع** على ما حصل: فاتورة ظهرت، فلوس دخلوا،
     * فلوس رجعت. اللي بيعدّل التوقيع بعد كتابته مش بيصحح —
     * بيكسر سلسلة الأرصدة (كل `balance_after` بيقلد ساعته
     * `balance_after` سابقين) فأي تعديل يعمل انحراف صامت
     * في كل السطور بعده، ومتكتشفش إلا بالتسوية.
     *
     * ⭐ التصحيح الصحيح موجود: `StudentLedgerService::write()`
     * بسطر جديد عكسي — والأصل يتم (زي `AccountMovement`).
     */
    protected static function booted(): void
    {
        static::updating(function (self $entry) {
            throw new \RuntimeException(
                'سطر دفتر الطالب ما يتعداش بعد كتابته. التصحيح بسطر جديد عكسي عبر StudentLedgerService.'
            );
        });

        static::deleting(function (self $entry) {
            throw new \RuntimeException(
                'سطر دفتر الطالب ما يتمسحش أبداً. التصحيح بسطر جديد عكسي عبر StudentLedgerService.'
            );
        });
    }

    protected $fillable = [
        'student_id', 'invoice_id', 'payment_id', 'type', 'debit', 'credit',
        'balance_after', 'currency', 'description', 'reference_type',
        'reference_id', 'created_by', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'debit' => MoneyCast::class,
            'credit' => MoneyCast::class,
            'balance_after' => MoneyCast::class,
            'created_at' => 'datetime',
        ];
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }
}
