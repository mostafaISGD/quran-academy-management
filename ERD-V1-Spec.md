# Quran Academy — Database ERD V1 (Laravel + PostgreSQL)

## المبادئ الثابتة

1. **Primary Key**: ULID (مش BIGINT)
2. **Audit Columns**: `created_at`, updated_at لكل جدول مهم
3. **Soft Delete**: `deleted_at` للكيانات التي تحتاج Archive
4. **لا حذف مالي**: Payment / Invoice / Refund لا تُحذف فعليًا
5. **لا قيم محسوبة مخزنة**: لا نخزن `total_earnings` كمصدر للحقيقة
6. **التاريخ المالي Immutable**: التعديل عبر Adjustment / Refund / Reversal

## الهيكل التنظيمي

```
Organization → Branches → Users → Students/Teachers
                                ↓
                           Subscriptions → Credits → Lessons
                                ↓
                           Invoices → Payments → Ledger
```

## الجداول الأساسية (38+ جدول)

| المجال | الجداول |
|---------|---------|
| **الهيكل** | organizations, branches |
| **المستخدمين** | users, roles, permissions, role_permissions, user_roles |
| **الأهلية** | parents, student_parents |
| **الطلاب** | students, student_goals |
| **المعلمين** | teachers, teacher_contracts, teacher_rates |
| **البرامج** | programs, levels, quran_surahs |
| **الاشتراكات** | subscription_plans, subscriptions, subscription_pauses |
| **الرصيد** | lesson_credit_accounts, lesson_credit_transactions |
| **الحصص** | lessons, lesson_attendance, lesson_reschedules |
| **التقدم** | memorization_records, progress_records |
| **الفوترة** | invoices, invoice_items, payments, refunds |
| **دفاتر** | student_ledger_entries, teacher_ledger_entries |
| **المعلمين** | teacher_earnings, teacher_payments, payroll_periods |
| **المصاريف** | expenses, expense_categories |
| **CRM** | leads, assessments |

## القرارات التصميمية المهمة

1. **MAKEUP** = lesson_type وليس status
2. **Credits** = Ledger pattern (current_balance مش كافي، لازم transactions)
3. **Invoices** = منفصلة عن Payments (1 invoice → N payments)
4. **Payroll Periods** = تجميع مستحقات المعلمين حسب الفترة
5. **Student/Teacher Ledger** = سجل مالي كامل
6. **Surahs** = Reference data (76 سورة)

## خطط التنفيذ

### Phase 1: البنية التحتية
- ULID migration helper
- Organization/Branch structure
- Refactor users table

### Phase 2: الطلاب والمعلمين
- student_parents pivot
- teacher_contracts + teacher_rates
- student_goals

### Phase 3: الاشتراكات والرصيد
- subscription_pauses
- lesson_credit_accounts + transactions
- Lesson types (REGULAR, TRIAL, MAKEUP, etc.)

### Phase 4: الفوترة
- invoices + invoice_items
- payments + refunds
- student_ledger_entries

### Phase 5: المعلمين
- teacher_earnings (بـ rate_id)
- teacher_payments
- payroll_periods
- teacher_ledger_entries

### Phase 6: المصاريف و CRM
- expenses + expense_categories
- leads + assessments
- quran_surahs reference data
