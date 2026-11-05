<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\MarketplaceListing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeveloperApiTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'VeryStrongPassword123!';

    private function account(string $role = 'super_admin'): User
    {
        return User::query()->create([
            'name' => 'API Account',
            'email' => uniqid('developer', true).'@example.test',
            'password' => self::PASSWORD,
            'role' => $role,
        ]);
    }

    public function test_only_super_admin_can_visit_developer_workspace(): void
    {
        $admin = $this->account('admin');
        $this->actingAs($admin)->get('/developer')->assertForbidden();
        $this->actingAs($this->account())->get('/developer')->assertOk();
        $this->get('/developer/docs')->assertOk()->assertSee('GET /api/v1/account');
    }

    public function test_token_is_hashed_and_only_displayed_once(): void
    {
        $admin = $this->account();
        $this->actingAs($admin)->post('/developer/tokens', [
            'name' => 'Integration test',
            'days' => 30,
            'scopes' => ['account.read'],
            'password' => self::PASSWORD,
        ])->assertRedirect('/developer');

        $token = ApiToken::firstOrFail();
        $secret = session('issued_token');
        $this->assertMatchesRegularExpression('/^bmb_[a-f0-9]{64}$/', $secret);
        $this->assertSame(hash('sha256', $secret), $token->token_hash);
        $this->assertSame(substr($secret, 0, 20), $token->prefix);
        $this->assertNotSame($secret, $token->token_hash);
        $this->assertSame(['account.read'], $token->scopes);

        $this->get('/developer')->assertOk()->assertSee($secret);
        $this->get('/developer')->assertOk()->assertDontSee($secret);
    }

    public function test_real_scoped_account_api_denies_other_scopes(): void
    {
        $admin = $this->account();
        [$token, $secret] = ApiToken::issue($admin, 'Account integration', ['account.read'], 7);

        $response = $this->withToken($secret)->getJson('/api/v1/account');
        $response->assertOk()->assertJsonPath('data.public_id', $admin->public_id)
            ->assertJsonPath('data.email', $admin->email);
        $this->assertNotNull($token->fresh()->last_used_at);

        $this->withToken($secret)->getJson('/api/v1/listings')->assertForbidden();
    }

    public function test_listings_api_returns_only_published_records_and_ten_per_page(): void
    {
        $admin = $this->account();
        $creator = $this->account('freelancer');
        [$token, $secret] = ApiToken::issue($admin, 'Published listings', ['listings.read'], 30);

        for ($i = 0; $i < 12; $i++) {
            MarketplaceListing::query()->create([
                'user_id' => $creator->id,
                'type' => 'service',
                'title' => 'Published service '.$i,
                'description' => str_repeat('Professional service description. ', 2),
                'status' => 'published',
            ]);
        }
        MarketplaceListing::query()->create([
            'user_id' => $creator->id,
            'type' => 'product',
            'title' => 'Private unpublished listing',
            'description' => str_repeat('Unpublished content is private. ', 2),
            'status' => 'pending',
            'license_terms' => 'Licensed use only',
        ]);

        $response = $this->withToken($secret)->getJson('/api/v1/listings');
        $response->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.total', 12)
            ->assertDontSee('Private unpublished listing');
    }

    public function test_revocation_expiration_and_role_change_disable_access(): void
    {
        $admin = $this->account();
        [$token, $secret] = ApiToken::issue($admin, 'Revocable credential', ['account.read'], 7);
        $this->withToken($secret)->getJson('/api/v1/account')->assertOk();

        $this->actingAs($admin)->delete('/developer/tokens/'.$token->id, [
            'password' => self::PASSWORD,
        ])->assertRedirect();
        $this->withToken($secret)->getJson('/api/v1/account')->assertUnauthorized();

        [$expired, $expiredSecret] = ApiToken::issue($admin, 'Expired', ['account.read'], 7);
        $expired->update(['expires_at' => now()->subMinute()]);
        $this->withToken($expiredSecret)->getJson('/api/v1/account')->assertUnauthorized();

        [$demoted, $demotedSecret] = ApiToken::issue($admin, 'Role restricted', ['account.read'], 7);
        $admin->update(['role' => 'customer']);
        $this->withToken($demotedSecret)->getJson('/api/v1/account')->assertUnauthorized();
    }

    public function test_invalid_credentials_and_wrong_user_cannot_revoke(): void
    {
        $first = $this->account();
        $second = $this->account();
        [$token, $secret] = ApiToken::issue($first, 'Private token', ['account.read'], 7);

        $this->getJson('/api/v1/account')->assertUnauthorized();
        $this->withToken('bmb_'.str_repeat('0', 64))->getJson('/api/v1/account')->assertUnauthorized();
        $this->actingAs($second)->delete('/developer/tokens/'.$token->id, [
            'password' => self::PASSWORD,
        ])->assertNotFound();

        $this->actingAs($first)->delete('/developer/tokens/'.$token->id, [
            'password' => 'incorrect',
        ])->assertSessionHasErrors('password');
        $this->assertNull($token->fresh()->revoked_at);
    }
}
