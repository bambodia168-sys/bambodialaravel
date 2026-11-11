<?php
/**
 * Local-only, unauthenticated-request-transport consent VIEW preview on a pinned QA database.
 *
 * This validates that an existing synthetic super_admin and DCR client render
 * the read-only consent view with a session-scoped CSRF token. It does NOT test
 * browser login, real authorization-code issuance, consent approval/denial,
 * external HTTPS, callback delivery, PKCE exchange or token issuance.
 *
 * Requires execution under the isolated browser-QA PHP runtime (as www).
 * No HTTP requests and no DB writes. Existing OAuth artifacts are preserved.\n * Laravel may compile a view into QA storage.
 */
declare(strict_types=1);

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;

$root = '/var/lib/bambodia-oauth-browser-qa';
$failed = static function (string $label): never {
    fwrite(STDERR, "CONSENT_PREVIEW_BLOCKED=" . $label . "\n");
    exit(1);
};

try {
    if (! is_file($root . '/vendor/autoload.php') || ! is_file($root . '/artisan')) {
        $failed('QA_APP_MISSING');
    }

    require $root . '/vendor/autoload.php';
    $app = require $root . '/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    if (
        config('app.env') !== 'staging' ||
        config('app.debug') !== false ||
        config('app.url') !== 'https://oauth-browser-qa.invalid' ||
        config('database.default') !== 'mysql' ||
        config('database.connections.mysql.database') !== 'bambodia_oauthqa_browser' ||
        config('mail.default') !== 'log'
    ) {
        $failed('QA_ENVIRONMENT_MISMATCH');
    }

    $counts = static function (): array {
        return [
            'users' => DB::table('users')->count(),
            'clients' => DB::table('oauth_clients')->count(),
            'codes' => DB::table('oauth_auth_codes')->count(),
            'access' => DB::table('oauth_access_tokens')->count(),
            'refresh' => DB::table('oauth_refresh_tokens')->count(),
            'audit' => DB::table('audit_events')->count(),
        ];
    };
    $before = $counts();
    // A previously used browser-QA database can retain authorization codes
    // and OAuth tokens from earlier isolated acceptance attempts. Preserve them.
    // This offline view test requires only the one known synthetic principal and
    // DCR client; ALL six table counts must be identical after rendering.
    if ($before['users'] !== 1 || $before['clients'] !== 1) {
        $failed('UNEXPECTED_QA_PRINCIPAL_OR_CLIENT_COUNTS');
    }

    $admin = User::query()->sole();
    if ($admin->email !== 'oauth-admin@oauth-browser-qa.invalid' ||
        $admin->role !== 'super_admin' || $admin->account_status !== 'active') {
        $failed('SYNTHETIC_QA_ADMIN_MISMATCH');
    }
    $client = Client::query()->sole();
    if ($client->revoked || (string) $client->name !== 'Bambodia Browser QA PKCE Client') {
        $failed('SYNTHETIC_DCR_CLIENT_MISMATCH');
    }

    // The previous offline preview failed because SessionGuard was resolved
    // before the request existed. Bind a request + in-memory test session first.
    $request = Request::create(
        'https://oauth-browser-qa.invalid/oauth/authorize',
        'GET',
        ['state' => 'qa-consent-preview-state']
    );
    $session = new Store('bambodia-offline-consent-preview', new ArraySessionHandler(60));
    $session->start();
    $request->setLaravelSession($session);

    $app->instance('request', $request);
    $app->make('url')->setRequest($request);
    Auth::forgetGuards();
    Auth::guard('web')->setUser($admin);

    if (Auth::guard('web')->user()?->getKey() !== $admin->getKey()) {
        $failed('IN_MEMORY_SESSION_PRINCIPAL_MISMATCH');
    }
    $scopes = Passport::scopesFor(['mcp:use']);
    if (count($scopes) !== 1) {
        $failed('READ_SCOPE_NOT_REGISTERED');
    }

    // Rendering exercises the exact Blade consent template, not the entire
    // HTTP authorization flow. Fake auth_token is never persisted or approved.
    $html = view('auth.oauth.authorize', [
        'user' => $admin,
        'client' => $client,
        'request' => $request,
        'scopes' => $scopes,
        'authToken' => 'qa-preview-never-issued',
    ])->render();

    $assertions = [
        'CONSENT_HEADING' => str_contains($html, 'Connect Bambodia Manager'),
        'CLIENT_NAME' => str_contains($html, e($client->name)),
        'READ_ONLY_SCOPE' => str_contains($html, 'Read Bambodia MCP resources and operational reports'),
        'WRITE_SCOPE_NOT_DISPLAYED' => ! str_contains($html, 'Create and edit Bambodia unpublished drafts'),
        'STATE_FIELD' => str_contains($html, 'name="state"') && str_contains($html, 'qa-consent-preview-state'),
        'AUTH_TOKEN_FIELD' => str_contains($html, 'name="auth_token"'),
        'CSRF_FIELDS' => substr_count($html, 'name="_token"') >= 2,
        'APPROVE_ACTION' => str_contains($html, 'Authorize access'),
        'DENY_ACTION' => str_contains($html, 'Cancel'),
    ];

    foreach ($assertions as $label => $passed) {
        if (! $passed) {
            $failed('ASSERTION_' . $label);
        }
    }
    if ($counts() !== $before) {
        $failed('QA_DATABASE_COUNTS_CHANGED');
    }

    echo "CONSENT_PREVIEW_REQUEST_BINDING=PASS\n";
    echo "CONSENT_PREVIEW_ASSERTIONS=" . count($assertions) . "_PASS\n";
    echo "CONSENT_PREVIEW_DB_COUNTS=UNCHANGED\n";
    echo "CONSENT_PREVIEW_PHP=PASS\n";
    echo "OAUTH_CODES_ISSUED=NONE\n";
    echo "OAUTH_TOKENS_ISSUED=NONE\n";
    echo "PUBLIC_HTTPS=NOT_TESTED\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'CONSENT_PREVIEW_EXCEPTION_TYPE=' . get_class($e) . "\n");
    exit(1);
}
