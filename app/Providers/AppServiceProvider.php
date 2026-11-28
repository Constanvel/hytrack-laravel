<?php

namespace App\Providers;

use Illuminate\Support\Facades\Artisan;
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
        // Vercel has no deploy hook for migrations: the first request of each new instance runs
        // them. ponytail: concurrent cold starts may both migrate; Postgres wraps each migration
        // in a transaction, so the loser's request fails once and the next one sees it done.
        if (config('hytrack.auto_migrate') && ! $this->app->runningInConsole()) {
            $this->app->booted(function () {
                $done = sys_get_temp_dir().'/hytrack-upgraded';
                if (! is_file($done)) {
                    Artisan::call('hytrack:upgrade');
                    touch($done);
                }
            });
        }

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
