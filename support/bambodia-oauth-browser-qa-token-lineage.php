<?php
/**
 * Isolated browser QA: read-only OAuth artifact linkage and expiry summary.
 *
 * Never displays IDs, email addresses, scopes, callback URLs or token values.
 * Does not assert that tokens are authorized, cryptographically valid or safe.
 * The QA user/client must be the previously verified synthetic records.
 */
declare(strict_types=1);

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Client;

$root = '/var/lib/bambodia-oauth-browser-qa';
$fail = static function (string $reason): never {
    fwrite(STDERR, 'QA_TOKEN_LINEAGE_BLOCKED=' . $reason . "\n");
    exit(1);
};

try {
    if (! is_file($root.'/vendor/autoload.php') || ! is_file($root.'/artisan')) {
        $fail('ISOLATED_QA_RUNTIME_MISSING');
    }
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    if (config('app.env') !== 'staging' ||
        config('app.debug') !== false ||
        config('app.url') !== 'https://oauth-browser-qa.invalid' ||
        config('database.default') !== 'mysql' ||
        config('database.connections.mysql.host') !== '127.0.0.1' ||
        config('database.connections.mysql.database') !== 'bambodia_oauthqa_browser' ||
        config('database.connections.mysql.username') !== 'bambodiaqa_browser') {
        $fail('UNEXPECTED_LARAVEL_RUNTIME_OR_DATABASE');
    }
    if (DB::connection('mysql')->getDatabaseName() !== 'bambodia_oauthqa_browser') {
        $fail('CONNECTED_DATABASE_MISMATCH');
    }

    if (DB::table('users')->count() !== 1 || DB::table('oauth_clients')->count() !== 1) {
        $fail('UNEXPECTED_QA_PRINCIPAL_OR_CLIENT_COUNTS');
    }
    $user = User::query()->sole();
    $client = Client::query()->sole();
    if ($user->email !== 'oauth-admin@oauth-browser-qa.invalid' ||
        $user->role !== 'super_admin' || $user->account_status !== 'active' ||
        $client->name !== 'Bambodia Browser QA PKCE Client' || $client->revoked) {
        $fail('UNEXPECTED_QA_PRINCIPAL_OR_CLIENT');
    }

    $userId = $user->getKey();
    $clientId = $client->getKey();

    $codeTable = DB::table('oauth_auth_codes');
    $accessTable = DB::table('oauth_access_tokens');
    $refreshTable = DB::table('oauth_refresh_tokens');

    $codes = $codeTable->count();
    $access = $accessTable->count();
    $refresh = $refreshTable->count();

    $otherCodes = DB::table('oauth_auth_codes')
        ->where(function ($query) use ($userId, $clientId): void {
            $query->where('user_id', '!=', $userId)
                ->orWhere('client_id', '!=', $clientId);
        })->count();

    $otherAccess = DB::table('oauth_access_tokens')
        ->where(function ($query) use ($userId, $clientId): void {
            $query->whereNull('user_id')
                ->orWhere('user_id', '!=', $userId)
                ->orWhere('client_id', '!=', $clientId);
        })->count();

    $orphanRefresh = DB::table('oauth_refresh_tokens as r')
        ->leftJoin('oauth_access_tokens as a', 'a.id', '=', 'r.access_token_id')
        ->whereNull('a.id')->count();

    // Distinguish linkage to this client from mere linkage to any access token.
    $otherRefresh = DB::table('oauth_refresh_tokens as r')
        ->leftJoin('oauth_access_tokens as a', 'a.id', '=', 'r.access_token_id')
        ->where(function ($query) use ($userId, $clientId): void {
            $query->whereNull('a.id')
                ->orWhereNull('a.user_id')
                ->orWhere('a.user_id', '!=', $userId)
                ->orWhere('a.client_id', '!=', $clientId);
        })->count();

    $expiry = static function (string $table, string $name): void {
        $revoked = DB::table($table)->where('revoked', true)->count();
        $expired = DB::table($table)->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())->count();
        $noExpiry = DB::table($table)->whereNull('expires_at')->count();
        $potential = DB::table($table)->where('revoked', false)
            ->whereNotNull('expires_at')->where('expires_at', '>', now())->count();
        echo $name.'_REVOKED='.$revoked."\n";
        echo $name.'_EXPIRED='.$expired."\n";
        echo $name.'_NULL_EXPIRY='.$noExpiry."\n";
        echo $name.'_UNREVOKED_UNEXPIRED='.$potential."\n";
    };

    echo 'QA_AUTH_CODES='.$codes."\n";
    echo 'QA_ACCESS_TOKENS='.$access."\n";
    echo 'QA_REFRESH_TOKENS='.$refresh."\n";
    echo 'AUTH_CODES_WRONG_USER_OR_CLIENT='.$otherCodes."\n";
    echo 'ACCESS_TOKENS_WRONG_USER_OR_CLIENT='.$otherAccess."\n";
    echo 'REFRESH_TOKENS_ORPHANED='.$orphanRefresh."\n";
    echo 'REFRESH_TOKENS_WRONG_USER_OR_CLIENT='.$otherRefresh."\n";

    $expiry('oauth_auth_codes', 'AUTH_CODES');
    $expiry('oauth_access_tokens', 'ACCESS_TOKENS');
    $expiry('oauth_refresh_tokens', 'REFRESH_TOKENS');

    if ($otherCodes !== 0 || $otherAccess !== 0 ||
        $orphanRefresh !== 0 || $otherRefresh !== 0) {
        $fail('UNEXPECTED_QA_TOKEN_RELATIONSHIP');
    }

    echo "QA_TOKEN_LINEAGE=PASS\n";
    echo "BEARER_TOKEN_CRYPTOGRAPHY=NOT_TESTED\n";
    echo "CONSENT_APPROVAL=NOT_TESTED\n";
    echo "CALLBACK_URLS_AND_TOKEN_IDS=NOT_DISPLAYED\n";
    echo "SQL_WRITES=NONE\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'QA_TOKEN_LINEAGE_EXCEPTION=' . get_class($exception) . "\n");
    exit(1);
}
