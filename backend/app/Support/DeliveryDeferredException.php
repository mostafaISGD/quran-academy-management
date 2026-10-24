<?php

namespace App\Support;

/**
 * ⭐⭐⭐ **PR 4.1 — تسليم مؤجَّل إرادةً.**
 *
 * يُرمى دون لمس الصف ولا عدّاد المحاولات في حالتين:
 *
 * - ساعات الهدوء (من مركز الإعدادات) — الصف يبقى `queued`.
 * - قناة مؤجلة بمواصفة المرحلة الرابعة (`whatsapp` واجهة
 *   معلقة) — يقبضها الأمر المجدول حين يتوفر مزودها.
 */
class DeliveryDeferredException extends \RuntimeException
{
}
