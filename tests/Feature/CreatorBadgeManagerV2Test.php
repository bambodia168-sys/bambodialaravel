<?php

namespace Tests\Feature;

use App\Models\CreatorBadge;
use App\Models\CreatorBadgeGrant;
use App\Models\User;
use App\Support\CreatorBadgeAwards;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CreatorBadgeManagerV2Test extends TestCase
{
    use RefreshDatabase;

    private function account(string $role='freelancer',string $name='Award recipient'): User
    {
        return User::query()->create([
            'name'=>$name, 'role'=>$role,
            'email'=>uniqid('badge-qa-',true).'@example.test',
            'password'=>'StrongExamplePassword123!',
        ]);
    }

    private function owner(): User
    {
        return User::query()->create([
            'name'=>'Bamboo Owner', 'role'=>'super_admin',
            'email'=>CreatorBadgeAwards::OWNER_EMAIL,
            'password'=>'StrongExamplePassword123!',
        ]);
    }

    private function bulk(string $confirmation): array
    {
        return [
            'confirmation'=>$confirmation,
            'attest_evidence'=>'1',
            'evidence_note'=>'Complete documented independent assessment for every listed badge.',
        ];
    }

    public function test_all_users_have_ten_per_page_search_filters_and_go_to(): void
    {
        $owner=$this->owner();
        foreach(range(1,24) as $i){
            $this->account($i%2?'customer':'freelancer','Page QA '.str_pad((string)$i,2,'0',STR_PAD_LEFT));
        }
        $this->actingAs($owner)->get(route('dashboard.badges.index'))
            ->assertOk()->assertSee('10 accounts per page')
            ->assertSee('Go To')->assertSee('Previous')->assertSee('Next')
            ->assertSee('Manage badges')->assertSee('Badge catalog');
        $page=User::query()->orderByDesc('id')->skip(10)->firstOrFail();
        $this->get(route('dashboard.badges.index',['page'=>2]))
            ->assertOk()->assertSee($page->name)->assertSee('2 / 3');
        $this->get(route('dashboard.badges.index',['search'=>'Page QA 21','role'=>'customer']))
            ->assertOk()->assertSee('Page QA 21')->assertDontSee('Page QA 22');
    }

    public function test_any_active_role_can_receive_all_but_inactive_accounts_cannot(): void
    {
        $owner=$this->owner();
        $customer=$this->account('customer','Approved customer');
        $this->actingAs($owner)->post(route('dashboard.badges.grant-all',$customer),
            $this->bulk('GRANT ALL'))->assertRedirect();
        $this->assertSame(16,CreatorBadgeGrant::query()->where('user_id',$customer->id)->count());
        $this->assertSame(16,CreatorBadgeAwards::earned($customer)->count());
        $this->assertDatabaseHas('creator_badge_actions',[
            'user_id'=>$customer->id,'action'=>'grant_all','affected_count'=>16,
        ]);
        $customer->update(['account_status'=>'archived']);
        $another=$this->post(route('dashboard.badges.grant-all',$customer),$this->bulk('GRANT ALL'));
        $another->assertStatus(422);
        $this->assertDatabaseCount('creator_badge_actions',1);
    }

    public function test_superadmin_global_all_grant_is_explicit_audited_and_only_for_active_users(): void
    {
        $owner=$this->owner();
        $customer=$this->account('customer','Recipient C');
        $staff=$this->account('staff','Recipient S');
        $inactive=$this->account('freelancer','Inactive F');
        $inactive->update(['account_status'=>'archived']);
        $this->actingAs($owner)->post(route('dashboard.badges.grant-everyone'),$this->bulk('GRANT EVERY USER'))
            ->assertRedirect();
        foreach([$owner,$customer,$staff] as $user){
            $this->assertSame(16,CreatorBadgeGrant::query()->where('user_id',$user->id)->count());
        }
        $this->assertSame(0,CreatorBadgeGrant::query()->where('user_id',$inactive->id)->count());
        $this->assertDatabaseHas('creator_badge_actions',[
            'scope'=>'all_active','action'=>'grant_all','affected_count'=>3,
        ]);
        $this->actingAs($this->account('admin'))->post(route('dashboard.badges.grant-everyone'),$this->bulk('GRANT EVERY USER'))
            ->assertForbidden();
    }

    public function test_bulk_grants_require_explicit_evidence_and_confirmation(): void
    {
        $owner=$this->owner();
        $recipient=$this->account();
        $this->actingAs($owner)->post(route('dashboard.badges.grant-all',$recipient),[
            'confirmation'=>'GRANT ALL','evidence_note'=>'unsupported','attest_evidence'=>'0',
        ])->assertSessionHasErrors(['attest_evidence','evidence_note']);
        $this->assertDatabaseCount('creator_badge_grants',0);
        $this->post(route('dashboard.badges.grant-everyone'),[
            'confirmation'=>'YES','evidence_note'=>'Unsupported claim about real-world identification.',
            'attest_evidence'=>'1',
        ])->assertSessionHasErrors('confirmation');
    }

    public function test_grant_update_evidence_and_revoke_manual_badge(): void
    {
        $owner=$this->owner();
        $recipient=$this->account('freelancer');
        $badge=CreatorBadge::query()->where('slug','expert-vetted')->firstOrFail();
        $this->actingAs($owner)->post(route('dashboard.badges.grant',$recipient),[
            'badge_id'=>$badge->id,'evidence_note'=>'Documented portfolio and expertise review.',
        ])->assertRedirect();
        $this->post(route('dashboard.badges.grant',$recipient),[
            'badge_id'=>$badge->id,'evidence_note'=>'Updated verified portfolio assessment file.',
        ])->assertRedirect();
        $this->assertSame('Updated verified portfolio assessment file.',
            CreatorBadgeGrant::query()->where('user_id',$recipient->id)->where('creator_badge_id',$badge->id)->firstOrFail()->evidence_note);
        $this->delete(route('dashboard.badges.revoke',[$recipient,$badge]))->assertRedirect();
        $this->assertSame(0,CreatorBadgeGrant::query()->where('user_id',$recipient->id)->count());
        $this->assertDatabaseHas('creator_badge_actions',['action'=>'revoke','user_id'=>$recipient->id]);
    }

    public function test_revoke_all_preserves_automatic_awards_and_owner_policy(): void
    {
        $owner=$this->owner();
        $recipient=$this->account();
        $this->actingAs($owner)->post(route('dashboard.badges.grant-all',$recipient),$this->bulk('GRANT ALL'))
            ->assertRedirect();
        $this->delete(route('dashboard.badges.revoke-all',$recipient),[
            'confirmation'=>'REVOKE ALL','reason'=>'Withdraw prior internal bulk grant pending full review.',
        ])->assertRedirect();
        $this->assertSame(0,CreatorBadgeGrant::query()->where('user_id',$recipient->id)->count());
        $this->assertDatabaseHas('creator_badge_actions',['action'=>'revoke_all','user_id'=>$recipient->id]);
        $this->assertSame(16,CreatorBadgeAwards::earned($owner)->count());
        $this->delete(route('dashboard.badges.revoke-all',$owner),[
            'confirmation'=>'REVOKE ALL','reason'=>'Owner catalog should remain permanently assigned.',
        ])->assertStatus(422);
    }

    public function test_badge_appearance_can_be_changed_and_custom_badge_created_updated_deleted(): void
    {
        $owner=$this->owner();
        $this->actingAs($owner)->get(route('dashboard.badges.settings'))
            ->assertOk()->assertSee('Badge + Name')->assertSee('Add custom badge');
        $this->patch(route('dashboard.badges.display.update'),[
            'display_mode'=>'badge','badge_size'=>'large','tooltips_enabled'=>'0',
        ])->assertRedirect();
        $this->assertDatabaseHas('creator_badge_settings',[
            'id'=>1,'display_mode'=>'badge','badge_size'=>'large','tooltips_enabled'=>0,
        ]);
        $this->post(route('dashboard.badges.store'),[
            'slug'=>'trusted-partner','name'=>'Trusted Partner',
            'description'=>'Recognizes outstanding documented contribution to verified client projects.',
            'tone'=>'green','icon'=>'handshake','sort_order'=>42,
        ])->assertRedirect();
        $badge=CreatorBadge::query()->where('slug','trusted-partner')->firstOrFail();
        $this->patch(route('dashboard.badges.update',$badge),[
            'name'=>'Trusted Professional','description'=>'Based on validated independent assessment.',
            'tone'=>'blue','icon'=>'award','sort_order'=>40,'grant_mode'=>'manual','enabled'=>1,
        ])->assertRedirect();
        $this->assertSame('Trusted Professional',$badge->fresh()->name);
        $this->delete(route('dashboard.badges.destroy',$badge),['confirmation'=>'DELETE'])->assertRedirect();
        $this->assertDatabaseMissing('creator_badges',['slug'=>'trusted-partner']);
    }

    public function test_builtin_badges_are_not_deletable_and_unsafe_auto_rules_blocked(): void
    {
        $admin=$this->account('admin');
        $badge=CreatorBadge::query()->where('slug','identity-verified')->firstOrFail();
        $this->actingAs($admin)->delete(route('dashboard.badges.destroy',$badge),[
            'confirmation'=>'DELETE',
        ])->assertSessionHasErrors('badge');
        $this->patch(route('dashboard.badges.update',$badge),[
            'name'=>$badge->name,'description'=>$badge->description,
            'grant_mode'=>'auto','enabled'=>1,'icon'=>'badge-check','tone'=>'blue','sort_order'=>5,
        ])->assertSessionHasErrors('grant_mode');
    }

    public function test_staff_can_manage_single_grants_but_cannot_modify_settings_or_global_grant(): void
    {
        $staff=$this->account('staff');
        $recipient=$this->account('customer');
        $badge=CreatorBadge::query()->where('slug','skill-certified')->firstOrFail();
        $this->actingAs($staff)->get(route('dashboard.badges.index'))->assertOk();
        $this->get(route('dashboard.badges.settings'))->assertForbidden();
        $this->post(route('dashboard.badges.grant',$recipient),[
            'badge_id'=>$badge->id,'evidence_note'=>'Independently verified skills certificate.',
        ])->assertRedirect();
        $this->post(route('dashboard.badges.grant-everyone'),$this->bulk('GRANT EVERY USER'))
            ->assertForbidden();
    }

    public function test_original_badge_catalog_is_first_and_user_table_is_second(): void
    {
        $owner=$this->owner();
        $this->account('freelancer','Test Creator Account');
        $response=$this->actingAs($owner)->get(route('dashboard.badges.index'));
        $response->assertOk()->assertSee('Badge catalog')
            ->assertSee('Grant badges to creators')
            ->assertSee('Automatic eligibility conditions')
            ->assertSee('Eligibility / manual review conditions')
            ->assertSee('Save badge')->assertSee('Manage badges')
            ->assertSee('10 accounts per page')->assertSee('Go To');
        $html=$response->getContent();
        $this->assertLessThan(
            strpos($html,'Grant badges to creators'),
            strpos($html,'id="badge-catalog"')
        );
        $this->assertSame(16,substr_count($html,'data-badge-card="'));
        $this->assertSame(16,CreatorBadge::query()->count());
        // Original 16 medals stay in the first section, not only on a separate Settings page.
        $this->assertStringContainsString('images/badges/top-rated.svg',$html);
        $this->assertStringContainsString('images/badges/vip-client-favorite.svg',$html);
    }

    public function test_auto_thresholds_are_configurable_without_changing_all_other_badges(): void
    {
        $owner=$this->owner();
        $badge=CreatorBadge::query()->where('slug','rising-talent')->firstOrFail();
        $this->assertEquals([
            'min_completed'=>1,'max_completed'=>5,'max_account_age_days'=>365,
        ],$badge->auto_rules);
        $this->actingAs($owner)->patch(route('dashboard.badges.update',$badge),[
            'name'=>$badge->name,'description'=>$badge->description,
            'eligibility_criteria'=>'One or more verifiable contracts, within approved early career limits.',
            'grant_mode'=>'auto','enabled'=>1,
            'auto_rules'=>['min_completed'=>2,'max_completed'=>6,'max_account_age_days'=>540],
        ])->assertRedirect();
        $badge->refresh();
        $this->assertEquals([
            'min_completed'=>2,'max_completed'=>6,'max_account_age_days'=>540,
        ],$badge->auto_rules);
        $this->assertStringContainsString('verifiable contracts',$badge->eligibility_criteria);
        $this->patch(route('dashboard.badges.update',$badge),[
            'name'=>$badge->name,'description'=>$badge->description,
            'grant_mode'=>'auto','enabled'=>1,
            'auto_rules'=>['min_completed'=>9,'max_completed'=>5,'max_account_age_days'=>365],
        ])->assertSessionHasErrors('auto_rules');
        $this->assertSame(2,$badge->fresh()->auto_rules['min_completed']);
    }

    public function test_manual_badge_has_editable_criteria_but_cannot_claim_unsupported_auto_awards(): void
    {
        $owner=$this->owner();
        $badge=CreatorBadge::query()->where('slug','identity-verified')->firstOrFail();
        $this->actingAs($owner)->patch(route('dashboard.badges.update',$badge),[
            'name'=>$badge->name,'description'=>$badge->description,
            'eligibility_criteria'=>'Admin must check government ID through an approved secure review.',
            'grant_mode'=>'manual','enabled'=>0,
        ])->assertRedirect();
        $this->assertFalse($badge->fresh()->enabled);
        $this->assertSame('Admin must check government ID through an approved secure review.',
            $badge->fresh()->eligibility_criteria);
        $this->patch(route('dashboard.badges.update',$badge),[
            'name'=>$badge->name,'description'=>$badge->description,
            'grant_mode'=>'auto','enabled'=>1,
        ])->assertSessionHasErrors('grant_mode');
        $this->assertSame('manual',$badge->fresh()->grant_mode);
    }

    public function test_staff_can_read_the_restored_cards_but_not_edit_badge_conditions(): void
    {
        $staff=$this->account('staff');
        $response=$this->actingAs($staff)->get(route('dashboard.badges.index'));
        $response->assertOk()->assertSee('Badge catalog')
            ->assertSee('Grant badges to creators')
            ->assertDontSee('Eligibility / manual review conditions');
        $this->assertSame(16,substr_count($response->getContent(),'data-badge-card="'));
        $badge=CreatorBadge::query()->where('slug','repeat-clients')->firstOrFail();
        $this->patch(route('dashboard.badges.update',$badge),[
            'name'=>$badge->name,'description'=>$badge->description,
            'grant_mode'=>'auto','enabled'=>1,
            'auto_rules'=>['min_repeat_contracts'=>3],
        ])->assertForbidden();
    }


    public function test_gallery_cards_have_separate_three_dot_dialogs_and_no_inline_editing_forms(): void
    {
        $owner=$this->owner();
        $creator=$this->account('freelancer','Creator Table Regression');
        $response=$this->actingAs($owner)->get(route('dashboard.badges.index'));
        $response->assertOk()->assertSee('Grant badges to creators')
            ->assertSee('Manage badges')->assertSee('10 accounts per page')
            ->assertSee('Go To')->assertSee('Badge preview')
            ->assertSee('Duplicate as custom badge');
        $markup=$response->getContent();
        $catalogStart=strpos($markup,'id="badge-catalog"');
        $tableAt=strpos($markup,'class="data-table badge-manager-table"');
        $this->assertNotFalse($catalogStart);
        $this->assertNotFalse($tableAt);
        $this->assertLessThan($tableAt,$catalogStart);
        $this->assertSame(16,substr_count($markup,'data-badge-card="'));
        $this->assertSame(16,substr_count($markup,'class="badge-catalog-action-trigger"'));
        $this->assertSame(16,substr_count($markup,'class="config-dialog badge-manager-dialog badge-catalog-editor-dialog"'));
        $this->assertStringContainsString('badge-catalog-gallery-grid',$markup);
        $this->assertStringContainsString('badge-catalog-gallery-art',$markup);
        $this->assertStringContainsString('badge-catalog-gallery-name',$markup);
        // No action form is rendered inside a gallery card, only inside dialogs.
        $firstCard=substr($markup,strpos($markup,'data-badge-card="'),
            strpos($markup,'</article>',strpos($markup,'data-badge-card="'))-strpos($markup,'data-badge-card="'));
        $this->assertStringNotContainsString('<form',$firstCard);
        $this->assertStringContainsString('data-badge-dialog-open="badge-editor-',$firstCard);
        $this->assertStringContainsString('Creator Table Regression',$markup);
    }

    public function test_badge_duplication_creates_manual_only_custom_record_without_copying_grants(): void
    {
        $owner=$this->owner();
        $recipient=$this->account('freelancer');
        $original=CreatorBadge::query()->where('slug','rising-talent')->firstOrFail();
        $this->actingAs($owner)->post(route('dashboard.badges.grant',$recipient),[
            'badge_id'=>$original->id,'evidence_note'=>'Independent portfolio assessment reference for test award',
        ])->assertRedirect();
        $this->post(route('dashboard.badges.store'),[
            'slug'=>'rising-talent-custom',
            'name'=>'Copy of Rising Talent',
            'description'=>$original->description,
            'eligibility_criteria'=>$original->eligibility_criteria??$original->description,
            'icon'=>$original->icon,'tone'=>$original->tone,
            'sort_order'=>min(999,$original->sort_order+1),
        ])->assertRedirect();
        $copy=CreatorBadge::query()->where('slug','rising-talent-custom')->firstOrFail();
        $this->assertSame('manual',$copy->grant_mode);
        $this->assertTrue($copy->enabled);
        $this->assertSame(0,CreatorBadgeGrant::query()->where('creator_badge_id',$copy->id)->count());
        $this->assertSame(1,CreatorBadgeGrant::query()->where('creator_badge_id',$original->id)->count());
        $this->delete(route('dashboard.badges.destroy',$original),['confirmation'=>'DELETE'])
            ->assertSessionHasErrors('badge');
        $this->delete(route('dashboard.badges.destroy',$copy),['confirmation'=>'DELETE'])
            ->assertRedirect();
    }

    public function test_staff_can_see_medal_gallery_but_cannot_open_definition_edit_dialogs(): void
    {
        $staff=$this->account('staff');
        $response=$this->actingAs($staff)->get(route('dashboard.badges.index'));
        $response->assertOk()->assertSee('Badge catalog')
            ->assertSee('Grant badges to creators');
        $this->assertSame(16,substr_count($response->getContent(),'data-badge-card="'));
        $this->assertStringNotContainsString('badge-catalog-action-trigger',$response->getContent());
        $this->assertStringNotContainsString('badge-catalog-editor-dialog',$response->getContent());
    }

}
