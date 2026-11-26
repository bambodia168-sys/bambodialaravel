<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\FeatureFlag;
use App\Models\PolicyAcknowledgement;
use App\Models\PolicyPublication;
use App\Models\SocialLoginIdentity;
use App\Models\SocialLoginProvider;
use App\Models\User;
use App\Support\EmailOutbox;
use App\Support\Security\SignIn;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Laravel\Socialite\Two\User as ProviderUser;

final class SocialLinkedInController extends Controller
{
    public const SESSION_KEY = 'social_linkedin.pending';

    public static function begin(Request $request, ProviderUser $profile, string $subject): RedirectResponse
    {
        $email = $profile->getEmail();
        $raw = $profile->user;
        if (!is_string($email) || !is_array($raw) || ($raw['email_verified'] ?? false) !== true
            || !is_string($raw['email'] ?? null) || strtolower(trim($raw['email'])) !== strtolower(trim($email))
            || strlen(trim($email)) > 254 || !filter_var(trim($email), FILTER_VALIDATE_EMAIL)) {
            return redirect()->route('login')->withErrors([
                'social_auth'=>'LinkedIn did not provide a verified email address. Confirm your email in LinkedIn and try again, or use another sign-in method.',
            ]);
        }

        $email = strtolower(trim($email));
        $existing = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
        if ($existing && (!self::eligible($existing) || SocialLoginIdentity::query()
            ->where('user_id',$existing->id)->where('provider','linkedin')->exists())) {
            return self::unavailable();
        }
        if (!$existing && (!FeatureFlag::isEnabled('registration') || !PolicyPublication::currentRegistrationTerms())) {
            return redirect()->route('login')->withErrors(['social_auth'=>'New account registration is currently unavailable.']);
        }

        $name = trim((string)$profile->getName());
        $request->session()->put(self::SESSION_KEY, [
            'email'=>$email,'name'=>$name !== '' ? mb_substr($name,0,120) : 'Bambodia Member',
            'subject'=>$subject,'user_id'=>$existing?->id,'at'=>now()->timestamp,
            'credential'=>$existing ? self::credential($existing) : null,
            'email_confirmable'=>$existing?->hasVerifiedEmail() ?? false,
        ]);
        return redirect()->route('social.linkedin.continue');
    }

    public function show(Request $request): Response|RedirectResponse
    {
        $pending = $this->pending($request);
        if (!$pending) return self::expired();
        $termsPublication = $pending['user_id'] === null ? PolicyPublication::currentRegistrationTerms() : null;
        if ($pending['user_id'] === null && (!FeatureFlag::isEnabled('registration') || !$termsPublication)) {
            return self::expired();
        }
        return response()->view('auth.social-linkedin-continue', compact('pending','termsPublication'))
            ->header('Cache-Control','private, no-store');
    }

    public function sendCode(Request $request): RedirectResponse
    {
        $pending = $this->pending($request);
        if (!$pending || $pending['user_id'] === null) return self::expired();
        $user = User::query()->find($pending['user_id']);
        if (!self::matches($user,$pending) || !$user->hasVerifiedEmail() || !($pending['email_confirmable'] ?? false)) return self::unavailable();
        if (!EmailOutbox::deliveryReady()) {
            return redirect()->route('social.linkedin.continue')->withErrors(['social_auth'=>'Email confirmation is temporarily unavailable. Use your existing sign-in method.']);
        }

        $key = 'linkedin-confirm:'.$user->id;
        if (RateLimiter::tooManyAttempts($key,3)) {
            return redirect()->route('social.linkedin.continue')->withErrors(['social_auth'=>'Too many confirmation requests. Try again in a few minutes.']);
        }
        RateLimiter::hit($key,600);
        $code = (string)random_int(10000000,99999999);
        // Invalidate any prior code even if delivery subsequently fails.
        unset($pending['code_hash'],$pending['code_at']);
        $request->session()->put(self::SESSION_KEY,$pending);
        try {
            $sent = Mail::raw('Your Bambodia LinkedIn confirmation code is '.$code.". It expires in 5 minutes. Do not share it. If you did not request this, ignore this email.",
                function ($message) use ($user): void {
                    $message->to($user->email)->subject('Confirm your Bambodia LinkedIn sign-in');
                });
            if (!$sent) throw new \RuntimeException('Confirmation mail was not accepted.');
        } catch (\Throwable) {
            return redirect()->route('social.linkedin.continue')->withErrors(['social_auth'=>'Could not send your confirmation code. Use your existing sign-in method or try again.']);
        }
        $pending['code_hash'] = self::codeHash($pending,$code);
        $pending['code_at'] = now()->timestamp;
        $request->session()->put(self::SESSION_KEY,$pending);
        return redirect()->route('social.linkedin.continue')->with('status','A confirmation code was sent to your Bambodia email address.');
    }

    public function store(Request $request): RedirectResponse
    {
        $pending = $this->pending($request);
        if (!$pending) return self::expired();
        if ($pending['user_id'] !== null) {
            return $this->confirmExisting($request,$pending);
        }

        abort_unless(FeatureFlag::isEnabled('registration'),404);
        $terms = PolicyPublication::currentRegistrationTerms();
        if (!$terms) return self::expired();
        $request->validate([
            'terms_accepted'=>['required','accepted'],
            'terms_publication_id'=>['required','integer',Rule::in([(int)$terms->id])],
        ]);
        try {
            $user = DB::transaction(function () use ($pending,$terms,$request): ?User {
                if (User::query()->whereRaw('LOWER(email) = ?',[$pending['email']])->lockForUpdate()->exists()
                    || SocialLoginIdentity::query()->where('provider','linkedin')->where('subject_hash',$pending['subject'])->exists()) {
                    return null;
                }
                $user = User::query()->create([
                    'name'=>$pending['name'],'email'=>$pending['email'],'password'=>Str::random(64),'role'=>'customer',
                ]);
                $user->forceFill(['email_verified_at'=>now()])->save();
                self::bind($user,$pending,$request);
                PolicyAcknowledgement::query()->create([
                    'policy_publication_id'=>$terms->id,'user_id'=>$user->id,'sha256'=>$terms->sha256,
                    'record_type'=>'registration_acceptance','created_at'=>now(),
                ]);
                AuditEvent::record('auth.registration.terms_accepted',$user,$request);
                EmailOutbox::queue('account.welcome',$user->id,'linkedin-registration',[],route('account',absolute:false));
                return $user;
            },3);
        } catch (UniqueConstraintViolationException) {
            $user = null;
        }
        $request->session()->forget(self::SESSION_KEY);
        return $user ? SignIn::external($request,$user,'linkedin') : self::unavailable();
    }

    private function confirmExisting(Request $request,array $pending): RedirectResponse
    {
        $code = $request->input('confirmation_code');
        $key = 'linkedin-confirm-attempt:'.$pending['user_id'];
        if (RateLimiter::tooManyAttempts($key,5)) {
            $request->session()->forget(self::SESSION_KEY);
            return self::expired();
        }
        RateLimiter::hit($key,600);
        $password = $request->input('current_password');
        $current = User::query()->find($pending['user_id']);
        $byPassword = is_string($password) && strlen($password) <= 4096
            && self::matches($current,$pending) && Hash::check($password,$current->getAuthPassword());
        $byEmail = !filled($password) && self::matches($current,$pending)
            && $current->hasVerifiedEmail() && ($pending['email_confirmable'] ?? false)
            && is_string($code) && preg_match('/\A[0-9]{8}\z/D',$code)
            && is_int($pending['code_at'] ?? null) && $pending['code_at'] >= now()->subMinutes(5)->timestamp
            && $pending['code_at'] <= now()->timestamp && is_string($pending['code_hash'] ?? null)
            && hash_equals($pending['code_hash'],self::codeHash($pending,$code));
        if (!$byPassword && !$byEmail) {
            return redirect()->route('social.linkedin.continue')->withErrors(['social_auth'=>'The password or confirmation code is invalid or expired.']);
        }

        // Consume the challenge before binding; recheck current account and identity under lock.
        $request->session()->forget(self::SESSION_KEY);
        try {
            $user = DB::transaction(function () use ($request,$pending,$byEmail): ?User {
                $user = User::query()->lockForUpdate()->find($pending['user_id']);
                if (!self::matches($user,$pending) || ($byEmail && !$user->hasVerifiedEmail()) || SocialLoginIdentity::query()->where('provider','linkedin')
                    ->where(fn ($q)=>$q->where('user_id',$user->id)->orWhere('subject_hash',$pending['subject']))->exists()) {
                    return null;
                }
                self::bind($user,$pending,$request);
                return $user;
            },3);
        } catch (UniqueConstraintViolationException) {
            $user = null;
        }
        if (!$user) return self::unavailable();
        RateLimiter::clear($key);
        return SignIn::external($request,$user,'linkedin');
    }

    private function pending(Request $request): ?array
    {
        $pending = $request->session()->get(self::SESSION_KEY);
        if (!is_array($pending) || !is_string($pending['email'] ?? null)
            || !filter_var($pending['email'],FILTER_VALIDATE_EMAIL) || strlen($pending['email'])>254
            || !is_string($pending['name'] ?? null)
            || !is_string($pending['subject'] ?? null) || !preg_match('/\A[a-f0-9]{64}\z/D',$pending['subject'])
            || !array_key_exists('user_id',$pending) || ($pending['user_id'] !== null && !is_int($pending['user_id']))
            || !is_int($pending['at'] ?? null) || $pending['at'] > now()->timestamp
            || $pending['at'] < now()->subMinutes(10)->timestamp
            || !SocialLoginProvider::query()->whereKey('linkedin')->where('enabled',true)->exists()) {
            $request->session()->forget(self::SESSION_KEY);
            return null;
        }
        return $pending;
    }

    private static function eligible(?User $user): bool
    {
        // An unverified local email can only be connected after password proof.
        return $user !== null && $user->account_status === 'active';
    }

    private static function matches(?User $user,array $pending): bool
    {
        return self::eligible($user) && strtolower($user->email) === $pending['email']
            && is_string($pending['credential'] ?? null) && hash_equals($pending['credential'],self::credential($user));
    }

    private static function credential(User $user): string
    {
        return hash_hmac('sha256',(string)$user->getAuthPassword(),(string)config('app.key'));
    }

    private static function codeHash(array $pending,string $code): string
    {
        return hash_hmac('sha256',$pending['subject'].'|'.$pending['user_id'].'|'.$pending['at'].'|'.$code,(string)config('app.key'));
    }

    private static function bind(User $user,array $pending,Request $request): void
    {
        SocialLoginIdentity::query()->create(['user_id'=>$user->id,'provider'=>'linkedin','subject_hash'=>$pending['subject']]);
        AuditEvent::record('auth.social.connected',$user,$request);
    }

    private static function unavailable(): RedirectResponse
    {
        return redirect()->route('login')->withErrors(['social_auth'=>'This account cannot be connected automatically. Use your existing sign-in method and connect LinkedIn in Account Security.']);
    }

    private static function expired(): RedirectResponse
    {
        return redirect()->route('login')->withErrors(['social_auth'=>'Your LinkedIn sign-in request expired or is unavailable. Start again.']);
    }
}
