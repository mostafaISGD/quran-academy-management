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
    })
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'permission' => \App\Http\Middleware\CheckPermission::class,
            'parse.json' => \App\Http\Middleware\ParseJsonRequest::class,
            // صفحات أولياء الأمر — الحساب لازم يكون ولي أمر
            'parent.only' => \App\Http\Middleware\EnsureUserIsParent::class,
        ]);
        
        // Add CORS middleware
        $middleware->prepend(\Illuminate\Http\Middleware\HandleCors::class);
        
        // Add JSON parsing middleware to API routes
        $middleware->prependToGroup('api', \App\Http\Middleware\ParseJsonRequest::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();