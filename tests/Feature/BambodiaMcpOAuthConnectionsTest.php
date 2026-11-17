<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use Tests\TestCase;

final class BambodiaMcpOAuthConnectionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Controller authorization is under test here, not Vite asset builds.
        // Keep the dashboard view renderable in isolated PHP-only test runners.
        $this->withoutVite();
    }

    private function actor(string $role = 'super_admin'): User
    {
        return User::query()->create([
            'name' => 'OAuth Connection Owner',
            'email' => uniqid('connections-', true).'@example.test',
            'password' => 'TestPassword123!',
            'role' => $role,
            'account_status' => 'active',
        ]);
    }

    private function token(User $owner): array
    {
        $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
            name: 'ChatGPT Bambodia Manager QA',
            redirectUris: ['https://chatgpt.com/connector/oauth/qa-callback'],
            confidential: false,
            enableDeviceFlow: false,
        );
        $token = Passport::token()->newQuery()->create([
            'id' => Str::random(80),
            'user_id' => $owner->id,
            'client_id' => $client->getKey(),
            'name' => 'OAuth acceptance access',
            'scopes' => ['mcp:use', 'offline_access'],
            'revoked' => false,
            'created_at' => now(),
            'updated_at' => now(),
            'expires_at' => now()->addMinutes(30),
        ]);
        $refresh = Passport::refreshToken()->newQuery()->create([
            'id' => Str::random(80),
            'access_token_id' => $token->getKey(),
            'revoked' => false,
            'expires_at' => now()->addDays(14),
        ]);

        return [$token, $refresh];
    }

    public function test_administrator_sees_only_their_connections_and_can_revoke_access_and_refresh_tokens(): void
    {
        $admin = $this->actor();
        [$token, $refresh] = $this->token($admin);
        [$otherToken, $otherRefresh] = $this->token($this->actor());

        $this->actingAs($admin)
            ->get('/developer/oauth-connections')
            ->assertOk()
            ->assertSee('ChatGPT Bambodia Manager QA')
            ->assertSee('OAuth Connections');

        $this->delete('/developer/oauth-connections/'.$otherToken->getKey(), [
            'password' => 'TestPassword123!',
        ])->assertNotFound();
        $this->assertFalse($otherToken->fresh()->revoked);
        $this->assertFalse($otherRefresh->fresh()->revoked);

        $this->delete('/developer/oauth-connections/'.$token->getKey(), [
            'password' => 'IncorrectPassword',
        ])->assertSessionHasErrors('password');
        $this->assertFalse($token->fresh()->revoked);

        $this->delete('/developer/oauth-connections/'.$token->getKey(), [
            'password' => 'TestPassword123!',
        ])->assertRedirect();

        $this->assertTrue($token->fresh()->revoked);
        $this->assertTrue($refresh->fresh()->revoked);
        $this->assertFalse($otherToken->fresh()->revoked);
        $this->assertFalse($otherRefresh->fresh()->revoked);
    }

    public function test_non_administrators_cannot_list_or_revoke_oauth_connections(): void
    {
        $admin = $this->actor();
        [$token] = $this->token($admin);
        $this->actingAs($this->actor('customer'))
            ->get('/developer/oauth-connections')
            ->assertForbidden();
        $this->delete('/developer/oauth-connections/'.$token->getKey(), [
            'password' => 'TestPassword123!',
        ])->assertForbidden();
        $this->assertFalse($token->fresh()->revoked);
    }
}
