<?php

namespace Tests\Feature;

use App\Models\CreatorBadge;
use App\Models\CreatorBadgeGrant;
use App\Models\User;
use App\Support\CreatorBadgeAwards;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreatorBadgeSystemTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $role, ?string $email = null): User
    {
        return User::query()->create([
            'name'=>'Badge Test '.$role,
            'email'=>$email ?? uniqid('badge',true).'@example.test',
            'password'=>'AReallyStrongPassword123!',
            'role'=>$role,
        ]);
    }

    public function test_badge_catalog_contains_sixteen_definitions(): void
    {
        $this->assertSame(16,CreatorBadge::query()->count());
    }

    public function test_every_catalog_badge_has_a_local_svg_medal(): void
    {
        foreach (CreatorBadge::query()->get() as $badge) {
            $file = public_path('images/badges/'.$badge->slug.'.svg');
            $this->assertFileExists($file, $badge->slug);
            $this->assertStringContainsString('<svg', file_get_contents($file));
        }
    }

    public function test_new_freelancer_cannot_claim_verification(): void
    {
        $user=$this->account('freelancer');
        $this->assertFalse(CreatorBadgeAwards::earned($user)->contains('slug','identity-verified'));
    }

    public function test_staff_can_grant_manual_badge_but_cannot_edit_definition(): void
    {
        $staff=$this->account('staff');
        $creator=$this->account('freelancer');
        $badge=CreatorBadge::query()->where('slug','skill-certified')->firstOrFail();
        $this->actingAs($staff)->post(route('dashboard.badges.grant',$creator),[
            'badge_id'=>$badge->id,'evidence_note'=>'Assessment verified by staff',
        ])->assertRedirect();
        $this->assertDatabaseHas('creator_badge_grants',['user_id'=>$creator->id,'creator_badge_id'=>$badge->id]);
        $this->actingAs($staff)->patch(route('dashboard.badges.update',$badge),[
            'name'=>'Changed','description'=>'Changed','grant_mode'=>'manual','enabled'=>1,
        ])->assertForbidden();
    }

    public function test_owner_superadmin_receives_all_active_badges(): void
    {
        $owner=$this->account('super_admin',CreatorBadgeAwards::OWNER_EMAIL);
        $this->assertSame(16,CreatorBadgeAwards::earned($owner)->count());
    }

    public function test_auto_mode_rejects_unverified_claims(): void
    {
        $admin=$this->account('admin');
        $badge=CreatorBadge::query()->where('slug','identity-verified')->firstOrFail();
        $this->actingAs($admin)->patch(route('dashboard.badges.update',$badge),[
            'name'=>$badge->name,'description'=>$badge->description,'grant_mode'=>'auto','enabled'=>1,
        ])->assertSessionHasErrors('grant_mode');
    }
}
