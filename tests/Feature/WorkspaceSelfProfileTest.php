<?php

namespace Tests\Feature;

use App\Models\CreatorProfileItem;
use App\Models\CreatorProfileSection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceSelfProfileTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $role): User
    {
        return User::query()->create([
            'name'=>'Profile '.$role, 'username'=>'profile_'.$role,
            'email'=>'profile_'.$role.'@example.test', 'password'=>'TestPassword123!',
            'role'=>$role, 'locale'=>'en',
        ]);
    }

    private function fields(User $member): array
    {
        $data = ['name'=>'Updated '.$member->role,'public_location'=>'Battambang, Cambodia','availability'=>'available'];
        if ($member->isCreator()) {
            $data += ['headline'=>'Professional marketplace creator','biography'=>str_repeat('Professional experience and expertise. ', 4),
                'website_url'=>'https://example.test','is_public'=>false];
        }
        return $data;
    }

    public function test_admin_staff_and_super_admin_use_their_own_administration_profile(): void
    {
        foreach (['admin','staff','super_admin'] as $role) {
            $member = $this->member($role);
            $this->actingAs($member)->get('/dashboard')->assertOk()
                ->assertSee(route('dashboard.profile.edit'), false);
            $page = $this->get('/dashboard/profile')->assertOk()
                ->assertSee('data-workspace="admin"', false)
                ->assertSee('My Profile')
                ->assertSee('action="'.route('dashboard.profile.update').'"', false)
                ->assertSee('data-profile-order-url="'.route('dashboard.profile.sections.order').'"', false)
                ->assertSee('creator-profile-v3.css?v=3', false)
                ->assertSee('creator-profile-v3.js?v=3', false)
                ->assertDontSee('action="'.route('account.profile.update').'"', false);
            $this->assertSame($member->id, $page->original->getData()['member']->id);
            $this->patch('/dashboard/profile', $this->fields($member) + ['role'=>'super_admin'])
                ->assertRedirect(route('dashboard.profile.edit'));
            $this->assertSame('Updated '.$role, $member->fresh()->name);
            $this->assertSame($role, $member->fresh()->role);
            $this->assertSame('Battambang, Cambodia', $member->fresh()->userProfile->public_location);
        }
    }

    public function test_creator_and_account_editors_keep_their_own_forms_and_workspace(): void
    {
        $creator = $this->member('freelancer');
        $this->actingAs($creator)->get('/studio/profile')->assertOk()
            ->assertSee('data-workspace="creator"', false)
            ->assertSee('action="'.route('studio.profile.update').'"', false)
            ->assertSee('action="'.route('studio.profile.sections.store').'"', false)
            ->assertSee('data-profile-order-url="'.route('studio.profile.items.order').'"', false)
            ->assertDontSee('action="'.route('account.profile.items.store').'"', false);
        $this->patch('/studio/profile', $this->fields($creator))->assertRedirect(route('studio.profile.edit'));
        $this->get('/dashboard/profile')->assertForbidden();
        $customer = $this->member('customer');
        $this->actingAs($customer)->get('/account/profile')->assertOk()
            ->assertSee('data-workspace="account"', false)
            ->assertSee('action="'.route('account.profile.update').'"', false)
            ->assertSee('action="'.route('account.profile.sections.store').'"', false);
        $this->get('/dashboard/profile')->assertForbidden();
        $this->patch('/dashboard/profile', $this->fields($customer))->assertForbidden();
        $this->post('/dashboard/profile/sections', ['title'=>'Forbidden'])->assertForbidden();
        $this->get('/studio/profile')->assertForbidden();
    }

    public function test_workspace_section_and_item_mutations_are_owner_scoped(): void
    {
        // Exercise every ownership check independently of shared write-rate limits.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $foreign = $this->member('customer');
        CreatorProfileSection::ensureDefaults($foreign);
        $foreignSection = CreatorProfileSection::query()->where('user_id',$foreign->id)->firstOrFail();
        $foreignItem = CreatorProfileItem::query()->create(['user_id'=>$foreign->id,'kind'=>'expertise','label'=>'Foreign item','is_visible'=>true,'sort_order'=>10]);

        foreach (['dashboard'=>'staff','studio'=>'freelancer'] as $workspace=>$role) {
            $member = $this->member($role);
            $base = '/'.$workspace.'/profile';
            $edit = route($workspace.'.profile.edit');
            $this->actingAs($member)->get($base)->assertOk();
            $this->post($base.'/sections', ['title'=>'Certifications'])->assertRedirect($edit);
            $section = CreatorProfileSection::query()->where('user_id',$member->id)->where('section_key','custom')->sole();
            $this->patch($base.'/sections/'.$section->id, ['title'=>'Qualifications','is_visible'=>true])->assertRedirect($edit);
            $this->post($base.'/items', ['kind'=>'custom','profile_section_id'=>$section->id,'label'=>'First entry','is_visible'=>true])->assertRedirect($edit);
            $item = CreatorProfileItem::query()->where('user_id',$member->id)->sole();
            $this->patch($base.'/items/'.$item->id, ['label'=>'Updated entry','is_visible'=>true])->assertRedirect($edit);
            $this->assertSame('Updated entry',$item->fresh()->label);
            $ids = CreatorProfileSection::query()->where('user_id',$member->id)->orderByDesc('sort_order')->pluck('id')->all();
            $this->patchJson($base.'/sections/order',['ids'=>$ids])->assertOk()->assertJson(['ok'=>true]);
            $this->patchJson($base.'/items/order',['section_id'=>$section->id,'ids'=>[$item->id]])->assertOk();
            $this->patch($base.'/items/'.$foreignItem->id, ['label'=>'Forbidden','is_visible'=>true])->assertNotFound();
            $this->delete($base.'/items/'.$foreignItem->id)->assertNotFound();
            $this->delete($base.'/sections/'.$foreignSection->id)->assertNotFound();
            $this->patchJson($base.'/sections/order',['ids'=>[$foreignSection->id,...array_slice($ids,1)]])->assertUnprocessable();
            $this->delete($base.'/items/'.$item->id)->assertRedirect($edit);
            $this->delete($base.'/sections/'.$section->id)->assertRedirect($edit);
            $this->assertDatabaseMissing('creator_profile_items',['id'=>$item->id]);
            $this->assertDatabaseMissing('creator_profile_sections',['id'=>$section->id]);
        }
        $this->assertSame('Foreign item',$foreignItem->fresh()->label);
        $this->assertTrue($foreignSection->fresh()->is_visible);
    }
}
