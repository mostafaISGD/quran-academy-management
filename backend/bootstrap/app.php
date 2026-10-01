<?php

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
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'permission' => \App\Http\Middleware\CheckPermission::class,
            'parse.json' => \App\Http\Middleware\ParseJsonRequest::class,
        ]);
        
        // Add CORS middleware
        $middleware->prepend(\Illuminate\Http\Middleware\HandleCors::class);
        
        // Add JSON parsing middleware to API routes
        $middleware->prependToGroup('api', \App\Http\Middleware\ParseJsonRequest::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();