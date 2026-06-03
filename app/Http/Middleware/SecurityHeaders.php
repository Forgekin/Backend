<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds defensive HTTP response headers to every request. These are cheap,
 * broadly-compatible hardening headers that reduce clickjacking, MIME-sniffing,
 * referrer leakage, and unwanted browser feature access.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=(), payment=()');
        // Modern browsers ignore the legacy XSS auditor; explicitly disable it
        // (the auditor itself has been a source of vulnerabilities).
        $response->headers->set('X-XSS-Protection', '0');

        // Only advertise HSTS over real HTTPS so local http dev isn't pinned.
        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        // Don't leak the runtime/version.
        $response->headers->remove('X-Powered-By');

        return $response;
    }
}
