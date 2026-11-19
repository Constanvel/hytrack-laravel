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

        // Checked on every request, so a role or deactivation takes effect without a new login.
        abort_unless($user?->role->value === $role && $user->is_active, 403);
        // A patient account without a patient profile has nothing it may see.
        abort_if($role === 'patient' && ! $user->patient, 403);
        // Staff actions are recorded against a staff profile; an account without one may not act.
        abort_if($role === 'staff' && ! $user->staff, 403);

        return $next($request);
    }
}
