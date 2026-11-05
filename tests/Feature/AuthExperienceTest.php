<?php

namespace Tests\Feature;

use App\Models\Policy;
use App\Models\PolicyAcknowledgement;
use App\Models\PolicyPublication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthExperienceTest extends TestCase
{
    use RefreshDatabase;

    private function terms(string $title='Bambodia Terms of Service'): PolicyPublication
    {
        $admin=User::query()->create([
            'name'=>'Policy Administrator','email'=>uniqid('policy-admin',true).'@example.test',
            'password'=>'LongSecurePassword123!','role'=>'super_admin',
        ]);
        $policy=Policy::query()->create([
            'title'=>$title,'category'=>'terms','status'=>'published',
            'jurisdiction'=>'Cambodia',
            'body'=>str_repeat('The marketplace terms govern lawful account access and product licensing. ',3),
            'reviewed_by'=>$admin->id,'reviewed_at'=>now(),
        ]);
        return PolicyPublication::query()->create([
            'policy_id'=>$policy->id,
            'revision'=>1,
            'title'=>$title,
            'category'=>'terms',
            'jurisdiction'=>'Cambodia',
            'body_snapshot'=>$policy->body,
            'sha256'=>PolicyPublication::digestFor($policy),
            'published_by'=>$admin->id,
            'created_at'=>now(),
        ]);
    }

    private function validRegistration(array $changes=[]): array
    {
        return array_replace([
            'name'=>'New Customer',
            'email'=>'new-customer@example.test',
            'password'=>'LongSecurePassword123!',
            'password_confirmation'=>'LongSecurePassword123!',
        ],$changes);
    }

    public function test_auth_pages_have_no_brand_header_and_share_home_theme_and_svg_flag_menu(): void
    {
        foreach(['/login','/register','/forgot-password'] as $path){
            $html=$this->get($path)->assertOk()
                ->assertSee('auth-tools')
                ->assertSee('auth-home-tool')
                ->assertSee('data-theme-toggle')
                ->assertSee('language-menu')
                ->assertSee('locale-flag')
                ->assertSee('href="'.route('home').'"',false)
                ->getContent();

            $this->assertStringNotContainsString('auth-brand',$html);
            $this->assertStringNotContainsString('Back to Bambodia',$html);
            $this->assertStringNotContainsString('class="brand-mark"',$html);
            $this->assertStringContainsString('name="locale" value="km"',$html);
            $this->assertStringContainsString('name="locale" value="zh"',$html);
        }
        $this->get('/reset-password/test-reset-token?email=example%40example.test')
            ->assertOk()->assertSee('auth-tools')->assertDontSee('auth-brand');
    }

    public function test_registration_shows_unavailable_notice_before_legal_terms_are_published(): void
    {
        $this->get('/register')->assertOk()
            ->assertSee('Terms of Service')
            ->assertSee('Registration unavailable')
            ->assertSee('disabled',false);

        $this->post('/register',$this->validRegistration([
            'terms_accepted'=>'1','terms_publication_id'=>'999',
        ]))->assertSessionHasErrors('terms_accepted');

        $this->assertDatabaseCount('users',0);
        $this->assertDatabaseCount('policy_acknowledgements',0);
    }

    public function test_registration_requires_acceptance_and_checks_specific_published_terms_revision(): void
    {
        $terms=$this->terms();
        $this->get('/register')->assertOk()
            ->assertSee('Terms of Service')
            ->assertSee('terms_accepted')
            ->assertSee('terms_publication_id')
            ->assertSee('published revision 1')
            ->assertSee($terms->policy->publicUrl());

        $withoutConsent=$this->validRegistration(['terms_publication_id'=>$terms->id]);
        $this->post('/register',$withoutConsent)->assertSessionHasErrors('terms_accepted');

        $this->post('/register',$this->validRegistration([
            'terms_accepted'=>'1','terms_publication_id'=>$terms->id+99,
        ]))->assertSessionHasErrors('terms_publication_id');
        $this->assertDatabaseCount('policy_acknowledgements',0);

        $this->post('/register',$this->validRegistration([
            'terms_accepted'=>'1','terms_publication_id'=>$terms->id,
        ]))->assertRedirect();

        $customer=User::query()->where('email','new-customer@example.test')->firstOrFail();
        $this->assertAuthenticatedAs($customer);
        $this->assertSame('customer',$customer->role);
        $this->assertDatabaseHas('policy_acknowledgements',[
            'policy_publication_id'=>$terms->id,
            'user_id'=>$customer->id,
            'sha256'=>$terms->sha256,
            'record_type'=>'registration_acceptance',
        ]);
        $this->assertDatabaseHas('audit_events',[
            'event'=>'auth.registration.terms_accepted',
            'actor_id'=>$customer->id,
        ]);
    }

    public function test_draft_terms_cannot_authorize_account_creation(): void
    {
        $terms=$this->terms();
        $terms->policy->update(['status'=>'draft']);
        $this->get('/register')->assertOk()->assertSee('Registration unavailable');
        $this->post('/register',$this->validRegistration([
            'terms_accepted'=>'1','terms_publication_id'=>$terms->id,
        ]))->assertSessionHasErrors('terms_accepted');
        $this->assertDatabaseCount('policy_acknowledgements',0);
    }

    public function test_flag_selection_uses_existing_locale_route_and_persists_session(): void
    {
        $this->post(route('locale.update'),['locale'=>'km'])->assertRedirect();
        $this->get('/login')->assertOk()
            ->assertSee('lang="km"',false)
            ->assertSee('ខ្មែរ')
            ->assertSee('locale-flag');
        $this->post(route('locale.update'),['locale'=>'xx'])->assertSessionHasErrors('locale');
    }
}
