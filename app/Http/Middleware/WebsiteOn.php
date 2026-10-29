<?php

namespace App\Http\Middleware;

use App\Support\Website;
use Closure;
use Illuminate\Http\Request;

/** The website's pages exist only while the website is switched on (Website → Settings). */
class WebsiteOn
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless(Website::enabled(), 404);

        return $next($request);
    }
}
