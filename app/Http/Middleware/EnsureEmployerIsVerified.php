<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureEmployerIsVerified
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle($request, Closure $next)
    {
        if ($request->user()->verification_status !== 'active') {
            return response()->json([
                'message' => 'Your account is not verified. Contact ForgeKin Support.',
                'success' => false
            ], 403);
        }

        return $next($request);
    }
}
