<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers for every response, with an enforced nonce Content-Security-Policy (the Dot fleet standard, see
 * InfoDot R-19). `Vite::useCspNonce()` runs before the view renders, so the @vite tags and Livewire's own asset
 * tags carry the nonce on their own; a hand written inline script must carry `nonce="{{ Vite::cspNonce() }}"`.
 *
 * Accepted constraints, same as the rest of the fleet: `'unsafe-eval'` stays in script-src because Livewire 3 ships
 * Alpine's default build (it evaluates directive expressions with the Function constructor), and inline `style=""`
 * attributes stay allowed because the kit sets custom properties that way. Native `on*=` handlers are not allowed
 * anywhere (`script-src-attr 'none'`); use Alpine directives.
 *
 * A response that already carries its own Content-Security-Policy (the media route sends `default-src 'none'`) keeps it.
 * SECURITY_CSP_REPORT_ONLY=true turns the policy into Content-Security-Policy-Report-Only without a deploy.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Str::random(24);
        Vite::useCspNonce($nonce);

        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');

        $header = config('pulse.security.csp_report_only') ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy';

        if (! $response->headers->has('Content-Security-Policy') && ! $response->headers->has('Content-Security-Policy-Report-Only')) {
            $response->headers->set($header, $this->policy($request, $nonce));
        }

        if (app()->environment('production') || $request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        $response->headers->remove('X-Powered-By');
        if (! headers_sent()) {
            header_remove('X-Powered-By'); // PHP adds this one itself (expose_php)
        }

        return $response;
    }

    private function policy(Request $request, string $nonce): string
    {
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}' 'unsafe-eval'",
            "script-src-attr 'none'",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
            "font-src 'self' data: https://fonts.gstatic.com",
            "img-src 'self' data: blob:",
            "media-src 'self'",
            'connect-src '.implode(' ', $this->connectSources($request)),
            "object-src 'none'",
            "frame-src 'self'",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
        ]);
    }

    /** @return list<string> */
    private function connectSources(Request $request): array
    {
        $sources = ["'self'"];

        $host = (string) config('pulse.security.reverb_public.host', '');
        $port = (string) config('pulse.security.reverb_public.port', '');
        $tls = config('pulse.security.reverb_public.scheme') === 'https';

        if ($host !== '') {
            $sources[] = ($tls ? 'wss' : 'ws').'://'.$host.($port !== '' ? ':'.$port : '');
        }

        if ($request->isSecure()) {
            $sources[] = 'wss://'.$request->getHost();
        }

        return array_values(array_unique($sources));
    }
}
