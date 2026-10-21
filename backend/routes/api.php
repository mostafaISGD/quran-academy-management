<?php

use App\Http\Controllers\Api\AssessmentController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\EmployeePayrollController;
use App\Http\Controllers\Api\InvitationController;
use App\Http\Controllers\Api\TeacherPayrollController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\PricingController;
use App\Http\Controllers\Api\GroupController;
use App\Http\Controllers\Api\WaitlistController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\ExpenseCategoryController;
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
use App\Http\Controllers\Api\FinancialAccountController;
use App\Http\Controllers\Api\StudentController;
use App\Http\Controllers\Api\StudentPhoneController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\TeacherController;
use App\Http\Controllers\Api\TeacherScheduleController;
use App\Http\Controllers\Api\TeacherEarningController;
use App\Http\Controllers\Api\TeacherLedgerController;
use App\Http\Controllers\Api\TeacherPaymentController;
use App\Http\Controllers\Api\StudentCreditController;
use App\Http\Controllers\Api\StudentConsentController;
use App\Http\Controllers\Api\LessonJoinTokenController;
use App\Http\Controllers\Api\ExportController;
use App\Http\Controllers\Api\LessonPolicyController;
use App\Http\Controllers\Api\PromoCodeController;
// ⭐⭐ PR 2.9 — تنبيهات التسوية المالية
use App\Http\Controllers\Api\FinanceAlertController;
use Illuminate\Support\Facades\Route;

// ---- Public ----
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword'])
    ->middleware('throttle:5,1');
Route::post('/auth/reset-password', [AuthController::class, 'resetPassword'])
    ->middleware('throttle:5,1');

    // ===== جدول الأسعار =====
    //
    // ⭐ **عام** — مفيش `auth:sanctum` ومفيش `permission`.
    //
    // السبب: الأسعار حاجة **الأهالي** بيسألوا عنها من غير حساب
    // دخول. لو حطيناها جوه `auth:sanctum`، اللي بيسأل هيتقابل
    // بـ «Unauthenticated» — وده جواب غلط على سؤال صحيح.
    //
    // ⚠️ التصميم: نرجّع **الأسعار بس**. مفيش بيانات طلاب ولا
    // مدرسين ولا أرباح — يعني مفيش حاجة تتسرب.
    Route::get('/pricing', [PricingController::class, 'index']);
    Route::get('/pricing/export', [PricingController::class, 'export']);

    // ⭐⭐ PR 2.12 — تنزيل مرفق مصروف برابط موقّع مؤقت.
    //
    // مفيش `auth:sanctum` ولا permission — الحماية هنا هي التوقيع
    // نفسه (`signed`): الرابط بيتولد عند الرفع، صالح 30 دقيقة،
    // وأي تعديل على أي باراميتر بيأدّي لرفض 403. الرفع نفسه محمي
    // فوق بـ `finance.expenses.create`.
    Route::get('/expense-attachments/{attachment}/download', [ExpenseController::class, 'downloadAttachment'])
        ->middleware('signed')
        ->name('expense-attachments.download');

    // ⭐ التعديل **مش** عام — بيحتاج تسجيل دخول + صلاحية.
    // العرض عام، والتعديل قرار تجاري للإدارة بس.
    Route::put('/pricing/{plan}', [PricingController::class, 'update'])
        ->middleware(['auth:sanctum', 'account.active', 'password.changed', 'permission:pricing.plans.manage']);

    // ⭐ إنشاء/حذف باقة — الأدمن بس (قرار تجاري)
    Route::post('/pricing', [PricingController::class, 'store'])
        ->middleware(['auth:sanctum', 'account.active', 'password.changed', 'permission:pricing.plans.manage']);
    Route::delete('/pricing/{plan}', [PricingController::class, 'destroy'])
        ->middleware(['auth:sanctum', 'account.active', 'password.changed', 'permission:pricing.plans.manage']);

    // ============================================================
    // ⭐⭐ الأكواد الترويجية (Promo Codes)
    //
    // ⚠️⚠️⚠️ **كلهم محتاجين `auth:sanctum` — ومن غيره 403!**
    //
    // middleware الـ `permission` لوحده **مش كافي**: هو بيشتغل
    // على `$request->user()`، فلو الـ token مش متأكد من الـ
    // middleware بيفضل null وبيرجع «غير مصرح» — حتى لو الـ token
    // **صالح** والصلاحية موجودة.
    //
    // ⭐ اللي حصل فعلاً: `/promo-codes` كان بيرجع 403 للمدير
    // نفسه، مع إن `pricing.view` كانت في التوكن.
    //
    // والترتيب مهم: `auth:sanctum` **قبل** `permission`.
    // ============================================================
    Route::get('/promo-codes', [PromoCodeController::class, 'index'])
        ->middleware(['auth:sanctum', 'account.active', 'password.changed', 'permission:pricing.view']);
    Route::post('/promo-codes', [PromoCodeController::class, 'store'])
        ->middleware(['auth:sanctum', 'account.active', 'password.changed', 'permission:pricing.plans.manage']);
    Route::get('/promo-codes/{promoCode}', [PromoCodeController::class, 'show'])
        ->middleware(['auth:sanctum', 'account.active', 'password.changed', 'permission:pricing.view']);
    Route::put('/promo-codes/{promoCode}', [PromoCodeController::class, 'update'])
        ->middleware(['auth:sanctum', 'account.active', 'password.changed', 'permission:pricing.plans.manage']);
    Route::delete('/promo-codes/{promoCode}', [PromoCodeController::class, 'destroy'])
        ->middleware(['auth:sanctum', 'account.active', 'password.changed', 'permission:pricing.plans.manage']);
    // التحقق من كود (عامة للواجهة عند الإدخال، محمية عشان ما يتسحبش الكودات)
    Route::post('/promo-codes/validate', [PromoCodeController::class, 'validate'])
        ->middleware(['auth:sanctum', 'account.active', 'password.changed', 'permission:pricing.view']);
    Route::get('/promo-codes/{promoCode}/stats', [PromoCodeController::class, 'stats'])
        ->middleware(['auth:sanctum', 'account.active', 'password.changed', 'permission:pricing.view']);

    // ===== المجموعات الأونلاين + قائمة الانتظار =====
    //
    // ⭐ **الطلب عام، والدخول محمي.**
    //
    //  - العرض: عام — زي الأسعار، الأولاد بيسألوا «فيه مجموعة
    //    للتحفيظ؟»
    //  - التسجيل في الطابور: **أي حد من غير حساب**. أكتر الناس
    //    اللي بتطلب هي اللي لسه ما عندهمش اشتراك — ولو ربطناها
    //    بحساب، اللي عايزينه هو اللي مش هيقدر يسجّل.
    //  - **«ادخل» / «ارفض» / إدارة المجموعة**: `groups.manage`.
    //    الاستقبال بياخد الطلب ويحطّه في الطابور، بس **اللي بيقرّر
    //    «يدخل» هو الإدارة** — عشان لازم حد مسؤول يبقى عارف مين
    //    داخل.
    Route::get('/groups', [GroupController::class, 'index']);

    // ⚠️⭐ **`/groups/alerts` لازم قبل `{group}` — مش ترتيب تنسيق.**
    //
    // `{group}` بيلقط **أي** كلمة بعد `/groups/`. فلو `alerts`
    // بعده، الطلب بيروح لـ `show` بـ id = "alerts" وبيرجّع 404
    // — يعني **الجرس ما اشتغلش أصلاً** مهما كلّفنا كلام.
    //
    // وهو محمي بـ `auth:sanctum`، فمش ينفع نعمله عام. فالحل:
    // guard جوّه الـ route نفسه — يتطابق أول، ويحمي بعد.
    //
    // ⚠️ لازم `auth:sanctum` **جوّه** الـ group مع `permission`، زي
    // بالظبط باقي الـ routes. لو حطيناهم array في_route واحد، الـ
    // `CheckPermission` بيشتغل قبل ما `auth` يحدّد الـ guard فيبوق
    // إن `$request->user()` فاضي ويرجّع 403 **غلط**.
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/groups/alerts', [GroupController::class, 'alerts'])
            ->middleware('permission:groups.manage');
    });

    Route::get('/groups/{group}', [GroupController::class, 'show']);

    // ⭐⭐ حد على التسجيل في الطابور — ١٠ في الساعة لكل IP.
    //
    // ⭐ الراوت ده **عام** (أي حد من غير حساب) و**بيكتب في
    // الداتابيز**. من غير حد، حد يبعت ١٠٠٠ طلب ويسبّute في
    // الطابور — والصفحات العامة عند أولاد الأهالي بتتأثر.
    Route::post('/groups/{group}/waitlist', [GroupController::class, 'joinWaitingList'])
        ->middleware('throttle:10,60');

// ═══════════════════════════════════════════════════════════
    // ⭐⭐⭐ الدعوات
    // ═══════════════════════════════════════════════════════════

    /**
     * ⭐⭐⭐ **قبول الدعوة — `auth:sanctum` عمداً مفيش.**
     *
     * ⭐ **السبب الوحيد**: اللي بيقبّل الدعوة **لسه مالوش حساب** —
     * هو أكتر حد محتاج يسجّل دخول في النظام. فلو حطّينا
     * `auth:sanctum`، الـendpoint يبقى مستحيل.
     *
     * ⭐ وبنفس السبب ده الراوت **مش** جوه مجموعة
     * `RouteProtectionTest::EXCEPTIONS` — هي مش جوه `auth:sanctum`
     * أصلاً، فمش محتاجة استثناء.
     *
     * ⚠️⚠️ **`throttle:5,1` إجباري.** ده سطح **عام** بيقرأ من
     * توكنات، ومحدش مقدرش يخمّن توكن (2^512)، بس **بيقدر يجرّب
     * توكنات مستعملة قبل كده**. من غير حد، ده مطارد لكل التوكنات
     * المسرّبة على الإنترنت — والمستعملة لسه.
     *
     * ⭐ `StrongPassword` (مش `uncompromised()`) — قرار PR 0.4 موثّق
     * في `InvitationController::accept()`.
     */
    Route::post('/invitations/accept', [InvitationController::class, 'accept'])
        ->middleware('throttle:5,1')
        ->name('invitations.accept');

    // ---- Authenticated ----
//
// ⭐⭐ `password.changed` على **المجموعة كلها** مش على راو راو.
//
// ⭐ السبب: لو حطيناه على راوت واحد، أي راو جديد جوه
// `auth:sanctum` بيتسرّب من غير ما حد ياخد باله — والقاعدة
// الأمنية بت dépend على إن المطور فاكر يضيفه.
//
// ⭐ الترتيب: `auth:sanctum` الأول عشان `request->user()` يبقى
// متاح للفلاتر اللي محتاجاه.
//
// ⭐ `account.active` هو اللي بيوقّف الحساب الموقوف **فوراً** —
// كان بيتقفل وقت الدخول بس، فالتوكن الصادر قبل الوقفة كان بيفضل
// شغّال (و`expiration = null` كان بيخلّيه صالح للأبد).
//
// ⭐ والاثنين بيرجّعوا `$next` لما الـuser null، فالراوتات
// العامة ما بتتأثرش.
Route::middleware(['auth:sanctum', 'account.active', 'password.changed'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    // ⭐⭐ تغيير كلمة المرور لحساب مجبور يغيّرها.
    //
    // ⭐ الراوت ده **استثناء** في `RouteProtectionTest` لازم
    // يتضاف — لأنه مالهوش `permission:` بالمعنى المعتاد: أي
    // حساب يقدر يغيّر كلمة مروره، والفلتر اللي بيحميه هو
    // `EnsurePasswordChanged` نفسه.
    Route::post('/auth/change-password', [AuthController::class, 'changePassword']);

    // ===== المجموعات — كل حاجة بعد كده محمية بـ `groups.manage` =====
    // ⚠️ `/groups/alerts` **مش** هنا — لازم يسبق `{group}` فوق.
    Route::post('/groups', [GroupController::class, 'store'])
        ->middleware('permission:groups.manage');
    Route::put('/groups/{group}', [GroupController::class, 'update'])
        ->middleware('permission:groups.manage');
    Route::delete('/groups/{group}', [GroupController::class, 'destroy'])
        ->middleware('permission:groups.manage');

    Route::get('/groups/{group}/members', [GroupController::class, 'members'])
        ->middleware('permission:groups.manage');
    Route::post('/groups/{group}/members', [GroupController::class, 'addMember'])
        ->middleware('permission:groups.manage');
    Route::delete('/groups/{group}/members/{member}', [GroupController::class, 'removeMember'])
        ->middleware('permission:groups.manage');

    // ===== النقل بين المجموعات =====
    // ⚠️ `move-options` **قبل** `{member}/move` — مش ترتيب تنسيق.
    // `{member}` بيلقط `move-options` كأنه id، والطلب بيروح
    // لـ `move` بـ `to_group_id` ناقص. نفس درس `groups/alerts`.
    Route::get('/groups/{group}/members/{member}/move-options', [GroupController::class, 'moveOptions'])
        ->middleware('permission:groups.manage');
    Route::post('/groups/{group}/members/{member}/move', [GroupController::class, 'move'])
        ->middleware('permission:groups.manage');

    Route::get('/groups/{group}/waiting', [GroupController::class, 'waitingList'])
        ->middleware('permission:groups.manage');
    Route::post('/groups/{group}/waiting/{entry}/admit', [GroupController::class, 'admit'])
        ->middleware('permission:groups.manage');
    Route::post('/groups/{group}/waiting/{entry}/decline', [GroupController::class, 'decline'])
        ->middleware('permission:groups.manage');

    // ===== قائمة الانتظار — صفحة مستقلة للأدمن =====
    Route::get('/waitlist', [WaitlistController::class, 'index'])
        ->middleware('permission:groups.manage');
    Route::post('/waitlist', [WaitlistController::class, 'store'])
        ->middleware('permission:groups.manage');
    Route::put('/waitlist/{entry}', [WaitlistController::class, 'update'])
        ->middleware('permission:groups.manage');
    Route::delete('/waitlist/{entry}', [WaitlistController::class, 'destroy'])
        ->middleware('permission:groups.manage');
    Route::post('/waitlist/{entry}/admit', [WaitlistController::class, 'admit'])
        ->middleware('permission:groups.manage');

    // Students
    Route::get('/students', [StudentController::class, 'index'])->middleware('permission:students.view');
    Route::post('/students', [StudentController::class, 'store'])->middleware('permission:students.create');
    Route::get('/students/{student}', [StudentController::class, 'show'])->middleware('permission:students.view');
    Route::put('/students/{student}', [StudentController::class, 'update'])->middleware('permission:students.update');
    Route::delete('/students/{student}', [StudentController::class, 'destroy'])->middleware('permission:students.delete');
// إجراءات جماعية — لازم تيجي قبل /students/{student} عشان ما تلتقطش كـ id
Route::post('/students/bulk/change-teacher', [StudentController::class, 'bulkChangeTeacher'])->middleware('permission:students.update');
Route::post('/students/bulk/create-invoices', [StudentController::class, 'bulkCreateInvoices'])->middleware('permission:finance.payments.create');
Route::post('/students/bulk/notify', [StudentController::class, 'bulkNotify'])->middleware('permission:students.update');
    Route::get('/students/{student}/schedule', [StudentController::class, 'schedule'])->middleware('permission:students.view');
    Route::get('/students/{student}/attendance', [StudentController::class, 'attendance'])->middleware('permission:students.view');
    Route::get('/students/{student}/progress', [StudentController::class, 'progress'])->middleware('permission:students.view');
    // ⭐⭐ PR 2.9 — كشف حساب: الدفتر الموحد (رصيد تجميعي لكل عمولة)
    Route::get('/students/{student}/statement', [StudentController::class, 'statement'])->middleware('permission:students.view');
    Route::get('/students/{student}/payments', [StudentController::class, 'payments'])->middleware('permission:finance.payments.view');

    // Teachers
    Route::get('/teachers', [TeacherController::class, 'index'])->middleware('permission:teachers.view');
    Route::post('/teachers', [TeacherController::class, 'store'])->middleware('permission:teachers.create');
    Route::get('/teachers/{teacher}', [TeacherController::class, 'show'])->middleware('permission:teachers.view');
    Route::put('/teachers/{teacher}', [TeacherController::class, 'update'])->middleware('permission:teachers.update');
    Route::delete('/teachers/{teacher}', [TeacherController::class, 'destroy'])->middleware('permission:teachers.delete');
    Route::get('/teachers/{teacher}/schedule', [TeacherController::class, 'schedule'])->middleware('permission:teachers.view');
    Route::get('/teachers/{teacher}/overview', [TeacherController::class, 'overview'])->middleware('permission:teachers.view');
    Route::get('/teachers/{teacher}/students', [TeacherController::class, 'students'])->middleware('permission:teachers.view');
    Route::get('/teachers/{teacher}/lessons-summary', [TeacherController::class, 'lessonsSummary'])->middleware('permission:teachers.view');
    Route::get('/teachers/{teacher}/financial-summary', [TeacherController::class, 'financialSummary'])->middleware('permission:finance.payments.view');
    Route::get('/teachers/{teacher}/earnings', [TeacherController::class, 'earnings'])->middleware('permission:finance.payments.view');
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
    //
    // البرنامج = كتالوج الخدمة التعليمية (ماذا نقدم، لمن، بكام).
    // ده **معلومة عامة** مش إعداد، والمعلم لازم يشوف بيحضّر إيه
    // وولي الأمر لازم يشوف المتاح. فالقراءة بـ programs.view.
    //
    // مهم: اخترنا programs.viewمش lessons.view عن قصد — لو منحنا
    // المعلم lessons.view هيفتحله حصص الأكاديمية كلها (تسريب).
    // الكتابة لإدارة النظام بس.
    Route::get('/programs', [ProgramController::class, 'index'])->middleware('permission:programs.view');
    Route::get('/programs/slug-preview', [ProgramController::class, 'slugPreview'])->middleware('permission:programs.view');
    Route::get('/programs/{program}', [ProgramController::class, 'show'])->middleware('permission:programs.view');
    Route::post('/programs', [ProgramController::class, 'store'])->middleware('permission:programs.update');
    Route::put('/programs/{program}', [ProgramController::class, 'update'])->middleware('permission:programs.update');
    Route::delete('/programs/{program}', [ProgramController::class, 'destroy'])->middleware('permission:programs.update');

    // مستويات/مراحل البرنامج
    Route::get('/programs/{program}/levels', [ProgramController::class, 'levels'])->middleware('permission:programs.view');
    Route::post('/programs/{program}/levels', [ProgramController::class, 'storeLevel'])->middleware('permission:programs.manage');
    Route::put('/programs/{program}/levels/{level}', [ProgramController::class, 'updateLevel'])->middleware('permission:programs.manage');
    Route::delete('/programs/{program}/levels/{level}', [ProgramController::class, 'destroyLevel'])->middleware('permission:programs.manage');
    Route::post('/programs/{program}/levels/reorder', [ProgramController::class, 'reorderLevels'])->middleware('permission:programs.manage');

    // المعلمون القادرون على تدريس البرنامج
    Route::get('/programs/{program}/teachers', [ProgramController::class, 'teachers'])->middleware('permission:programs.view');
    Route::post('/programs/{program}/teachers', [ProgramController::class, 'linkTeacher'])->middleware('permission:programs.manage');
    Route::delete('/programs/{program}/teachers/{teacherId}', [ProgramController::class, 'unlinkTeacher'])->middleware('permission:programs.manage');
    // إصلاح جماعي — لسه محتاج تأكيد من الواجهة
    Route::post('/programs/{program}/teachers/link-missing', [ProgramController::class, 'linkAllMissingTeachers'])->middleware('permission:programs.manage');
    Route::post('/programs/{program}/teachers/unlink-idle', [ProgramController::class, 'unlinkIdleTeachers'])->middleware('permission:programs.manage');

    // طلاب البرنامج (ملخص عن طريق الاشتراكات — مش إدارة اشتراك)
    Route::get('/programs/{program}/students', [ProgramController::class, 'students'])->middleware('permission:programs.view');

    // تصنيفات البرامج — قابلة للإدارة من النظام مش مثبتة في الكود
    Route::get('/program-categories', [ProgramController::class, 'categories'])->middleware('permission:programs.view');
    Route::post('/program-categories', [ProgramController::class, 'storeCategory'])->middleware('permission:programs.update');
    Route::put('/program-categories/{category}', [ProgramController::class, 'updateCategory'])->middleware('permission:programs.update');
    Route::delete('/program-categories/{category}', [ProgramController::class, 'destroyCategory'])->middleware('permission:programs.update');

    // ===== الدعوات — إدارة الحسابات الجديدة =====

    /**
     * ⭐⭐ **`users.invite` منفصلة عن `users.roles.assign`** عن قصد.
     *
     * ⭐ `roles.assign` بتعدّل دور **موجود**، و`invite` بتعمل **حساب
     * جديد**. عملية مختلفة وصلاحية مختلفة.
     *
     * ⭐ والقيد الحقيقي **مش** الـpermission — هو
     * `RoleGrantService::assertCanAssignRole()` اللي بيفرض إن كل
     * صلاحية في الدور اللي بتسلّمه تكون موجودة عندك.
     *
     * ⭐ `resend` و`destroy` ليهم نفس الصلاحية: إدارة دورة حياة
     * الدعوة كلها صلاحية واحدة، مش تلاتة.
     */
    Route::post('/invitations', [InvitationController::class, 'store'])
        ->middleware('permission:users.invite');
    Route::post('/invitations/{invitation}/resend', [InvitationController::class, 'resend'])
        ->middleware('permission:users.invite');
    Route::delete('/invitations/{invitation}', [InvitationController::class, 'destroy'])
        ->middleware('permission:users.invite');

    // ===== إدارة الحسابات (PR 1.5) =====

    /**
     * ⭐⭐⭐ `/users` و `/users/{user}` — العرض فقط.
     *
     * ⭐ `users.view` لوحدها. **مش** `settings.manage` — دي
     * صلاحيات مختلفة: تقدر تشوف الحسابات من غير ما تلمسها.
     */
    Route::get('/users', [UserController::class, 'index'])
        ->middleware('permission:users.view');
    Route::get('/users/{user}', [UserController::class, 'show'])
        ->middleware('permission:users.view');

    /**
     * ⭐⭐⭐ **إعادة التفعيل والأرشفة وإعادة التعيين = ٣ راوتات
     * بـ3 صلاحيات مختلفة** — مش واحدة.
     *
     * ⭐ `suspend` و `reactivate` مع بعض: **نفس العملية** بحالتين
     * متعاكستين، فلا معنى يفصلوا. وبيبطّلوا الوصول وساعتها.
     *
     * ⭐ `archive` منفصلة: **نهائية**. اللي يقدر يوقف مؤقتاً مش
     * بالضرورة اللي يقدر يؤرشف.
     *
     * ⚠️ `force-password-reset` تحت `users.suspend` **عن قصد** —
     * الاتنين عملية «إغلاق وصول» واحدة: المستخدم داخل بس ما
     * يعملش حاجة غير تغيير كلمة المرور.
     *
     * ⭐ و🚫 مافيش `DELETE /users/{user}` — البند 7: **مافيش**
     * حذف لـ`users` ولا تصفية (`PRAGMA foreign_keys` / REFERENCES).
     * الأرشفة بتسيب كل الروابط المالية سليمة؛ الحذف بيكسر
     * `audit_logs` ورأس الشجرة المالي. سيبه لـSQL اجتهاداً
     * بعد مراجعة — مش زرار.
     */
    Route::post('/users/{user}/suspend', [UserController::class, 'suspend'])
        ->middleware('permission:users.suspend');
    Route::post('/users/{user}/reactivate', [UserController::class, 'reactivate'])
        ->middleware('permission:users.suspend');
    Route::post('/users/{user}/force-password-reset', [UserController::class, 'forcePasswordReset'])
        ->middleware('permission:users.suspend');
    Route::post('/users/{user}/archive', [UserController::class, 'archive'])
        ->middleware('permission:users.archive');

    /**
     * ⭐ تغيير الدور — `users.roles.assign` (PR 0.3).
     *
     * ⭐⚠️ الراوت ده **مش** بيعدّي على `assertCanAssignRole` بس —
     * `UserController::updateRole` بيطلب القيدين: التصعيد ممنوع
     * (**RoleGrantService**) وتغيير الدور على النفس ممنوع
     * (**UserStatusService::assertNotSelf**).
     *
     * ⭐ والقاعدة الحقيقية مش الـ`permission` أصلاً — الفريق
     * بيمسك لو حد عنده صلاحية بس مش كل الصلاحيات.
     */
    Route::put('/users/{user}/role', [UserController::class, 'updateRole'])
        ->middleware('permission:users.roles.assign');

    // ===== الأدوار والصلاحيات =====
    //
    // ⭐ القراءة بـ `settings.manage`، والكتابة بـ `users.roles.assign`.
    //
    // ⭐ السبب: تعديل صلاحيات دور = تسليم صلاحيات. ده مش
    // «إعداد» — ده أرخص عملية تصعيد موجودة في النظام. لازم يكون
    // ليه تصريح صريح، مش إن حد عنده صلاحية الإعدادات بالوراثة.
    Route::get('/roles', [RoleController::class, 'index'])->middleware('permission:roles.view');
    Route::get('/permissions', [RoleController::class, 'allPermissions'])->middleware('permission:roles.view');

    Route::post('/roles', [RoleController::class, 'store'])
        ->middleware('permission:users.roles.assign');
    Route::put('/roles/{role}/permissions', [RoleController::class, 'updatePermissions'])
        ->middleware('permission:users.roles.assign');
    Route::delete('/roles/{role}', [RoleController::class, 'destroy'])
        ->middleware('permission:users.roles.assign');

    // Subscriptions
    Route::get('/subscriptions', [SubscriptionController::class, 'index'])->middleware('permission:students.view');
    Route::post('/subscriptions', [SubscriptionController::class, 'store'])->middleware('permission:students.update');
    Route::get('/subscriptions/{subscription}', [SubscriptionController::class, 'show'])->middleware('permission:students.view');
    Route::put('/subscriptions/{subscription}', [SubscriptionController::class, 'update'])->middleware('permission:students.update');
    // ⭐ PR 3.8 — إيقاف/استئناف الاشتراك (بأثر حقيقي: حصص + نهاية + سجل + إشعار).
    Route::post('/subscriptions/{subscription}/pause', [SubscriptionController::class, 'pause'])->middleware('permission:students.update');
    Route::post('/subscriptions/{subscription}/resume', [SubscriptionController::class, 'resume'])->middleware('permission:students.update');
    Route::delete('/subscriptions/{subscription}', [SubscriptionController::class, 'destroy'])->middleware('permission:students.update');

    // المعالجة اليومية — نفس اللي بيحصل بالأمر التلقائي، عشان
    // الأدمن يشغّلها بإيده لما يفضّل
    Route::post('/subscriptions/process-day', [SubscriptionController::class, 'processDay'])
        ->middleware('permission:students.update');

    // ===== واجهة ولي الأمر =====
    // البراوت يضمن إن الحساب ده ولي أمر فعلاً. وكمان الـ controller
    // بيتأكد من إن كل ابن مرتبط بيه — عشوائياً لا يكفي.
    Route::get('/parent/children', [ParentPortalController::class, 'children'])
        ->middleware('parent.only');

    // Schedule / Lessons
    Route::get('/schedule', [LessonController::class, 'schedule'])->middleware('permission:lessons.view');
    Route::post('/lessons', [LessonController::class, 'store'])->middleware('permission:lessons.create');
    Route::post('/lessons/recurring', [LessonController::class, 'storeRecurring'])->middleware('permission:lessons.create');
    Route::put('/lessons/{lesson}', [LessonController::class, 'update'])->middleware('permission:lessons.update');
    Route::post('/lessons/{lesson}/cancel', [LessonController::class, 'cancel'])->middleware('permission:lessons.cancel');
    Route::post('/lessons/{lesson}/makeup', [LessonController::class, 'makeup'])->middleware('permission:lessons.create');
    Route::post('/lessons/{lesson}/complete', [LessonController::class, 'complete'])->middleware('permission:lessons.update');
    // ⭐ PR 3.1 — القناة الوحيدة لتغيير حالة الحصة (آلة الحالات).
    // `update` ما بيغيّرش الحالة — التحويل من هنا بس، وكل تحويل
    // يتسجَّل في `lesson_status_history`.
    Route::post('/lessons/{lesson}/transition', [LessonController::class, 'transition'])->middleware('permission:lessons.update');
    // ⭐ PR 3.4 — تصحيح حالة بعد الإتمام (حركات عكسية، مش تعديل).
    Route::post('/lessons/{lesson}/correct', [LessonController::class, 'correct'])->middleware('permission:lessons.update');
    Route::post('/lessons/{lesson}/attendance', [LessonController::class, 'attendance'])->middleware('permission:lessons.update');
    Route::post('/lessons/{lesson}/memorization', [LessonController::class, 'memorization'])->middleware('permission:lessons.update');

    // Invoices
    Route::get('/invoices', [InvoiceController::class, 'index'])->middleware('permission:finance.payments.view');
    Route::post('/invoices', [InvoiceController::class, 'store'])->middleware('permission:finance.payments.create');
    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->middleware('permission:finance.payments.view');
    Route::put('/invoices/{invoice}', [InvoiceController::class, 'update'])->middleware('permission:finance.payments.update');
    Route::delete('/invoices/{invoice}', [InvoiceController::class, 'destroy'])->middleware('permission:finance.payments.update');

    // ⭐ PR 2.17 — مستند الفاتورة PDF: بلا `permission:` لأن ولي
    // الأمر يصله (الولي مالوش كتالوج صلاحيات) — الحكم في
    // `InvoicePolicy::viewPdf` (موظف مالي/طالب/ولي بـcan_view_finance
    // فقط) واستثناءه مُعلَّل في RouteProtectionTest::EXCEPTIONS.
    Route::get('/invoices/{invoice}/pdf', [InvoiceController::class, 'pdf']);

    // ⭐ PR 2.6 — الحالة بقت **أفعال صريحة** مش تعديل حقل.
    //
    // ⚠️ الاعتماد/الإلغاء **قرار له أثر** (الفاتورة بقت مطالبة على
    // الطالب)، فالـUI يعمله consciously والـaudit بيسجّله.
    // `PUT /invoices/{id}` بـ`status` بقى **422** (كان بيعتمد
    // فاتورة مدفوعة من غير ولا جنيه).
    Route::post('/invoices/{invoice}/issue', [InvoiceController::class, 'issue'])->middleware('permission:finance.payments.update');
    Route::post('/invoices/{invoice}/void', [InvoiceController::class, 'void'])->middleware('permission:finance.payments.update');

    // Payments
    Route::get('/payments', [PaymentController::class, 'index'])->middleware('permission:finance.payments.view');
    Route::post('/payments', [PaymentController::class, 'store'])->middleware('permission:finance.payments.create');
    Route::get('/payments/{payment}', [PaymentController::class, 'show'])->middleware('permission:finance.payments.view');
    // ⭐ PR 2.17 — إيصال الدفعة PDF: نفس المبدأ — عائلي (ولي/
    // طالب) بلا permission، والحكم `PaymentPolicy::viewPdf`.
    Route::get('/payments/{payment}/receipt', [PaymentController::class, 'receipt']);
    Route::post('/payments/{payment}/refund', [PaymentController::class, 'refund'])
        ->middleware('permission:finance.payments.refund');
    // ⭐⭐ PR 2.8 — عكس دفعة خاطئة (status= reversed + سطور عكسية)
    Route::post('/payments/{payment}/reverse', [PaymentController::class, 'reverse'])
        ->middleware('permission:finance.payments.refund');
    // ⭐⭐ موافقة الاسترداد — صلاحية منفصلة، وطالب الطلب مرفوض
    Route::post('/refunds/{refund}/approve', [PaymentController::class, 'approveRefund'])
        ->middleware('permission:finance.refunds.approve');
    Route::get('/refunds/pending-approval', [PaymentController::class, 'pendingApprovalRefunds'])
        ->middleware('permission:finance.refunds.approve');
    Route::get('/payments/{payment}/refunds', [PaymentController::class, 'refunds'])->middleware('permission:finance.payments.view');
    // ⭐ توزيع الدفعة على فواتير — القاعدة 5 من الخطة
    Route::post('/payments/{payment}/allocate', [PaymentController::class, 'allocate'])->middleware('permission:finance.payments.update');

    // ⭐ الرصيد الدائن
    Route::get('/student-credits', [StudentCreditController::class, 'index'])->middleware('permission:finance.payments.view');
    Route::post('/student-credits', [StudentCreditController::class, 'store'])->middleware('permission:finance.payments.create');
    Route::post('/student-credits/{studentCredit}/apply', [StudentCreditController::class, 'apply'])->middleware('permission:finance.payments.update');
    Route::post('/student-credits/{studentCredit}/refund', [StudentCreditController::class, 'refund'])
        ->middleware('permission:finance.payments.refund');

    // ⭐⭐ PR 2.9 — تنبيهات التسوية المالية: شوف بعلم قراءة، أغلق بتحديث
    Route::get('/finance-alerts', [FinanceAlertController::class, 'index'])
        ->middleware('permission:finance.payments.view');
    Route::post('/finance-alerts/{alert}/resolve', [FinanceAlertController::class, 'resolve'])
        ->middleware('permission:finance.payments.update');

    // ⭐ سياسة الحصص
    // ⭐ PR 3.2 — سياسات الحصص: القراءة (`lessons.policies.view`)
    // والتعديل قرار إدارة (`lessons.policies.manage`).
    Route::get('/lesson-policy', [LessonPolicyController::class, 'show'])
        ->middleware('permission:lessons.policies.view');
    Route::put('/lesson-policy/{policy}', [LessonPolicyController::class, 'update'])
        ->middleware('permission:lessons.policies.manage');

    // ⭐ تصدير CSV (Excel-compatible)
    Route::get('/exports/account-movements', [ExportController::class, 'accountMovements'])->middleware('permission:finance.accounts.view');
    Route::get('/exports/account-balances', [ExportController::class, 'accountBalances'])->middleware('permission:finance.accounts.view');
    Route::get('/exports/invoices', [ExportController::class, 'invoices'])->middleware('permission:finance.payments.view');
    Route::get('/exports/expenses', [ExportController::class, 'expenses'])->middleware('permission:finance.payments.view');

    // CRM — Leads
    Route::get('/leads', [LeadController::class, 'index'])->middleware('permission:leads.view');
    Route::post('/leads', [LeadController::class, 'store'])->middleware('permission:leads.create');
    Route::get('/leads/{lead}', [LeadController::class, 'show'])->middleware('permission:leads.view');
    Route::put('/leads/{lead}', [LeadController::class, 'update'])->middleware('permission:leads.update');
    Route::put('/leads/{lead}/status', [LeadController::class, 'updateStatus'])->middleware('permission:leads.update');
    Route::delete('/leads/{lead}', [LeadController::class, 'destroy'])->middleware('permission:leads.update');

    // CRM — Assessments
    Route::get('/assessments', [AssessmentController::class, 'index'])->middleware('permission:leads.view');
    Route::post('/assessments', [AssessmentController::class, 'store'])->middleware('permission:leads.update');
    Route::put('/assessments/{assessment}/result', [AssessmentController::class, 'updateResult'])->middleware('permission:leads.update');
    Route::post('/assessments/{assessment}/convert', [AssessmentController::class, 'convert'])->middleware('permission:students.create');

    // Notifications
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markAsRead']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);

    // Audit Logs
    Route::get('/audit-logs', [AuditLogController::class, 'index'])->middleware('permission:audit.view');

    // Dashboard & Reports
    Route::get('/dashboard/summary', [ReportController::class, 'dashboardSummary'])->middleware('permission:reports.view');
    Route::get('/reports/financial', [ReportController::class, 'financial'])->middleware('permission:reports.view');

    // ⭐⭐ PR 2.14 — «كام ضاع في الخصومات» (ترويجي vs يدوي)
    Route::get('/reports/discounts', [ReportController::class, 'discounts'])->middleware('permission:reports.view');
    Route::get('/reports/academic', [ReportController::class, 'academic'])->middleware('permission:reports.view');
    Route::get('/reports/sales', [ReportController::class, 'sales'])->middleware('permission:reports.view');

    // ⭐⭐ PR 2.16 — المتابعة اليومية: أعمار الديون + الأرصدة +
    // التزامات المعلمين + مصادر الإيراد
    Route::get('/reports/aging', [ReportController::class, 'aging'])->middleware('permission:reports.view');
    Route::get('/reports/account-balances', [ReportController::class, 'accountBalances'])->middleware('permission:reports.view');
    Route::get('/reports/teacher-liabilities', [ReportController::class, 'teacherLiabilities'])->middleware('permission:reports.view');
    Route::get('/reports/revenue-sources', [ReportController::class, 'revenueSources'])->middleware('permission:reports.view');

    // ⭐ التصدير — فيه قراءة بيانات صريحة فبصلاحية إضافية
    Route::get('/reports/export', [ReportController::class, 'export'])->middleware([
        'permission:reports.view', 'permission:reports.export',
    ]);

    // Settings
    Route::get('/settings', [SettingController::class, 'index'])->middleware('permission:settings.view');
    Route::post('/settings', [SettingController::class, 'store'])->middleware('permission:settings.update');
    Route::delete('/settings/{key}', [SettingController::class, 'destroy'])->middleware('permission:settings.update');

    // Employees
    Route::get('/employees', [EmployeeController::class, 'index'])->middleware('permission:students.view');
    Route::post('/employees', [EmployeeController::class, 'store'])->middleware('permission:students.update');
    Route::get('/employees/{employee}', [EmployeeController::class, 'show'])->middleware('permission:students.view');
    Route::put('/employees/{employee}', [EmployeeController::class, 'update'])->middleware('permission:students.update');
    Route::delete('/employees/{employee}', [EmployeeController::class, 'destroy'])->middleware('permission:students.update');
    Route::get('/employees/{employee}/activity', [EmployeeController::class, 'activity'])->middleware('permission:students.view');

    // ===== الحضور والانصراف — كله على الأدمن =====
    // تسجيل الحضور — موظف الاستقبال والمشرف بيسجّلوا، الأدمن يشوف الكل.
    // كان على students.* وده غلط: حضور الموظفين مش part من الطلاب.
    Route::get('/attendance/day', [AttendanceController::class, 'day'])->middleware('permission:attendance.view');
    Route::post('/attendance', [AttendanceController::class, 'store'])->middleware('permission:attendance.update');
    Route::get('/employees/{employee}/attendance-month', [AttendanceController::class, 'month'])->middleware('permission:attendance.view');

    // ===== مرتبات الموظفين بالساعات =====
    // الاحتساب بيقرأ الحضور المسجّل، والـ `payroll_periods` جدول
    // مشترك مع مرتبات المعلمين — الفترة الواحدة فيها الاتنين.
    Route::get('/payroll/periods', [EmployeePayrollController::class, 'periods'])->middleware('permission:payroll.periods.manage');
    Route::post('/payroll/periods', [EmployeePayrollController::class, 'createPeriod'])->middleware('permission:payroll.periods.manage');
    Route::get('/payroll/periods/{period}/lines', [EmployeePayrollController::class, 'lines'])->middleware('permission:payroll.periods.manage');
    Route::get('/payroll/periods/{period}/report', [EmployeePayrollController::class, 'report'])->middleware('permission:payroll.periods.manage');
    Route::post('/payroll/periods/{period}/generate', [EmployeePayrollController::class, 'generate'])->middleware('permission:payroll.periods.manage');
    Route::post('/payroll/periods/{period}/approve', [EmployeePayrollController::class, 'approve'])->middleware('permission:payroll.periods.manage');
    // إقفال / إعادة فتح الفترة. الإقفال بيقفل الأرقام بس —
    // المدفوعات بتكمّل بعده (قرار «مرحلتين»).
    Route::post('/payroll/periods/{period}/close', [EmployeePayrollController::class, 'close'])->middleware('permission:payroll.periods.manage');
    Route::post('/payroll/periods/{period}/reopen', [EmployeePayrollController::class, 'reopen'])->middleware('permission:payroll.periods.manage');
    Route::put('/payroll/lines/{line}', [EmployeePayrollController::class, 'updateLine'])->middleware('permission:payroll.periods.manage');
    Route::post('/payroll/lines/{line}/pay', [EmployeePayrollController::class, 'pay'])->middleware('permission:payroll.payments.create');

    // ===== مرتبات المعلمين =====
    // نفس الدورة بالظبط، والفرق في الإحساس: المعلمين بالحصة أو
    // راتب شهري، مش بالساعة. الجدول منفصل (`teacher_payroll_lines`)
    // لأن `teacher_earnings` هو المستحق مش السطر القابل للدفع.
    Route::get('/teacher-payroll/periods', [TeacherPayrollController::class, 'periods'])->middleware('permission:payroll.periods.manage');
    Route::get('/teacher-payroll/periods/{period}/lines', [TeacherPayrollController::class, 'lines'])->middleware('permission:payroll.periods.manage');
    Route::get('/teacher-payroll/periods/{period}/report', [TeacherPayrollController::class, 'report'])->middleware('permission:payroll.periods.manage');
    Route::post('/teacher-payroll/periods/{period}/generate', [TeacherPayrollController::class, 'generate'])->middleware('permission:payroll.periods.manage');
    Route::post('/teacher-payroll/periods/{period}/approve', [TeacherPayrollController::class, 'approve'])->middleware('permission:payroll.periods.manage');
    Route::post('/teacher-payroll/periods/{period}/close', [TeacherPayrollController::class, 'close'])->middleware('permission:payroll.periods.manage');
    Route::post('/teacher-payroll/periods/{period}/reopen', [TeacherPayrollController::class, 'reopen'])->middleware('permission:payroll.periods.manage');
    Route::put('/teacher-payroll/lines/{line}', [TeacherPayrollController::class, 'updateLine'])->middleware('permission:payroll.periods.manage');
    Route::post('/teacher-payroll/lines/{line}/pay', [TeacherPayrollController::class, 'pay'])->middleware('permission:payroll.payments.create');

    // ⭐⭐ PR 2.11 — كشف حساب المعلم والتسويات: كل مسار صلاحية
    // منفصلة (مكافأة/خصم/سلفة = قرارات مالية مختلفة)
    Route::get('/teachers/{teacher}/statement', [TeacherLedgerController::class, 'statement'])
        ->middleware('permission:payroll.view');
    Route::post('/teachers/{teacher}/bonus', [TeacherLedgerController::class, 'bonus'])
        ->middleware('permission:payroll.bonuses.create');
    Route::post('/teachers/{teacher}/deduction', [TeacherLedgerController::class, 'deduction'])
        ->middleware('permission:payroll.deductions.create');
    Route::post('/teachers/{teacher}/advance', [TeacherLedgerController::class, 'advance'])
        ->middleware('permission:payroll.advances.create');

    // ⭐⭐ PR 2.10 — اعتماد مستحقات المعلمين (للمعلَق فقط؛
    // الاعتماد بيكتب دفتر المعلم ويرفع المستحق لكشف المرتبات)
    Route::post('/teacher-earnings/bulk-approve', [TeacherEarningController::class, 'bulkApprove'])
        ->middleware('permission:payroll.earnings.approve');
    Route::post('/teacher-earnings/{earning}/approve', [TeacherEarningController::class, 'approve'])
        ->middleware('permission:payroll.earnings.approve');

    // ⭐ الخزائن والحسابات + دفتر الحركات
    //
    // ⚠️ ترتيب مهم: `/accounts/movements` و `/accounts/transfer`
    // **قبل** `/accounts/{account}` — لو العكس، Laravel هياخد
    // «movements» كـ id وهيرمي 404.
    Route::get('/accounts', [FinancialAccountController::class, 'index'])->middleware('permission:finance.accounts.view');
    Route::post('/accounts', [FinancialAccountController::class, 'store'])->middleware('permission:finance.accounts.manage');
    Route::get('/accounts/movements', [FinancialAccountController::class, 'movements'])->middleware('permission:finance.accounts.view');

    // ⭐ التحويل بياخد `finance.movements` — **مش** صلاحية جديدة.
    //
    // السبب: التحويل بين خزائن الأكاديمية مش «فلوس دخلت أو
    // خرجت» — هو حركتين داخل الدفتر نفسه. فاللي يقدر يسجّل
    // حركة يقدر يسجّل التحويل، والحساب (accountant) يقدر
    // ينقل من الخزينة للبنك من غير ما نعمل صلاحية زيادة.
    Route::post('/accounts/transfer', [FinancialAccountController::class, 'transfer'])->middleware('permission:finance.movements.create');

    // ⭐⭐ العكس — **المدير بس**، زي فتح الفترات.
    // الحساب (accountant) **مالوش** `finance.reverse`.
    Route::post('/movements/{movement}/reverse', [FinancialAccountController::class, 'reverse'])
        ->middleware('permission:finance.movements.reverse');

    Route::get('/period-locks', [FinancialAccountController::class, 'locks'])->middleware('permission:finance.accounts.view');
    Route::post('/period-locks', [FinancialAccountController::class, 'lockPeriod'])->middleware('permission:finance.periods.lock');
    Route::post('/period-locks/{lock}/unlock', [FinancialAccountController::class, 'unlockPeriod'])
        ->middleware('permission:finance.periods.unlock');

    Route::get('/accounts/{account}', [FinancialAccountController::class, 'show'])->middleware('permission:finance.accounts.view');
    Route::put('/accounts/{account}', [FinancialAccountController::class, 'update'])->middleware('permission:finance.accounts.manage');

    // ===== المصروفات =====
    // ⚠️ دي كانت تحت auth:sanctum بس — أي حد داخل يقدر يشوف
    // مصروفات ويسجّل مصروف. `RouteProtectionTest` بيحرس السطور دي.
    Route::get('/expenses', [ExpenseController::class, 'index'])
        ->middleware('permission:finance.expenses.view');
    Route::post('/expenses', [ExpenseController::class, 'store'])
        ->middleware('permission:finance.expenses.create');

    // ⭐⭐ PR 2.12 — دورة المصروف: اعتماد / رفض / صرف.
    // الاعتماد والصرف صلاحيتان منفصلتان — ومنشئ المصروف ما يقدرش
    // يعتمده بنفسه (الحارس جوّه `ExpenseService`).
    Route::post('/expenses/{expense}/approve', [ExpenseController::class, 'approve'])
        ->middleware('permission:finance.expenses.approve');
    Route::post('/expenses/{expense}/reject', [ExpenseController::class, 'reject'])
        ->middleware('permission:finance.expenses.approve');
    Route::post('/expenses/{expense}/pay', [ExpenseController::class, 'pay'])
        ->middleware('permission:finance.expenses.pay');

    // المرفقات — رفع محدود النوع والحجم، والتنزيل برابط موقّع
    // مؤقت (التوقيع هو الإذن — المسار ده تحت في القسم العام).
    Route::post('/expenses/{expense}/attachments', [ExpenseController::class, 'uploadAttachment'])
        ->middleware('permission:finance.expenses.create');

    // ===== فئات المصروفات =====
    // ⭐ القراءة محتاجة معرفة بالفئات (زي choose الفئة وقت الإنشاء)
    // — والإدارة الأربعة محرجة من الإدخال: فئة مربوطة بمصروفات
    // ما بتتحذفش (409).
    Route::get('/expense-categories', [ExpenseCategoryController::class, 'index'])
        ->middleware('permission:finance.expenses.view');
    Route::post('/expense-categories', [ExpenseCategoryController::class, 'store'])
        ->middleware('permission:finance.expenses.create');
    Route::put('/expense-categories/{category}', [ExpenseCategoryController::class, 'update'])
        ->middleware('permission:finance.expenses.create');
    Route::delete('/expense-categories/{category}', [ExpenseCategoryController::class, 'destroy'])
        ->middleware('permission:finance.expenses.create');

    // Teacher Earnings & Payments
    // ⭐ `payroll.view` للقراءة بس. الصرف والاعتماد محتاجين
    // `payroll.manage` — دي منفصلة عن قصد.
    Route::get('/teacher-earnings', [TeacherEarningController::class, 'index'])
        ->middleware('permission:payroll.view');
    Route::get('/teacher-payments', [TeacherPaymentController::class, 'index'])
        ->middleware('permission:payroll.view');

    // ===== أولياء الأمور =====
    // ⚠️ `POST /parents` = إنشاء **صلاحية وصول** لحد يشوف بيانات
    // الأبناء — فمفصولة عن القراءة.
    Route::get('/parents', [ParentController::class, 'index'])
        ->middleware('permission:parents.view');
    Route::post('/parents', [ParentController::class, 'store'])
        ->middleware('permission:parents.create');

    // ===== هواتف الطلاب والربط بالأبناء =====
    // ⚠️ `/phones/search` بتجيب **أهل** الطالب من رقمه — فدي حساسية
    // بيانات ولي الأمر مش الطالب بس.
    Route::get('/students/{student}/phones', [StudentPhoneController::class, 'index'])->middleware('permission:students.phones.manage');
    Route::post('/students/{student}/phones', [StudentPhoneController::class, 'store'])->middleware('permission:students.phones.manage');
    Route::post('/students/{student}/phones/bulk', [StudentPhoneController::class, 'bulkStore'])->middleware('permission:students.phones.manage');
    Route::put('/students/{student}/phones/{phone}', [StudentPhoneController::class, 'update'])->middleware('permission:students.phones.manage');
    Route::delete('/students/{student}/phones/{phone}', [StudentPhoneController::class, 'destroy'])->middleware('permission:students.phones.manage');
    Route::post('/students/{student}/phones/{phone}/set-primary', [StudentPhoneController::class, 'setPrimary'])->middleware('permission:students.phones.manage');
    Route::post('/students/{student}/phones/{phone}/toggle', [StudentPhoneController::class, 'toggleField'])->middleware('permission:students.phones.manage');
    Route::get('/parents/search', [StudentPhoneController::class, 'searchParents'])->middleware('permission:parents.view');
    Route::get('/students/{student}/parents', [StudentPhoneController::class, 'listParents'])->middleware('permission:parents.view');
    Route::post('/students/{student}/parents/{parent}/set-primary', [StudentPhoneController::class, 'setPrimaryParent'])->middleware('permission:parents.create');

    // ⭐ PR 1.11 — تأكيد/رفض ربط معلّق (رقم مكرر). التحويل ده قرار:
    // الموظف هو اللي بيقارن اسم ولي الأمر المسجّل بالاسم اللي حد أرسله.
    Route::post('/students/{student}/parents/{parent}/verify', [StudentPhoneController::class, 'verifyParent'])->middleware('permission:parents.create');
    Route::delete('/students/{student}/parents/{parent}', [StudentPhoneController::class, 'rejectParent'])->middleware('permission:parents.create');
    Route::get('/phones/search', [StudentPhoneController::class, 'search'])->middleware('permission:students.phones.manage');
    Route::get('/phones/search/parent', [StudentPhoneController::class, 'searchByParentPhone'])->middleware('permission:students.phones.manage');

    // ⭐ PR 1.12 — موافقة ولي الأمر (تسجيل / سحب)
    Route::post('/students/{student}/consents', [StudentConsentController::class, 'grant'])->middleware('permission:students.update');
    Route::post('/students/{student}/consents/{consent}/revoke', [StudentConsentController::class, 'revoke'])->middleware('permission:students.update');

    // ⭐ PR 1.12 — رموز دخول الحصة (طلاب تحت 13 بدون حساب)
    Route::post('/lessons/{lesson}/join-tokens', [LessonJoinTokenController::class, 'issue'])->middleware('permission:lessons.update');
});

// ⭐ PR 1.12 — استعمال رمز دخول الحصة (عام — الطالب تحت 13 مالوش حساب)
Route::post('/lessons/join', [LessonJoinTokenController::class, 'redeem'])
    ->middleware('throttle:10,1');
