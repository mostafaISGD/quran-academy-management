<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;

/**
 * مرفق مصروف — ملف على القرص المحلي الخاص (خارج `public`)،
 * والوصول للرابط موقّع مؤقت فقط.
 *
 * @see \App\Http\Controllers\Api\ExpenseController::downloadAttachment
 */
class ExpenseAttachment extends Model
{
    protected $fillable = [
        'expense_id', 'path', 'original_name', 'mime', 'size', 'uploaded_by',
    ];

    /**
     * ⭐ الرابط الموقّع بيتولّد لحظة الرد (مش مخزّن) — فالصفوف
     * القديمة بترجع روابط سارية 30 دقيقة، من غير re-upload.
     */
    protected $appends = ['download_url'];

    protected function downloadUrl(): Attribute
    {
        return Attribute::get(function () {
            return URL::temporarySignedRoute(
                'expense-attachments.download',
                now()->addMinutes(30),
                ['attachment' => $this->id],
            );
        });
    }

    public function expense()
    {
        return $this->belongsTo(Expense::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
