<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    public function handle(Request $request, Closure $next, string $role): Response
    {
        $user = $request->user();

        abort_unless($user?->role->value === $role, 403);
        // A patient account without a patient profile has nothing it may see.
        abort_if($role === 'patient' && ! $user->patient, 403);

        return $next($request);
    }
}
