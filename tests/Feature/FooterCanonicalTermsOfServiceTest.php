<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Policy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class FooterCanonicalTermsOfServiceTest extends TestCase
{
    use RefreshDatabase;

    private function policy(string $title, string $category, string $code, string $status = 'published'): Policy
    {
        return Policy::query()->create([
            'title' => $title,
            'category' => $category,
            'body' => 'Publication for the footer regression test.',
            'status' => $status,
            'public_code' => $code,
            'published_at' => now(),
        ]);
    }

    public function test_footer_links_to_actual_terms_not_newer_reviews_policy_in_same_category(): void
    {
        $terms = $this->policy('Terms of Service', 'terms', '91204896');
        $reviews = $this->policy('Reviews and Ratings Integrity Policy', 'terms', '14223589');
        // Make the unrelated document explicitly more recently edited and published.
        $reviews->forceFill([
            'published_at' => now()->addDay(),
            'updated_at' => now()->addDay(),
        ])->save();

        $response = $this->get('/')->assertOk();
        $response->assertSee('href="'.$terms->publicUrl().'">Terms of Service</a>', false);
        $response->assertDontSee('href="'.$reviews->publicUrl().'">Terms of Service</a>', false);

        $this->assertSame('terms-of-service', $terms->slug);
        $this->assertSame('reviews-and-ratings-integrity-policy', $reviews->slug);
        $this->assertSame('91204896', $terms->public_code);
    }

    public function test_footer_does_not_link_to_an_unpublished_terms_of_service(): void
    {
        $terms = $this->policy('Terms of Service', 'terms', '91204896', 'draft');
        $this->policy('Reviews and Ratings Integrity Policy', 'terms', '14223589');
        $response = $this->get('/')->assertOk();
        $response->assertDontSee('href="'.$terms->publicUrl().'">Terms of Service</a>', false);
        $response->assertDontSee('>Terms of Service</a>', false);
    }

    public function test_unrelated_published_policy_categories_remain_available(): void
    {
        $cookies = $this->policy('Cookie Policy', 'cookies', '14567891');
        $privacy = $this->policy('Privacy Policy', 'privacy', '14567892');
        $this->policy('Terms of Service', 'terms', '91204896');

        $this->get('/')->assertOk()
            ->assertSee('href="'.$cookies->publicUrl().'">Cookie Policy</a>', false)
            ->assertSee('href="'.$privacy->publicUrl().'">Privacy Policy</a>', false)
            ->assertSee('>Terms of Service</a>', false)
            ->assertSee('>Policy Center</a>', false);
    }
}
