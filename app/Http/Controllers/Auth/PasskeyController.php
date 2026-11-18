<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\User;
use App\Support\Security\SignIn;
use App\Support\SystemSecurity;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Spatie\LaravelPasskeys\Actions\FindPasskeyToAuthenticateAction;
use Spatie\LaravelPasskeys\Actions\GeneratePasskeyRegisterOptionsAction;
use Spatie\LaravelPasskeys\Actions\StorePasskeyAction;
use Spatie\LaravelPasskeys\Support\Config;
use Spatie\LaravelPasskeys\Support\Serializer;
use Throwable;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\PublicKeyCredentialRequestOptions;

final class PasskeyController extends Controller
{
    public function registrationOptions(Request $request, GeneratePasskeyRegisterOptionsAction $generate): Response
    {
        abort_unless(SystemSecurity::enabled('passkeys'), 404);
        $request->validate(['current_password' => ['required', 'current_password']]);
        abort_if($request->user()->passkeys()->count() >= 10, 422, 'Remove a passkey before adding another.');
        $options = $generate->execute($request->user(), false);
        $options->authenticatorSelection->userVerification = AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED;

        return $this->issue($request, 'register', Serializer::make()->toJson($options), $request->user()->id);
    }

    public function register(Request $request, StorePasskeyAction $store): JsonResponse
    {
        abort_unless(SystemSecurity::enabled('passkeys'), 404);
        $values = $request->validate(['name' => ['required', 'string', 'max:80'], 'credential' => ['required', 'json', 'max:16000']]);
        $user = $request->user();
        $options = $this->consume($request, 'register', $user->id);

        try {
            $store->execute($user, $values['credential'], $options, Config::getRelyingPartyId(), ['name' => $values['name']]);
        } catch (UniqueConstraintViolationException) {
            abort(422, 'This passkey is already registered.');
        } catch (Throwable) {
            abort(422, 'Could not verify this passkey. Start again.');
        }

        AuditEvent::record('auth.passkey.registered', $user, $request);

        $request->session()->flash('passkey_status', 'Passkey saved successfully.');

        return response()->json(['redirect' => route('account.security').'#account-passkeys'], 201);
    }

    public function authenticationOptions(Request $request): Response
    {
        abort_unless(SystemSecurity::enabled('passkeys'), 404);
        $options = new PublicKeyCredentialRequestOptions(
            challenge: random_bytes(32),
            rpId: Config::getRelyingPartyId(),
            allowCredentials: [],
            userVerification: PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED,
        );

        return $this->issue($request, 'login', Serializer::make()->toJson($options));
    }

    public function authenticate(Request $request, FindPasskeyToAuthenticateAction $find): JsonResponse
    {
        abort_unless(SystemSecurity::enabled('passkeys'), 404);
        $values = $request->validate(['credential' => ['required', 'json', 'max:16000']]);
        $options = $this->consume($request, 'login');

        try {
            $passkey = $find->execute($values['credential'], $options);
        } catch (Throwable) {
            abort(422, 'Passkey sign-in could not be verified.');
        }
        $user = $passkey?->authenticatable;
        abort_unless($user instanceof User && $user->account_status === 'active', 422, 'Passkey sign-in could not be verified.');

        return response()->json(['redirect' => SignIn::external($request, $user)->getTargetUrl()]);
    }

    public function destroy(Request $request, string $passkey): RedirectResponse
    {
        $request->validate(['current_password' => ['required', 'current_password']]);
        $request->user()->passkeys()->findOrFail($passkey)->delete();
        AuditEvent::record('auth.passkey.removed', $request->user(), $request);

        return back()->with('status', 'Passkey removed.');
    }

    private function issue(Request $request, string $purpose, string $options, ?int $userId = null): Response
    {
        $request->session()->put('bambodia_passkeys.'.$purpose, ['options' => $options, 'at' => now()->timestamp, 'user' => $userId]);

        return response($options, 200, ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store']);
    }

    private function consume(Request $request, string $purpose, ?int $userId = null): string
    {
        $state = $request->session()->pull('bambodia_passkeys.'.$purpose);
        abort_unless(is_array($state) && $state['user'] === $userId && $state['at'] >= now()->subMinutes(3)->timestamp,
            422, 'This passkey request expired. Start again.');

        return $state['options'];
    }
}
