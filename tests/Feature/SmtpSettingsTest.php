<?php

namespace Tests\Feature;

use App\Http\Controllers\CommerceCartController;
use App\Models\MailDeliverySetting;
use App\Models\User;
use App\Support\DashboardMailManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class SmtpSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_smtp_setup_is_limited_to_super_administrators(): void
    {
        $page = route('dashboard.smtp.index');
        $this->get($page)->assertRedirect(route('login'));

        foreach (['customer', 'staff', 'admin'] as $role) {
            $this->actingAs($this->member($role));
            $this->get($page)->assertForbidden();
            $this->put(route('dashboard.smtp.save'), $this->profile())->assertForbidden();
            $this->post(route('dashboard.smtp.test'), ['recipient' => 'qa@example.test'])->assertForbidden();
            $this->post(route('dashboard.smtp.activate'))->assertForbidden();
            $this->delete(route('dashboard.smtp.disable'))->assertForbidden();
        }

        $this->actingAs($this->member('super_admin'));
        $this->get(route('dashboard.system-settings'))->assertOk()->assertSee('Open SMTP Setup');
        $this->get($page)->assertOk()->assertSee('Save draft');
    }

    public function test_saved_credentials_are_encrypted_and_not_reused_for_another_server(): void
    {
        $this->actingAs($this->member('super_admin'));
        $page = route('dashboard.smtp.index');
        $save = route('dashboard.smtp.save');
        $profile = $this->profile();

        $this->from($page)->put($save, [...$profile, 'host' => 'https://smtp.example.test/path'])
            ->assertRedirect($page)->assertSessionHasErrors('host')->assertSessionMissing('_old_input.password');
        $this->from($page)->put($save, $profile)->assertRedirect($page)->assertSessionHasNoErrors();

        $state = MailDeliverySetting::query()->findOrFail(1);
        $revision = $state->draft_revision;
        $this->assertSame($profile['password'], $state->draft['password']);
        $this->assertStringNotContainsString($profile['password'], DB::table('mail_delivery_settings')->where('id', 1)->value('draft'));
        $this->assertArrayNotHasKey('draft', $state->toArray());
        $this->get($page)->assertOk()->assertSee('Password stored')->assertDontSee($profile['password']);

        $this->from($page)->put($save, [...$profile, 'password' => ''])
            ->assertRedirect($page)->assertSessionHasNoErrors();
        $this->assertSame($revision, $state->refresh()->draft_revision);

        $this->from($page)->put($save, [...$profile, 'host' => 'second.example.test', 'password' => ''])
            ->assertRedirect($page)->assertSessionHasErrors('password');
        $this->assertSame($revision, $state->refresh()->draft_revision);
    }

    public function test_successful_test_enables_the_draft_and_routes_checkout_mail_through_it(): void
    {
        config(['mail.default' => 'log']);
        $this->actingAs($this->member('super_admin'));
        $page = route('dashboard.smtp.index');
        $save = route('dashboard.smtp.save');
        $profile = $this->profile();

        $this->assertInstanceOf(DashboardMailManager::class, app('mail.manager'));
        $manager = Mockery::mock(DashboardMailManager::class, [$this->app])->makePartial();
        $transport = new ArrayTransport();
        $manager->shouldReceive('build')->twice()->with(Mockery::on(fn (array $config) =>
            $config['transport'] === 'smtp' && $config['scheme'] === 'smtp'
            && $config['require_tls'] === true && $config['host'] === $profile['host']
            && $config['password'] === $profile['password']
        ))->andReturnUsing(fn (array $config) => new Mailer($config['name'], app('view'), $transport, app('events')));
        $this->app->instance('mail.manager', $manager);

        $this->from($page)->put($save, $profile)->assertRedirect($page);
        $draft = MailDeliverySetting::query()->findOrFail(1)->draft_revision;
        $this->assertSame('log', $manager->getDefaultDriver());
        $this->from($page)->post(route('dashboard.smtp.activate'), ['revision' => $draft])
            ->assertRedirect($page)->assertSessionHasErrors('recipient');
        $this->from($page)->post(route('dashboard.smtp.test'), [
            'recipient' => 'qa@example.test', 'revision' => $draft,
        ])->assertRedirect($page)->assertSessionHasNoErrors();
        $this->assertSame('qa@example.test', $transport->messages()->sole()->getOriginalMessage()->getTo()[0]->getAddress());
        $this->get($page)->assertOk()->assertSee('Enable SMTP');

        $this->from($page)->post(route('dashboard.smtp.activate'), ['revision' => $draft])
            ->assertRedirect($page)->assertSessionHasNoErrors();
        $this->assertSame(DashboardMailManager::DRIVER, $manager->getDefaultDriver());
        $this->assertSame('log', config('mail.default'));

        $buyer = $this->member('customer');
        $this->app->instance('env', 'production');
        try {
            $response = CommerceCartController::verificationEmail($buyer);
            $this->assertSame(route('checkout.verification'), $response->getTargetUrl());
            $this->assertSame('Verification email sent.', session('status'));
        } finally {
            $this->app->instance('env', 'testing');
        }
        $message = $transport->messages()->last()->getOriginalMessage();
        $this->assertSame($buyer->email, $message->getTo()[0]->getAddress());
        $this->assertSame($profile['from_address'], $message->getFrom()[0]->getAddress());
        $this->assertStringContainsString('verify-email', $message->getHtmlBody());
        $this->assertDatabaseHas('audit_events', ['event' => 'mail.smtp.activated']);

        $this->from($page)->put($save, [...$profile, 'from_address' => 'new@example.test', 'password' => ''])
            ->assertRedirect($page)->assertSessionHasNoErrors();
        $state = MailDeliverySetting::query()->findOrFail(1);
        $this->assertSame($profile['from_address'], $state->active['from_address']);
        $this->assertNull($state->tested_at);
        $this->from($page)->post(route('dashboard.smtp.activate'), ['revision' => $draft])
            ->assertRedirect($page)->assertSessionHasErrors('recipient');
        $this->from($page)->delete(route('dashboard.smtp.disable'))->assertRedirect($page);
        $this->assertSame('log', $manager->getDefaultDriver());
    }

    public function test_provider_error_does_not_expose_a_password_or_allow_activation(): void
    {
        $this->actingAs($this->member('super_admin'));
        $page = route('dashboard.smtp.index');
        $profile = $this->profile();
        $this->from($page)->put(route('dashboard.smtp.save'), $profile)->assertRedirect($page);

        $manager = Mockery::mock(DashboardMailManager::class, [$this->app])->makePartial();
        $manager->shouldReceive('build')->once()->andThrow(new TransportException('Provider error '.$profile['password']));
        $this->app->instance('mail.manager', $manager);
        $revision = MailDeliverySetting::query()->findOrFail(1)->draft_revision;

        $this->from($page)->post(route('dashboard.smtp.test'), [
            'recipient' => 'qa@example.test', 'revision' => $revision,
        ])->assertRedirect($page)->assertSessionHasErrors([
            'recipient' => 'SMTP test failed. Check the server, TLS setting, credentials, and sender address.',
        ]);
        $this->get($page)->assertOk()->assertDontSee($profile['password']);
        $this->from($page)->post(route('dashboard.smtp.activate'), ['revision' => $revision])
            ->assertRedirect($page)->assertSessionHasErrors('recipient');
        $this->assertNull(MailDeliverySetting::query()->findOrFail(1)->active);
    }

    private function member(string $role): User
    {
        return User::query()->create([
            'name' => ucfirst($role),
            'email' => Str::random(12).'@example.test',
            'password' => 'ExampleFixturePassword123!',
            'role' => $role,
        ]);
    }

    private function profile(): array
    {
        return [
            'host' => 'smtp.example.test',
            'port' => 587,
            'encryption' => 'starttls',
            'username' => 'mail-user',
            'password' => 'fixture-smtp-secret',
            'from_address' => 'checkout@example.test',
            'from_name' => 'Bambodia',
        ];
    }
}
