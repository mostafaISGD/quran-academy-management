<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        \App\Console\Commands\ProcessSubscriptionDay::class,
        // ⭐⭐ PR 2.9 — التسوية المالية اليومية
        \App\Console\Commands\FinanceReconcileCommand::class,
    ])
    ->withSchedule(function (Schedule $schedule) {
        // المعالجة اليومية للاشتراكات: تجديد + إشعار قبل الانتهاء +
        // إقفال المنتهي.
        //
        // ٠١:١٥ بعد منتصف الليل بالوقيت المحلي — بعد ما اليوم يخلص
        // وقبل ما أحد يفتح لوحة التحكم الصبح.
        //
        // تشغيلها مرتين في نفس اليوم آمن: الفاتورة بتتحمل برقم اليوم
        // فمفيش تجديد مزدوج، وكل إشعار ليه مفتاح في الـ payload.
        $schedule->command('subscriptions:process-day')
            ->dailyAt('01:15')
            ->withoutOverlapping()
            ->runInBackground();

        // ⭐⭐ PR 2.9 — التسوية المالية (03:30): بعد الاشتراكات.
        // انحراف واحد مكتشوف = تنبيه مفتوح في `finance_alerts`
        // للإدارة. التنبيه المفتوح ما بيتكررش كل يوم.
        $schedule->command('finance:reconcile')
            ->dailyAt('03:30')
            ->withoutOverlapping();

        // ⭐⭐ PR 2.16 — تنبيهات الفواتير المتأخرة (09:00 صباحاً).
        //
        // الأمر `invoices:notify-overdue` موجود من PR أقدم بس
        // **ما كانش مسجّل في الجدولة** — الكومنت كان بيقول «يشتغل
        // يومياً» من غير تسجيل. والمواصفة هنا بتطلب أمر للتنبيهات
        // (بدون تخزين حالة الفاتورة — التنبيه فقط). ومهمته:
        // 3/7/14 يوم بعد الاستحقاق، وكل فترة مرة واحدة للفاتورة.
        $schedule->command('invoices:notify-overdue')
            ->dailyAt('09:00')
            ->withoutOverlapping();
    })
    ->withMiddleware(function (Middleware $middleware) {
        // ⭐⭐⭐ **مافيش `login` route — الـAPI بيرجّع 401 مش redirect**
        //
        // ⚠️ Laravel 11 بيجيب `redirectGuestsTo(fn () => route('login'))`
        // كـdefault. والـAPI ده **مافيش فيه راوت اسمه `login`** —
        // صفحة الدخول في الواجهة (`localhost:3000/login`).
        //
        // ⭐ فأي طلب **مش JSON** على راوت محمي بيرمي
        // `RouteNotFoundException: Route [login] not defined` → **500**
        // بـstack trace، بدل 401 نضيف.
        //
        // ⭐ مين بيمسّه؟ مش `lib/api.ts` (بيبعت `Accept:
        // application/json` دايمًا)، لكن:
        //   • متصفح بيفتح `/api/...` في الـaddress bar
        //   • monitoring probe بيعمل GET بدون هيدر
        //   • `curl` من غير `-H 'Accept: application/json'`
        //
        // ⭐ `null` معناه «مافيش redirect» — وده بالظبط الصح
        // لتطبيق API. `Authenticate` بيرجّع 401 JSON.
        $middleware->redirectGuestsTo(fn () => null);

        // ⭐⭐⭐ **الجلسات بدل التوكن** — تفعيل `statefulApi`.
        //
        // ⚠️⚠️ ليه السطر ده مهم لدرجة إنه كان **ناقصاً**:
        //
        // `routes/api.php` جوه مجموعة **`api`**: `[throttle:api,
        // SubstituteBindings]`. مافيش `StartSession`، مافيش
        // `EncryptCookies`، مافيش `ValidateCsrfToken`.
        //
        // فلو بعنا كوكي جلسة من الواجهة، الـstack **مش هيتعامل
        // معاه خالص** — `$request->user()` بيبقى null وكل
        // الطلبات بترجع 401. يعني الـcookie mechanism كله كان
        // مكتوب ومش شغّال.
        //
        // ⭐ `statefulApi()` بتعمل prepend لـ`EnsureFrontendRequestsAreStateful`
        // في الـglobal stack. والـmiddleware دي **بتحقن** جوه
        // الـpipeline **بس** لما الطلب ييجي من دومين SPA
        // (`config('sanctum.stateful')`):
        //
        //     EncryptCookies · AddQueuedCookiesToResponse
        //     StartSession · ValidateCsrfToken
        //
        // ⭐ النتيجة: وضعين في نفس الوقت — موبايل بالتوكن
        // (من غير Referer) و SPA بالكوكي (من غير توكن).
        //
        // ⭐ `SANCTUM_STATEFUL_DOMAINS` بتتحمل من الـenv في
        // `config/sanctum.php` — مش مثبّتة هنا.
        //
        // @see \Tests\Feature\SessionAuthenticationTest
        $middleware->statefulApi();

        // ⭐⭐ هيدرز الأمان على **كل** رد — مش بس الـAPI.
        //
        // ⭐ global مش per-route: لو حطيناها على راو معيّن،
        // أول ما نسي راو، نسي حماية. الـglobal بيضمن بالبناء.
        //
        // @see \App\Http\Middleware\SecurityHeaders
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);

        // ⭐⭐ الحساب المجبور يغيّر كلمة المرور بيتقفل على الراوتات
        // **المحمية**.
        //
        // ⚠️⚠️ ليه lias مش ppendToGroup؟
        //
        // ppendToGroup('api', …) بيشغّل الفلتر على **كل** /api —
        // بما فيها العامة زي /pricing و /groups. دي مالهاش
        // uth:sanctum فـ request->user() بيبقى null والفلتر
        // بيرجّع 403/401 لكل حد. (اتجرّب و كسر ExportTest.)
        //
        // ⭐ فالحماية على الراوتات المحمية بس — والتطبيق الفاعلي
        // لازم يبقى **مجموعة auth:sanctum كاملة** مش راو راو،
        // عشان أي راو جديد ما يتسرّبش (شوف routes/api.php).
        $middleware->alias([
            'permission' => \App\Http\Middleware\CheckPermission::class,
            'parse.json' => \App\Http\Middleware\ParseJsonRequest::class,
            // ⭐ الحساب الموقوف يتقفل فوراً — مش وقت الدخول بس
            'account.active' => \App\Http\Middleware\EnsureAccountActive::class,
            // ⭐ الحساب المجبور يغيّر كلمة المرور
            'password.changed' => \App\Http\Middleware\EnsurePasswordChanged::class,
            // صفحات أولياء الأمر — الحساب لازم يكون ولي أمر
            'parent.only' => \App\Http\Middleware\EnsureUserIsParent::class,
        ]);
        
        // Add CORS middleware
        $middleware->prepend(\Illuminate\Http\Middleware\HandleCors::class);
        
        // Add JSON parsing middleware to API routes
        $middleware->prependToGroup('api', \App\Http\Middleware\ParseJsonRequest::class);

        // ⭐⭐⭐ **وضع المستخدم الواحد: دخول تلقائي للأدمن.**
        //
        // ⭐ لازم **قبل** `auth:sanctum` — هو اللي بيعمل المستخدم
        // اللي `auth:sanctum` هيلاقيه جوه الطلب.
        //
        // ⭐ والراوتات العامة (تسجيل الدخول، الأسعار) بتفضل زي ما
        // هي: الوسيط بيسجّل اللي بعده بس، و`auth:sanctum` لوحده
        // بيفضل زي ما هو — فمصفوفة الصلاحيات ماتحوّلتش خالص.
        //
        // @see \App\Http\Middleware\AutoLoginAdmin
        $middleware->prependToGroup('api', \App\Http\Middleware\AutoLoginAdmin::class);
        
        // ⭐ ترجمة رسائل الـ 422 للعربي.
        //
        // لازم `append` مش `prepend`: الـ validation بيحصل في الـ
        // controller، فلو الوسطية دي شغّلت قبله مش هتلاقي
        // `errors` أصلاً. `appendToGroup` بيشتغل بعد كل حاجة.
        //
        // الـ API كله عربي، فمفيش endpoint محتاج يستثنى — لو في
        // endpoint إنجليزي فعلاً، نضيف شرط هنا.
        $middleware->appendToGroup('api', \App\Http\Middleware\TranslateValidationErrors::class);

        // ⭐⭐⭐ **الصلاحية قبل تحميل الموديل** — مش بعده.
        //
        // ⚠️⚠️ الفخ اسمه `$middlewarePriority`:
        //
        // Laravel **مش** بيشغّل الراوت بالترتيب المكتوب في
        // `->middleware()`. بيعمل sort بـ `$middlewarePriority`:
        //
        //     AuthenticatesRequests
        //     ThrottleRequests
        //     SubstituteBindings   ← هنا
        //     Authorize
        //
        // فـ`SubstituteBindings` بيشتغل **قبل** `Authorize` بالافتراضي.
        // يعني على `GET /students/{student}/payments`:
        //
        //     ١. Laravel يعمل `SELECT * FROM students WHERE id = ?`
        //     ٢. لو مش موجود → **404 فوراً**
        //     ٣. لو موجود → `CheckPermission` تشتغل → 403
        //
        // ⭐⭐ النتيجة: **تسريب وجود**. حد مالوش صلاحية يقدر
        // يخمّن أي أرقام موجودة في الداتابيز: 404 معناها «مش
        // موجود»، و403 معناها «موجود بس ممنوع». ده enumerating
        // للداتابيز كلها من غير أي صلاحية.
        //
        // ⭐ ولسبب تاني: الـ404 بيحصل وهو بيحطّ **قاعدة
        // داتابيز على كل طلب مرفوض** — استعلام ضائع من غير لازمة.
        //
        // ⭐ الحل: نحطّ `CheckPermission` قبل `SubstituteBindings`
        // في الـpriority. بعد التعديل، المستخدم من غير صلاحية
        // بياخد **403 دايماً** — موجود أو مش موجود — فمفيش
        // تسريب. والمستخدم **اللي عنده** الصلاحية لسه بياخد 404
        // صح لما الـid مش موجود.
        //
        // ⚠️ لازم `AuthenticatesRequests` يفضل **فوق** —
        // `CheckPermission` بيحتاج `$request->user()`، ولو رتب
        // فوقه الـuser هيبقى null وهرجّع 403 غلط للمستخدم الصح.
        // (وده بالظبط اللي بيحصل لو استخدمنا `priority()` بدل
        // `prependToPriorityList`. راجع `RouteProtectionTest`.)
        //
        // @see \Tests\Feature\RoleIsolationTest
        $middleware->prependToPriorityList(
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \App\Http\Middleware\CheckPermission::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // ⭐⭐⭐ رد الأخطاء الموحّد — مافيش تسريب داخلي.
        //
        // ⭐ الـblock ده كان **فاضي**، فأي exception كان بيرجّع
        // تفاصيله الكاملة: اسم class، أسماء جداول، مسار ملف،
        // رقم سطر. ده بيدّي مهاجم خريطة الداتابيز مجاناً.
        //
        // ⭐ كمان: كان فيه 19 `return $e->getMessage()` مكتوبة
        // جوّه الـcontrollers — دي اللي بتفضّح. `ErrorResponder`
        // بيمنع **أي** exception جديد يفضح، والـcontrollers
        // بتتوصّل للـhandler الموحّد بدل try/catch.
        $exceptions->render(function (Throwable $e, $request) {
            return \App\Support\ErrorResponder::respond($e, $request);
        });
    })->create();
