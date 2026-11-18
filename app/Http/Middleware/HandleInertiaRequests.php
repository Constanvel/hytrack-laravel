<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    /**
     * Props every page receives. The React screens expect these (see resources/js/types.ts and
     * resources/js/prototype/fixtures.json for real examples):
     * hospital, demo, pollSeconds, today, now, auth {role, name, phone, unit}, visit, unread, following.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
        ];
    }
}
