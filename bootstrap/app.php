<?php

use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\EnsureActiveAccount;
use App\Http\Middleware\EnsureCapability;
use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => EnsureRole::class,
            'api.token' => AuthenticateApiToken::class,
            'capability' => EnsureCapability::class,
        ]);
        $middleware->web(append: [SetLocale::class, EnsureActiveAccount::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Credentials are never echoed into validation redirects or flash data.
        $exceptions->dontFlash(['access_key_id','secret_access_key','secret_key','cf-turnstile-response']);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
