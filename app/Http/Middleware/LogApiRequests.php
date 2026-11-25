<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class LogApiRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->environment('local')) {
            return $next($request);
        }

        $started = microtime(true);
        $response = $next($request);

        Log::info(sprintf(
            '%s /%s -> %d (%dms) user:%s',
            $request->method(),
            $request->path(),
            $response->getStatusCode(),
            (microtime(true) - $started) * 1000,
            $request->user()?->id ?? 'guest',
        ));

        return $response;
    }
}