<?php

namespace Tests\Feature;

use App\Models\MarketplaceJob;
use App\Models\MarketplaceListing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnifiedBuilderTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $role): User
    {
        return User::query()->create([
            'name' => 'Builder '.$role,
            'email' => uniqid($role, true).'@example.test',
            'password' => 'SecureBuilderPassword123!',
            'role' => $role,
        ]);
    }

    private function jobData(): array
    {
        return [
            'title' => 'Build a secure Laravel marketplace application',
            'description' => str_repeat('Create and test a maintainable Laravel marketplace for real customers. ', 2),
            'category' => 'development',
            'budget' => '1800.00',
        ];
    }

    public function test_builder_requires_login_and_shows_only_authorized_types(): void
    {
        $this->get('/builder')->assertRedirect('/login');

        $this->actingAs($this->member('freelancer'))->get('/builder')
            ->assertOk()->assertSee('Product')->assertSee('Freelance')->assertDontSee('Create job');
        $this->get('/builder?type=product')->assertOk()
            ->assertSee('name="license_definition_id"', false)
            ->assertSee('name="cover_media_id"', false);
        $this->get('/builder?type=job')->assertForbidden();

        $this->actingAs($this->member('customer'))->get('/builder')
            ->assertOk()->assertSee('Job')->assertDontSee('Create product');
        $this->get('/builder?type=job')->assertOk()->assertSee('name="budget"', false);
        $this->get('/builder?type=product')->assertForbidden();

        $this->actingAs($this->member('super_admin'))->get('/builder')
            ->assertOk()->assertSee('Product')->assertSee('Job');
        $this->get('/builder?type=wrong')->assertNotFound();
        $this->get('/builder?=not-a-public-id')->assertNotFound();
    }

    public function test_creation_redirects_to_the_single_builder_public_id_url(): void
    {
        $creator = $this->member('freelancer');
        $this->actingAs($creator)->post('/studio/listings', [
            'builder_mode' => '1',
            'type' => 'service',
            'title' => 'Professional Laravel security review',
            'description' => str_repeat('A professional audit with a complete report and recommendations. ', 2),
        ])->assertRedirect();

        $listing = MarketplaceListing::query()->firstOrFail();
        $this->assertSame('pending', $listing->status);
        $this->get('/builder?='.$listing->public_id)->assertOk()
            ->assertSee('Professional Laravel security review')
            ->assertSee('Public ID:');
        $this->get('/studio/listings')->assertSee('/builder?='.$listing->public_id);

        $this->post('/studio/products', [
            'builder_mode' => '1',
            'title' => 'Original reusable digital interface',
            'description' => str_repeat('Original assets and documented features for a licensed project. ', 2),
            'status' => 'draft',
        ])->assertRedirect();

        $product = MarketplaceListing::query()->where('type', 'product')->firstOrFail();
        $this->assertSame('draft', $product->status);
        $this->get('/builder?='.$product->public_id)->assertOk()->assertSee('name="status"', false);
        $this->get('/studio/products')->assertSee('/builder?='.$product->public_id);
    }

    public function test_job_builder_handles_owner_editing_review_and_locked_publication(): void
    {
        $client = $this->member('customer');
        $this->actingAs($client)->post('/my-jobs', [
            ...$this->jobData(), 'builder_mode'=>'1',
        ])->assertRedirect();
        $job = MarketplaceJob::query()->firstOrFail();
        $this->get('/builder?='.$job->public_id)->assertOk()->assertSee($job->title);
        $this->get('/my-jobs')->assertSee('/builder?='.$job->public_id);

        $this->patch('/builder?='.$job->public_id, [
            ...$this->jobData(),
            'title' => 'An updated and reviewed Laravel marketplace brief',
        ])->assertRedirect('/builder?='.$job->public_id);
        $this->assertSame('pending', $job->fresh()->status);
        $this->assertSame('An updated and reviewed Laravel marketplace brief', $job->fresh()->title);

        $job->update(['status'=>'published']);
        $this->get('/builder?='.$job->public_id)->assertOk()->assertSee('Direct editing is disabled');
        $this->patch('/builder?='.$job->public_id, $this->jobData())->assertStatus(409);

        $stranger = $this->member('customer');
        $this->actingAs($stranger)->get('/builder?='.$job->public_id)->assertNotFound();
        $this->patch('/builder?='.$job->public_id, $this->jobData())->assertNotFound();
    }

    public function test_creator_listing_visibility_is_owner_scoped_and_reviewed_items_are_locked(): void
    {
        $owner = $this->member('freelancer');
        $other = $this->member('freelancer');
        $listing = MarketplaceListing::query()->create([
            'user_id'=>$owner->id, 'type'=>'project',
            'title'=>'Original web application project',
            'description'=>str_repeat('Original independent project with detailed deliverables. ',2),
            'status'=>'published',
        ]);
        $this->actingAs($other)->get('/builder?='.$listing->public_id)->assertNotFound();
        $this->actingAs($owner)->get('/builder?='.$listing->public_id)
            ->assertOk()->assertSee('Direct editing is disabled');
    }
}
