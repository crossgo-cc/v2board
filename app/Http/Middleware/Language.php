<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\App;

class Language
{
    public function handle($request, Closure $next)
    {
        App::setLocale($request->header('content-language') ?: config('app.locale'));

        return $next($request);
    }
}
