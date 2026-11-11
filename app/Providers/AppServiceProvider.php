<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Inertia\ExceptionResponse;
use Inertia\Inertia;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Render HTTP errors with the HyTrack error screen instead of Laravel's default pages.
        Inertia::handleExceptionsUsing(function (ExceptionResponse $response) {
            $status = $response->statusCode();

            if ($response->request->expectsJson()) {
                return null;
            }

            if ($status === 419) {
                Inertia::flash('error', 'Sesi berakhir. Silakan coba lagi.');

                return back();
            }

            if (in_array($status, [403, 404, 503]) || ($status === 500 && ! config('app.debug'))) {
                return $response->render('ErrorPage', ['status' => $status]);
            }

            return null;
        });
    }
}
