<?php

use App\Exceptions\QueueRuleException;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Inertia\Inertia;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind Vercel's edge: read https and the client IP from X-Forwarded-*,
        // otherwise asset URLs come out as http and throttles key on the proxy IP.
        $middleware->trustProxies(at: '*');
        $middleware->web(append: [HandleInertiaRequests::class]);
        $middleware->alias(['role' => EnsureRole::class]);
        $middleware->redirectGuestsTo('/masuk');
        $middleware->redirectUsersTo(fn (Request $request) => $request->user()->role->home());
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // A broken queue rule is an expected outcome: send the user back with the reason.
        $exceptions->dontReport(QueueRuleException::class);
        $exceptions->render(function (QueueRuleException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            Inertia::flash('error', $e->getMessage());

            return back();
        });
    })->create();
