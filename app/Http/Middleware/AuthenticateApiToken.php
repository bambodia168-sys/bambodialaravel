<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $token = ApiToken::authenticate($request->bearerToken() ?? '');

        if ($token === null) {
            return new JsonResponse(['message' => 'Unauthenticated. Provide a valid, unexpired bearer token.'], 401);
        }

        if ($token->owner?->account_status !== 'active') {
            return new JsonResponse(['message'=>'Account is not active.'],403);
        }

        if (! $token->allows($scope)) {
            return new JsonResponse(['message' => 'This token does not have the required scope.'], 403);
        }

        $request->attributes->set('bambodia_api_token', $token);
        $request->attributes->set('bambodia_api_owner', $token->owner);
        $token->forceFill(['last_used_at' => now()])->save();

        return $next($request);
    }
}
