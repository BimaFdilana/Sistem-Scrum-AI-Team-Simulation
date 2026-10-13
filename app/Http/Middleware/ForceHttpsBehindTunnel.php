<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tunnel publik (Pinggy/localhost.run) menghentikan TLS di edge dan meneruskan
 * HTTP polos tanpa X-Forwarded-Proto, sehingga asset() menghasilkan URL http://
 * yang diblokir browser (mixed content) -> UI tampil polos. Paksa skema https
 * untuk host non-lokal; akses langsung 127.0.0.1/localhost tetap http.
 */
class ForceHttpsBehindTunnel
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->getHost(), ['localhost', '127.0.0.1', '[::1]'], true)) {
            URL::forceScheme('https');
        }

        return $next($request);
    }
}
