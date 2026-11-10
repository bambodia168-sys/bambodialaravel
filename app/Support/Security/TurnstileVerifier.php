<?php

namespace App\Support\Security;

use App\Models\TurnstileBotProtection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

final class TurnstileVerifier
{
    private const VERIFY_URL='https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public static function verifyToken(TurnstileBotProtection $config, Request $request, string $action): bool
    {
        $token=$request->input('cf-turnstile-response');
        if (! is_string($token) || $token==='' || strlen($token)>2048) {
            return false;
        }

        try {
            $response=Http::asForm()->connectTimeout(3)->timeout(6)->post(self::VERIFY_URL,[
                'secret'=>$config->secret_key,
                'response'=>$token,
                // No remote IP is sent. Server-side verification still validates
                // the one-use token without transmitting an extra user identifier.
            ]);
            if (! $response->successful() || $response->json('success') !== true) {
                return false;
            }
            $host=parse_url((string)config('app.url'), PHP_URL_HOST);
            return is_string($host) && $host!==''
                && strcasecmp((string)$response->json('hostname'),$host)===0
                && $response->json('action')===$action;
        } catch (\Throwable) {
            // Third-party failures must never silently bypass an enabled check.
            return false;
        }
    }

    public static function enforce(Request $request, string $action): void
    {
        $config=TurnstileBotProtection::forAction($action);
        if (! $config) {
            return;
        }
        if (! self::verifyToken($config,$request,$action)) {
            throw ValidationException::withMessages([
                'turnstile'=>'Security verification failed or expired. Complete the check and try again.',
            ]);
        }
    }
}
