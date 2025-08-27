<?php

namespace App\Providers;

use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
class RateLimiterServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        RateLimiter::for('public-links', function (Request $request) {
            // Ajusta el bucket a tu gusto (por IP)
            return Limit::perMinute(60)->by($request->ip());
        });
    }
}
