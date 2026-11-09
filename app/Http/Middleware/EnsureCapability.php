<?php

namespace App\Http\Middleware;

use App\Support\UserManagementAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCapability
{
    public function handle(Request $request, Closure $next, string $capability): Response
    {
        abort_unless($request->user() && UserManagementAccess::allows($request->user(),$capability),403);
        return $next($request);
    }
}