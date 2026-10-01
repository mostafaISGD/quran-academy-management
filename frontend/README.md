# Quran Academy — Frontend (Sprint 1)

Next.js 16 (App Router) + TypeScript + Tailwind. تم بناؤه واختباره فعليًا (`npm run build` ناجح).

## الصفحات المتوفرة الآن
- `/login` — تسجيل دخول (يستخدم `/api/auth/login`)
- `/dashboard` — هيكل لوحة التحكم (الأرقام placeholder لحد ما تتوصل بالـ API)
- `/students` — قائمة الطلاب (تُجلب من `/api/students`)
- `/teachers` — قائمة المعلمين (تُجلب من `/api/teachers`)

## التشغيل

```bash
npm install
cp .env.local.example .env.local   # واضبط رابط الـ API لو مختلف
npm run dev
```

يفتح على `http://localhost:3000`.

## ملاحظات
- التوكن يُخزَّن في `localStorage` مؤقتًا لغرض الـ Sprint 1 فقط. في Sprint لاحق يُفضَّل التحويل لـ httpOnly cookie عبر Sanctum SPA authentication لتقليل مخاطر XSS.
- الخطوط: تم استخدام خطوط النظام الافتراضية (مش Google Fonts) لتفادي الاعتماد على استدعاء خارجي وقت الـ Build — تقدر ترجّع Geist أو أي خط تاني بمجرد ما يبقى عندك اتصال إنترنت طبيعي في بيئة الإنتاج.
- الجدول التفاعلي (Drag & Drop للحصص) هيتضاف في Sprint 2 مع مكتبة `react-big-calendar` أو `FullCalendar`.
