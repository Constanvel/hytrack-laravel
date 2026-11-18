<?php

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Prototype mode: the screens with in-browser sample data. No web middleware, so no session or database.
        then: fn () => Route::view('/prototipe/{path?}', 'prototype')->where('path', '.*'),
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind Vercel's edge: read https and the client IP from X-Forwarded-*,
        // otherwise asset URLs come out as http and throttles key on the proxy IP.
        $middleware->trustProxies(at: '*');
        $middleware->web(append: [HandleInertiaRequests::class]);
        $middleware->redirectGuestsTo('/masuk');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
