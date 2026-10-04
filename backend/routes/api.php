<?php

use App\Http\Controllers\Api\AssessmentController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\LeadController;
use App\Http\Controllers\Api\LessonController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ParentController;
use App\Http\Controllers\Api\ParentPortalController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ProgramController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\StudentController;
use App\Http\Controllers\Api\StudentPhoneController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\TeacherController;
use App\Http\Controllers\Api\TeacherScheduleController;
use App\Http\Controllers\Api\TeacherEarningController;
use App\Http\Controllers\Api\TeacherPaymentController;
use Illuminate\Support\Facades\Route;

// ---- Public ----
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/auth/reset-password', [AuthController::class, 'resetPassword']);

// ---- Authenticated ----
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    // Students
    Route::get('/students', [StudentController::class, 'index'])->middleware('permission:students.view');
    Route::post('/students', [StudentController::class, 'store'])->middleware('permission:students.create');
    Route::get('/students/{student}', [StudentController::class, 'show'])->middleware('permission:students.view');
    Route::put('/students/{student}', [StudentController::class, 'update'])->middleware('permission:students.edit');
    Route::delete('/students/{student}', [StudentController::class, 'destroy'])->middleware('permission:students.delete');
// إجراءات جماعية — لازم تيجي قبل /students/{student} عشان ما تلتقطش كـ id
Route::post('/students/bulk/change-teacher', [StudentController::class, 'bulkChangeTeacher'])->middleware('permission:students.edit');
Route::post('/students/bulk/create-invoices', [StudentController::class, 'bulkCreateInvoices'])->middleware('permission:payments.create');
Route::post('/students/bulk/notify', [StudentController::class, 'bulkNotify'])->middleware('permission:students.edit');
    Route::get('/students/{student}/schedule', [StudentController::class, 'schedule'])->middleware('permission:students.view');
    Route::get('/students/{student}/attendance', [StudentController::class, 'attendance'])->middleware('permission:students.view');
    Route::get('/students/{student}/progress', [StudentController::class, 'progress'])->middleware('permission:students.view');
    Route::get('/students/{student}/payments', [StudentController::class, 'payments'])->middleware('permission:payments.view');

    // Teachers
    Route::get('/teachers', [TeacherController::class, 'index'])->middleware('permission:teachers.view');
    Route::post('/teachers', [TeacherController::class, 'store'])->middleware('permission:teachers.create');
    Route::get('/teachers/{teacher}', [TeacherController::class, 'show'])->middleware('permission:teachers.view');
    Route::put('/teachers/{teacher}', [TeacherController::class, 'update'])->middleware('permission:teachers.edit');
    Route::delete('/teachers/{teacher}', [TeacherController::class, 'destroy'])->middleware('permission:teachers.delete');
    Route::get('/teachers/{teacher}/schedule', [TeacherController::class, 'schedule'])->middleware('permission:teachers.view');
    Route::get('/teachers/{teacher}/overview', [TeacherController::class, 'overview'])->middleware('permission:teachers.view');
    Route::get('/teachers/{teacher}/students', [TeacherController::class, 'students'])->middleware('permission:teachers.view');
    Route::get('/teachers/{teacher}/lessons-summary', [TeacherController::class, 'lessonsSummary'])->middleware('permission:teachers.view');
    Route::get('/teachers/{teacher}/financial-summary', [TeacherController::class, 'financialSummary'])->middleware('permission:payments.view');
    Route::get('/teachers/{teacher}/earnings', [TeacherController::class, 'earnings'])->middleware('permission:payments.view');
    Route::get('/teachers/{teacher}/contracts', [TeacherController::class, 'contracts'])->middleware('permission:teachers.view');
    Route::get('/teachers/{teacher}/rates', [TeacherController::class, 'rates'])->middleware('permission:teachers.view');
    Route::get('/teachers/{teacher}/ratings', [TeacherController::class, 'ratings'])->middleware('permission:teachers.view');
    Route::post('/teachers/{teacher}/ratings', [TeacherController::class, 'storeRating'])->middleware('permission:students.view');

    // Teacher Availability — جدول commitments المعلم (أكاديمية + شغل خارجي)
    // المسار اسمه availability مش schedule عشان /teachers/{teacher}/schedule
    // موجود أصلاً وبيرجّع حصص المعلم.
    //
    // ملاحظة: مفيش permission middleware على مسارات المعلم الشخصية، لأن
    // authorizeOwnership() جوه الـ controller هو اللي بيمنع يعدّل غير جدوله.
    Route::get('/teacher-availability', [TeacherScheduleController::class, 'indexAll'])->middleware('permission:teachers.view');
    Route::get('/availability/teachers', [TeacherScheduleController::class, 'availableTeachers'])->middleware('permission:students.view');
    Route::get('/teachers/{teacher}/availability/completeness', [TeacherScheduleController::class, 'completeness']);
    Route::get('/teachers/{teacher}/availability', [TeacherScheduleController::class, 'index']);
    Route::post('/teachers/{teacher}/availability', [TeacherScheduleController::class, 'store']);
    Route::put('/teachers/{teacher}/availability/{block}', [TeacherScheduleController::class, 'update']);
    Route::delete('/teachers/{teacher}/availability/{block}', [TeacherScheduleController::class, 'destroy']);

    // Programs
    Route::get('/programs', [ProgramController::class, 'index'])->middleware('permission:settings.manage');
    Route::post('/programs', [ProgramController::class, 'store'])->middleware('permission:settings.manage');
    Route::get('/programs/{program}', [ProgramController::class, 'show'])->middleware('permission:settings.manage');
    Route::put('/programs/{program}', [ProgramController::class, 'update'])->middleware('permission:settings.manage');
    Route::delete('/programs/{program}', [ProgramController::class, 'destroy'])->middleware('permission:settings.manage');

    // Roles & Permissions
    Route::get('/roles', [RoleController::class, 'index'])->middleware('permission:settings.manage');
    Route::post('/roles', [RoleController::class, 'store'])->middleware('permission:settings.manage');
    Route::put('/roles/{role}/permissions', [RoleController::class, 'updatePermissions'])->middleware('permission:settings.manage');
    Route::get('/permissions', [RoleController::class, 'allPermissions'])->middleware('permission:settings.manage');

    // Subscriptions
    Route::get('/subscriptions', [SubscriptionController::class, 'index'])->middleware('permission:students.view');
    Route::post('/subscriptions', [SubscriptionController::class, 'store'])->middleware('permission:students.edit');
    Route::get('/subscriptions/{subscription}', [SubscriptionController::class, 'show'])->middleware('permission:students.view');
    Route::put('/subscriptions/{subscription}', [SubscriptionController::class, 'update'])->middleware('permission:students.edit');
    Route::delete('/subscriptions/{subscription}', [SubscriptionController::class, 'destroy'])->middleware('permission:students.edit');

    // المعالجة اليومية — نفس اللي بيحصل بالأمر التلقائي، عشان
    // الأدمن يشغّلها بإيده لما يفضّل
    Route::post('/subscriptions/process-day', [SubscriptionController::class, 'processDay'])
        ->middleware('permission:students.edit');

    // ===== واجهة ولي الأمر =====
    // البراوت يضمن إن الحساب ده ولي أمر فعلاً. وكمان الـ controller
    // بيتأكد من إن كل ابن مرتبط بيه — عشوائياً لا يكفي.
    Route::get('/parent/children', [ParentPortalController::class, 'children'])
        ->middleware('parent.only');

    // Schedule / Lessons
    Route::get('/schedule', [LessonController::class, 'schedule'])->middleware('permission:lessons.view');
    Route::post('/lessons', [LessonController::class, 'store'])->middleware('permission:lessons.create');
    Route::post('/lessons/recurring', [LessonController::class, 'storeRecurring'])->middleware('permission:lessons.create');
    Route::put('/lessons/{lesson}', [LessonController::class, 'update'])->middleware('permission:lessons.edit');
    Route::post('/lessons/{lesson}/cancel', [LessonController::class, 'cancel'])->middleware('permission:lessons.cancel');
    Route::post('/lessons/{lesson}/makeup', [LessonController::class, 'makeup'])->middleware('permission:lessons.create');
    Route::post('/lessons/{lesson}/complete', [LessonController::class, 'complete'])->middleware('permission:lessons.edit');
    Route::post('/lessons/{lesson}/attendance', [LessonController::class, 'attendance'])->middleware('permission:lessons.edit');
    Route::post('/lessons/{lesson}/memorization', [LessonController::class, 'memorization'])->middleware('permission:lessons.edit');

    // Invoices
    Route::get('/invoices', [InvoiceController::class, 'index'])->middleware('permission:payments.view');
    Route::post('/invoices', [InvoiceController::class, 'store'])->middleware('permission:payments.create');
    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->middleware('permission:payments.view');
    Route::put('/invoices/{invoice}', [InvoiceController::class, 'update'])->middleware('permission:payments.edit');
    Route::delete('/invoices/{invoice}', [InvoiceController::class, 'destroy'])->middleware('permission:payments.edit');

    // Payments
    Route::get('/payments', [PaymentController::class, 'index'])->middleware('permission:payments.view');
    Route::post('/payments', [PaymentController::class, 'store'])->middleware('permission:payments.create');
    Route::get('/payments/{payment}', [PaymentController::class, 'show'])->middleware('permission:payments.view');
    Route::post('/payments/{payment}/refund', [PaymentController::class, 'refund'])->middleware('permission:payments.refund');
    Route::get('/payments/{payment}/refunds', [PaymentController::class, 'refunds'])->middleware('permission:payments.view');

    // CRM — Leads
    Route::get('/leads', [LeadController::class, 'index'])->middleware('permission:leads.view');
    Route::post('/leads', [LeadController::class, 'store'])->middleware('permission:leads.create');
    Route::get('/leads/{lead}', [LeadController::class, 'show'])->middleware('permission:leads.view');
    Route::put('/leads/{lead}', [LeadController::class, 'update'])->middleware('permission:leads.edit');
    Route::put('/leads/{lead}/status', [LeadController::class, 'updateStatus'])->middleware('permission:leads.edit');
    Route::delete('/leads/{lead}', [LeadController::class, 'destroy'])->middleware('permission:leads.edit');

    // CRM — Assessments
    Route::get('/assessments', [AssessmentController::class, 'index'])->middleware('permission:leads.view');
    Route::post('/assessments', [AssessmentController::class, 'store'])->middleware('permission:leads.edit');
    Route::put('/assessments/{assessment}/result', [AssessmentController::class, 'updateResult'])->middleware('permission:leads.edit');
    Route::post('/assessments/{assessment}/convert', [AssessmentController::class, 'convert'])->middleware('permission:students.create');

    // Notifications
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markAsRead']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);

    // Audit Logs
    Route::get('/audit-logs', [AuditLogController::class, 'index'])->middleware('permission:settings.manage');

    // Dashboard & Reports
    Route::get('/dashboard/summary', [ReportController::class, 'dashboardSummary'])->middleware('permission:reports.view');
    Route::get('/reports/financial', [ReportController::class, 'financial'])->middleware('permission:reports.view');
    Route::get('/reports/academic', [ReportController::class, 'academic'])->middleware('permission:reports.view');
    Route::get('/reports/sales', [ReportController::class, 'sales'])->middleware('permission:reports.view');

    // Settings
    Route::get('/settings', [SettingController::class, 'index'])->middleware('permission:settings.manage');
    Route::post('/settings', [SettingController::class, 'store'])->middleware('permission:settings.manage');
    Route::delete('/settings/{key}', [SettingController::class, 'destroy'])->middleware('permission:settings.manage');

    // Employees
    Route::get('/employees', [EmployeeController::class, 'index'])->middleware('permission:students.view');
    Route::post('/employees', [EmployeeController::class, 'store'])->middleware('permission:students.edit');
    Route::get('/employees/{employee}', [EmployeeController::class, 'show'])->middleware('permission:students.view');
    Route::put('/employees/{employee}', [EmployeeController::class, 'update'])->middleware('permission:students.edit');
    Route::delete('/employees/{employee}', [EmployeeController::class, 'destroy'])->middleware('permission:students.edit');
    Route::get('/employees/{employee}/activity', [EmployeeController::class, 'activity'])->middleware('permission:students.view');

    // ===== الحضور والانصراف — كله على الأدمن =====
    Route::get('/attendance/day', [AttendanceController::class, 'day'])->middleware('permission:students.view');
    Route::post('/attendance', [AttendanceController::class, 'store'])->middleware('permission:students.edit');
    Route::get('/employees/{employee}/attendance-month', [AttendanceController::class, 'month'])->middleware('permission:students.view');

    // Expenses
    Route::get('/expenses', [ExpenseController::class, 'index']);
    Route::post('/expenses', [ExpenseController::class, 'store']);

    // Teacher Earnings & Payments
    Route::get('/teacher-earnings', [TeacherEarningController::class, 'index']);
    Route::get('/teacher-payments', [TeacherPaymentController::class, 'index']);

    // Parents
    Route::get('/parents', [ParentController::class, 'index']);
    Route::post('/parents', [ParentController::class, 'store']);

    // Student Phones
    Route::get('/students/{student}/phones', [StudentPhoneController::class, 'index']);
    Route::post('/students/{student}/phones', [StudentPhoneController::class, 'store']);
    Route::post('/students/{student}/phones/bulk', [StudentPhoneController::class, 'bulkStore']);
    Route::put('/students/{student}/phones/{phone}', [StudentPhoneController::class, 'update']);
    Route::delete('/students/{student}/phones/{phone}', [StudentPhoneController::class, 'destroy']);
    Route::post('/students/{student}/phones/{phone}/set-primary', [StudentPhoneController::class, 'setPrimary']);
    Route::post('/students/{student}/phones/{phone}/toggle', [StudentPhoneController::class, 'toggleField']);
    Route::get('/parents/search', [StudentPhoneController::class, 'searchParents']);
    Route::get('/students/{student}/parents', [StudentPhoneController::class, 'listParents']);
    Route::post('/students/{student}/parents/{parent}/set-primary', [StudentPhoneController::class, 'setPrimaryParent']);
    Route::get('/phones/search', [StudentPhoneController::class, 'search']);
    Route::get('/phones/search/parent', [StudentPhoneController::class, 'searchByParentPhone']);
});
