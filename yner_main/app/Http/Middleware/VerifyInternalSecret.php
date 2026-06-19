<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyInternalSecret
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->header('X-Internal-Secret') !== config('services.internal_secret')) {
            abort(403, 'Invalid internal secret.');
        }

        return $next($request);
    }
}
