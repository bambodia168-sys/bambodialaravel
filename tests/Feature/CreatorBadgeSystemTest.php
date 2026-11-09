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

    public function test_badge_catalog_contains_sixteen_definitions(): void
    {
        $this->assertSame(16,CreatorBadge::query()->count());
    }

    public function test_new_freelancer_cannot_claim_verification(): void
    {
        $user=User::factory()->create(['role'=>'freelancer']);
        $this->assertFalse(CreatorBadgeAwards::earned($user)->contains('slug','identity-verified'));
    }

    public function test_staff_can_grant_manual_badge_but_cannot_edit_definition(): void
    {
        $staff=User::factory()->create(['role'=>'staff']);
        $creator=User::factory()->create(['role'=>'freelancer']);
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
        $owner=User::factory()->create(['role'=>'super_admin','email'=>CreatorBadgeAwards::OWNER_EMAIL]);
        $this->assertSame(16,CreatorBadgeAwards::earned($owner)->count());
    }

    public function test_auto_mode_rejects_unverified_claims(): void
    {
        $admin=User::factory()->create(['role'=>'admin']);
        $badge=CreatorBadge::query()->where('slug','identity-verified')->firstOrFail();
        $this->actingAs($admin)->patch(route('dashboard.badges.update',$badge),[
            'name'=>$badge->name,'description'=>$badge->description,'grant_mode'=>'auto','enabled'=>1,
        ])->assertSessionHasErrors('grant_mode');
    }
}
