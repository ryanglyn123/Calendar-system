<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsApproved
{
    /**
     * Block users whose account is not yet approved (or has been suspended)
     * from everything except the pending-approval screen and logout.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->isActive()) {
            return redirect()->route('approval.pending');
        }

        return $next($request);
    }
}
