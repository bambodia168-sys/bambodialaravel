<?php

namespace App\Http\Controllers\Developer;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Passport\Passport;
use Throwable;

/**
 * A human-operated, real HTTPS MCP check for the isolated OAuth QA host.
 *
 * This does NOT simulate OAuth/PKCE or make the connection for ChatGPT. It
 * exercises the deployed MCP bearer transport, token middleware, tool discovery,
 * and two read-only tools against the actual QA hostname.
 */
final class McpConnectionCenterController extends Controller
{
    private const QA_ORIGIN = 'https://oauth-qa.bambodia.com';

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $isQa = $this->isQa();

        $oauthStorageReady = Schema::hasTable('oauth_access_tokens')
            && Schema::hasTable('oauth_clients');

        $activeConnections = $oauthStorageReady
            ? Passport::token()->newQuery()
                ->where('user_id', $user->id)
                ->whereJsonContains('scopes', 'mcp:use')
                ->where('revoked', false)
                ->where('expires_at', '>', now())
                ->count()
            : 0;

        return response()->view('developer.mcp-connection', [
            'qa' => $isQa,
            'oauthStorageReady' => $oauthStorageReady,
            'activeConnections' => $activeConnections,
            'mcpUrl' => rtrim((string) config('app.url'), '/').'/mcp',
        ])->header('Cache-Control', 'private, no-store');
    }

    public function check(Request $request): RedirectResponse
    {
        abort_unless($this->isQa(), 403, 'Connection checks are restricted to the isolated QA host.');

        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        /** @var User $actor */
        $actor = $request->user();

        // The secret never leaves this server except in the HTTPS Authorization
        // header of the dedicated QA host. A 3-minute expiry limits damage even
        // if token revocation encounters a database error.
        [$token, $secret] = ApiToken::issue(
            $actor, 'QA one-time MCP connection check', ['mcp.read'], 7
        );
        $token->forceFill(['expires_at' => now()->addMinutes(3)])->save();

        $passed = false;
        $message = 'The deployed QA MCP endpoint did not complete the read-only check. Review HTTPS routing, authentication and application logs.';

        try {
            $baseRequest = fn () => Http::withToken($secret)
                ->acceptJson()
                ->timeout(12)
                ->withOptions(['allow_redirects' => false, 'verify' => true]);

            $tools = $baseRequest()->post(self::QA_ORIGIN.'/mcp', [
                'jsonrpc' => '2.0',
                'id' => 'qa-discovery',
                'method' => 'tools/list',
                'params' => (object) [],
            ]);

            $definitions = $tools->json('result.tools');
            $toolNames = is_array($definitions)
                ? array_values(array_filter(array_map(
                    static fn ($definition) => is_array($definition) ? ($definition['name'] ?? null) : null,
                    $definitions
                ), 'is_string'))
                : [];

            $expected = ['bambodia_overview', 'bambodia_list', 'bambodia_get', 'bambodia_analytics'];
            if ($tools->ok() && count(array_diff($expected, $toolNames)) === 0) {
                $overview = $baseRequest()->post(self::QA_ORIGIN.'/mcp', [
                    'jsonrpc' => '2.0',
                    'id' => 'qa-overview',
                    'method' => 'tools/call',
                    'params' => [
                        'name' => 'bambodia_overview',
                        'arguments' => (object) [],
                    ],
                ]);
                $overviewData = $overview->json('result.structuredContent');

                if ($overview->ok()
                    && $overview->json('result.isError') === false
                    && is_array($overviewData)
                    && ($overviewData['site'] ?? null) === self::QA_ORIGIN) {
                    $list = $baseRequest()->post(self::QA_ORIGIN.'/mcp', [
                        'jsonrpc' => '2.0',
                        'id' => 'qa-list',
                        'method' => 'tools/call',
                        'params' => [
                            'name' => 'bambodia_list',
                            'arguments' => ['entity' => 'post', 'page' => 1],
                        ],
                    ]);
                    $data = $list->json('result.structuredContent');
                    if ($list->ok()
                        && $list->json('result.isError') === false
                        && is_array($data)
                        && ($data['entity'] ?? null) === 'post'
                        && is_array($data['data'] ?? null)) {
                        $passed = true;
                        $message = 'Live HTTPS MCP check passed: tool discovery, administrator authorization, overview, and post listing. This does not yet verify OAuth sign-in or writes.';
                    }
                }
            }
        } catch (Throwable $e) {
            // Never expose the transient bearer, HTTP headers, connection
            // internals or untrusted upstream errors to the administrator UI.
            $message = 'The QA MCP HTTPS request could not be completed. Check the QA domain, Nginx/PHP-FPM and application logs.';
        } finally {
            $token->forceFill(['revoked_at' => now()])->save();
            unset($secret);
        }

        return redirect()->route('developer.mcp.index')
            ->with($passed ? 'mcp_check_success' : 'mcp_check_error', $message);
    }

    /**
     * One explicit operator action creates ONE unpublished QA post through
     * the actual HTTPS MCP write tool. The result is verified against the
     * same Post Manager model used by the administrator dashboard.
     */
    public function createQaDraft(Request $request): RedirectResponse
    {
        abort_unless($this->isQa(), 403, 'Draft checks are restricted to isolated QA.');

        $request->validate([
            'password' => ['required', 'current_password'],
            'confirm_test_draft' => ['required', 'accepted'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        [$token, $secret] = ApiToken::issue(
            $actor, 'QA one-time MCP draft check', ['mcp.read', 'mcp.write'], 7
        );
        $token->forceFill(['expires_at' => now()->addMinutes(3)])->save();

        $title = 'QA MCP Draft '.now()->format('Ymd-His').'-'.substr(bin2hex(random_bytes(4)), 0, 8);
        $message = 'Could not confirm the test draft. Check Post Manager before repeating to avoid duplicate drafts.';

        try {
            $result = Http::withToken($secret)
                ->acceptJson()
                ->timeout(15)
                ->withOptions(['allow_redirects' => false, 'verify' => true])
                ->post(self::QA_ORIGIN.'/mcp', [
                    'jsonrpc' => '2.0',
                    'id' => 'qa-create-post',
                    'method' => 'tools/call',
                    'params' => [
                        'name' => 'bambodia_create_draft',
                        'arguments' => [
                            'entity' => 'post',
                            'fields' => [
                                'title' => $title,
                                'body' => '<p>Functional QA connection test: an unpublished blog post created through the Bambodia MCP HTTPS endpoint, for administrator verification only.</p>',
                            ],
                        ],
                    ],
                ]);

            $draft = $result->json('result.structuredContent');
            if ($result->ok()
                && $result->json('result.isError') === false
                && is_array($draft)
                && is_numeric($draft['id'] ?? null)
                && ($draft['title'] ?? null) === $title
                && ($draft['status'] ?? null) === 'draft') {
                $entry = \App\Models\ContentEntry::query()
                    ->whereKey((int) $draft['id'])
                    ->where('author_id', $actor->id)
                    ->where('type', 'post')
                    ->where('status', 'draft')
                    ->where('title', $title)
                    ->first();
                if ($entry) {
                    return redirect()->route('developer.mcp.index')->with('mcp_draft_created', [
                        'id' => (int) $entry->id,
                        'title' => $title,
                    ]);
                }
            }
        } catch (Throwable $e) {
            // No retry: the post may have been created before an HTTP timeout.
        } finally {
            $token->forceFill(['revoked_at' => now()])->save();
            unset($secret);
        }

        return redirect()->route('developer.mcp.index')->with('mcp_check_error', $message);
    }

    private function isQa(): bool
    {
        return config('app.env') === 'staging'
            && rtrim((string) config('app.url'), '/') === self::QA_ORIGIN;
    }
}
