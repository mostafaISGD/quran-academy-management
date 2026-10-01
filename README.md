# Quran Academy Management System

مشروع كامل: Backend (Laravel API) + Frontend (Next.js). هذا التسليم يغطي **Sprint 1** فقط:
Auth, Users/Roles/Permissions, Students, Teachers, Teacher Availability, Programs, Parents.

## البنية
```
backend/    Laravel 11 API (composer/artisan لازم تتشغل عندك — راجع backend/README.md)
frontend/   Next.js app (تم بناؤه واختباره فعليًا هنا — راجع frontend/README.md)
```

## الحالة الفعلية لكل جزء
- **Frontend:** الكود اتكتب واتعمله `npm run build` بنجاح فعليًا في بيئة التطوير — جاهز للتشغيل مباشرة عندك بـ `npm install && npm run dev`.
- **Backend:** الكود مكتوب متوافق تمامًا مع Laravel 11 + spatie/laravel-permission، لكن لم يُشغَّل فعليًا هنا لأن بيئة التطوير معزولة عن Packagist (مصدر مكتبات PHP). أول خطوة عندك: اتبع `backend/README.md` بالتفصيل وشغّل composer install + migrate، وأبلغني لو ظهر أي خطأ عشان نصلحه فورًا.

## الخطوة الجاية
بعد ما تتأكد إن الجزئين شغالين عندك، نكمل **Sprint 2**: Subscription Plans, Subscriptions, Lessons Engine, Schedule/Calendar UI مع Drag & Drop, Meeting Links.
