# Quran Academy — Backend (Sprint 1)

يغطي هذا الجزء: Auth, Users/Roles/Permissions, Students, Teachers, Teacher Availability, Programs, Parents.

## المتطلبات
- PHP >= 8.2
- Composer
- PostgreSQL
- Redis (اختياري في هذه المرحلة، مطلوب لاحقًا للـ Queues)

## خطوات التشغيل (على جهازك، ليس هنا)

```bash
# 1) أنشئ مشروع Laravel جديد فارغ ثم انسخ عليه الملفات دي
composer create-project laravel/laravel:^11.0 quran-academy-api
cd quran-academy-api

# 2) ثبّت الحزم الإضافية
composer require laravel/sanctum spatie/laravel-permission

# 3) انسخ ملفات هذا المشروع (app/, database/, routes/api.php) فوق الملفات المقابلة
#    وانسخ .env.example إلى .env ثم اضبط بيانات القاعدة

cp .env.example .env
php artisan key:generate

# 4) انشر ملفات migrations الخاصة بـ spatie/laravel-permission
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"

# 5) شغّل الهجرات ثم الـ Seeder
php artisan migrate
php artisan db:seed --class=Database\\Seeders\\RolePermissionSeeder

# 6) سجّل middleware alias في bootstrap/app.php (Laravel 11):
#    ->withMiddleware(function (Middleware $middleware) {
#        $middleware->alias(['permission' => \App\Http\Middleware\CheckPermission::class]);
#    })

# 7) شغّل السيرفر
php artisan serve
```

## هيكل الملفات المُسلَّمة

```
app/
  Models/              User, Teacher, TeacherAvailability, ParentProfile, Student, Program
  Http/
    Controllers/Api/   AuthController, StudentController, TeacherController, ProgramController, RoleController
    Middleware/        CheckPermission
database/
  migrations/          7 جداول Sprint 1
  seeders/             RolePermissionSeeder (يطابق مصفوفة الصلاحيات من الـ Blueprint)
routes/
  api.php
```

## ملاحظات مهمة

1. **`ParentProfile` وليس `Parent`**: كلمة `Parent` محجوزة في PHP فاستخدمنا `ParentProfile` كاسم Model بينما اسم الجدول لا يزال `parents`.
2. **`lead_id` في جدول `students`**: عمود بدون Foreign Key فعلي الآن لأن جدول `leads` هيتضاف في Sprint 5 — الربط سيُضاف بـ migration منفصلة وقتها (`add_lead_foreign_key_to_students_table`).
3. **الصلاحيات**: كل صلاحية (`students.create`, `payments.refund`, ...) موجودة في `RolePermissionSeeder` ومطابقة تمامًا لمصفوفة الـ Blueprint. أي صلاحية جديدة تُضاف هناك فقط، مفيش صلاحيات Hardcoded في الكود.
4. **لم يتم تشغيل composer/artisan فعليًا في بيئة الإنشاء** بسبب قيود شبكة الـ sandbox (لا يوجد وصول لـ Packagist)، لكن الكود اتكتب ليطابق syntax وconventions لارافيل 11 + spatie/laravel-permission بدقة. أول حاجة تعملها بعد النسخ: `composer install` ثم `php artisan migrate` وتتأكد إن كله شغال.

## الخطوة الجاية (Sprint 2)
Subscription Plans, Subscriptions, Schedule/Lessons Engine, Meeting Links — هبنيها بنفس الأسلوب فور ما تأكد إن Sprint 1 شغال عندك صح.
