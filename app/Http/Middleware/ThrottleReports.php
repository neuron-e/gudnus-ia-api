<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class ThrottleReports
{
    public function handle(Request $request, Closure $next)
    {
        $key = 'report-generation:' . $request->ip();

        if (RateLimiter::tooManyAttempts($key, 3)) { // 3 reportes por hora
            $seconds = RateLimiter::availableIn($key);

            return response()->json([
                'error' => 'Demasiadas solicitudes de reportes. Intenta en ' . ceil($seconds / 60) . ' minutos.',
            ], 429);
        }

        RateLimiter::hit($key, 3600); // 1 hora

        return $next($request);
    }
}
