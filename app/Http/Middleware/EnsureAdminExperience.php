<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps plain operational users (Employee / Team Leader) out of the back-office
 * area and on their minimal dashboard. This is a UX guard only — the real
 * protection of financial data is enforced by per-permission route middleware
 * and policies.
 */
class EnsureAdminExperience
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->usesAdminExperience()) {
            return redirect()->route('employee.dashboard');
        }

        return $next($request);
    }
}
