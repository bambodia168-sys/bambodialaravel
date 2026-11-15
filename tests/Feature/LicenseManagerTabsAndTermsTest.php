<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\LicenseDefinition;
use App\Models\MarketplaceListing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LicenseManagerTabsAndTermsTest extends TestCase
{
    use RefreshDatabase;

    public function test_tabs_load_only_their_own_section(): void
    {
        $this->actingAs($this->member('admin'));
        $this->get('/dashboard/license-manager')->assertOk()
            ->assertSeeInOrder(['Product Licenses', 'Support Renewals', 'License Definitions'])
            ->assertSee('id="issued-product-licenses"', false)
            ->assertDontSee('id="support-renewal-requests"', false)
            ->assertDontSee('id="license-create"', false);
        $this->get('/dashboard/license-manager?tab=renewals')->assertOk()
            ->assertSee('id="support-renewal-requests"', false)
            ->assertDontSee('id="issued-product-licenses"', false);
        $this->get('/dashboard/license-manager?tab=definitions')->assertOk()
            ->assertSee('id="license-create"', false)
            ->assertDontSee('id="support-renewal-requests"', false);
    }

    public function test_formatted_terms_round_trip_and_unused_definitions_can_be_removed(): void
    {
        $admin = $this->member('admin');
        $this->actingAs($admin);
        $data = [
            'code' => 'EDITABLE_TEST',
            'tier' => 'regular',
            'name' => 'Editable license definition',
            'version' => '1.0.0',
            'status' => 'active',
            'confirm_review' => '1',
            'permissions_html' => '<p onclick="alert(1)">Licenses apply to <strong>one end product</strong>.</p><p>A second paragraph describes the conditions.</p>',
            'restrictions_html' => '<ul><li>Redistribution of original files is prohibited.</li></ul><script>window.bad = true</script>',
        ];
        $this->post(route('dashboard.licenses.store'), $data)
            ->assertRedirect(route('dashboard.licenses.index', ['tab' => 'definitions']));
        $license = LicenseDefinition::query()->where('code', 'EDITABLE_TEST')->sole();
        $this->assertStringContainsString('one end product', $license->permissions);
        $this->assertStringNotContainsString('onclick', $license->permissions_html);
        $this->assertStringNotContainsString('window.bad', $license->restrictions_html);
        $this->get($license->publicUrl())->assertOk()
            ->assertSee('<strong>one end product</strong>', false)
            ->assertSee('<li>Redistribution of original files is prohibited.</li>', false);
        $this->patch(route('dashboard.licenses.update', $license), [
            ...$data, 'version' => '1.0.1',
            'permissions_html' => '<p><em>Updated terms</em> apply to one registered domain.</p>',
        ])->assertRedirect();
        $this->get($license->publicUrl())->assertOk()->assertSee('<em>Updated terms</em>', false);
        $remove = route('dashboard.licenses.destroy', $license);
        $this->actingAs($this->member('freelancer'))->delete($remove, ['confirm_remove' => '1'])->assertForbidden();
        $this->actingAs($admin)->delete($remove)->assertSessionHasErrors('confirm_remove');
        $this->delete($remove, ['confirm_remove' => '1'])->assertRedirect();
        $this->assertModelMissing($license);
        $this->assertTrue(AuditEvent::query()->where('event', 'license.definition.deleted')->exists());
    }

    public function test_only_verified_layout_fixture_can_be_archived_with_listings_attached(): void
    {
        $admin = $this->member('super_admin');
        $owner = $this->member('freelancer', 'layout-preview-creator@bambodia.invalid');
        $license = LicenseDefinition::query()->create([
            'name' => 'Layout Test Only — No Commercial License',
            'code' => 'LAYOUT_TEST_ONLY',
            'tier' => 'regular',
            'version' => '1.0.0',
            'status' => 'active',
            'created_by' => $admin->id,
            'permissions' => 'This preview is only available for layout testing.',
            'restrictions' => 'No purchase, download or commercial rights are granted.',
        ]);
        $product = MarketplaceListing::query()->create([
            'type' => 'product',
            'user_id' => $owner->id,
            'title' => 'Real product',
            'description' => 'A product with a referenced definition.',
            'license_definition_id' => $license->id,
            'status' => 'published',
        ]);
        $this->actingAs($admin);
        $url = route('dashboard.licenses.destroy', $license);
        $this->delete($url, ['confirm_remove' => '1'])->assertConflict();
        $product->update(['title' => '[LAYOUT TEST] Fixture product']);
        $this->get('/dashboard/license-manager?tab=definitions')->assertSee('Archive layout-test definition');
        $this->delete($url, ['confirm_remove' => '1'])->assertRedirect();
        $this->assertSame('archived', $license->fresh()->status);
        $this->assertSame($license->id, $product->fresh()->license_definition_id);
        $this->get($license->publicUrl())->assertNotFound();
        $this->get('/dashboard/license-manager?tab=definitions')->assertDontSee($license->name);
        $this->get('/dashboard/license-manager?tab=definitions&archived=1')->assertSee($license->name);
        $this->assertTrue(AuditEvent::query()->where('event', 'license.definition.archived')->exists());
    }

    private function member(string $role, ?string $email = null): User
    {
        return User::query()->create([
            'name' => ucfirst($role),
            'email' => $email ?? uniqid($role).'@example.test',
            'password' => 'IsolatedLicenseTestPassword42!',
            'role' => $role,
        ]);
    }
}
