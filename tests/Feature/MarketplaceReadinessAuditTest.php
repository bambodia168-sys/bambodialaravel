<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\KnowledgeArticle;
use App\Models\MarketplaceJob;
use App\Models\MarketplaceListing;
use App\Models\User;
use App\Support\MarketplaceReadinessAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class MarketplaceReadinessAuditTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'super_admin'): User
    {
        return User::query()->create([
            'name' => 'Readiness Auditor',
            'email' => uniqid('readiness_', true).'@example.test',
            'password' => 'TestOnlyRandomPassword2026!',
            'role' => $role,
        ]);
    }

    private function listing(User $creator, string $title, string $status): MarketplaceListing
    {
        return MarketplaceListing::query()->create([
            'user_id' => $creator->id,
            'type' => 'service',
            'title' => $title,
            'description' => 'A real description for a marketplace listing that requires editorial review.',
            'status' => $status,
        ]);
    }

    private function job(User $client, string $title, string $status): MarketplaceJob
    {
        return MarketplaceJob::query()->create([
            'client_id' => $client->id,
            'title' => $title,
            'description' => 'A professional client job brief with a complete scope and deliverables.',
            'category' => 'development',
            'budget' => '250.00',
            'status' => $status,
        ]);
    }

    private function knowledge(User $owner, string $status): KnowledgeArticle
    {
        return KnowledgeArticle::query()->create([
            'title' => 'A reviewed guide for marketplace creators',
            'excerpt' => 'How to prepare work for marketplace publication and understand the available tools.',
            'category' => 'creators',
            'body_format' => 'markdown',
            'body' => 'Creators should review their work and avoid sharing private credentials.',
            'status' => $status,
            'author_id' => $owner->id,
            'published_at' => $status === 'published' ? now() : null,
        ]);
    }

    public function test_read_only_json_reports_only_published_preview_markers(): void
    {
        $creator = $this->user('freelancer');
        $client = $this->user('customer');
        $public = $this->listing($creator, '[LAYOUT TEST] Public sample layout', 'published');
        $this->listing($creator, '[LAYOUT TEST] Private creator draft', 'draft');
        $this->listing($creator, 'Reviewed professional listing', 'published');
        $job = $this->job($client, '[SAMPLE] Preview only frontend job', 'published');
        $this->job($client, '[DEMO] Confidential customer job', 'pending');
        $this->knowledge($creator, 'published');

        $before = [
            'listings' => MarketplaceListing::query()->count(),
            'jobs' => MarketplaceJob::query()->count(),
            'knowledge' => KnowledgeArticle::query()->count(),
            'audits' => AuditEvent::query()->count(),
        ];

        $this->assertSame(2, Artisan::call('marketplace:readiness-audit', [
            '--json' => true, '--strict' => true,
        ]));
        $raw = Artisan::output();
        $report = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('review_required', $report['status']);
        $this->assertTrue($report['read_only']);
        $this->assertSame(2, $report['counts']['public_listings']);
        $this->assertSame(1, $report['counts']['published_jobs']);
        $this->assertSame(1, $report['counts']['published_knowledge_articles']);
        $this->assertSame(['public_listings' => 1, 'published_jobs' => 1], $report['preview_markers']);
        $this->assertSame([$public->public_id, $job->public_id], array_column($report['samples'], 'public_id'));
        $this->assertFalse($report['sample_truncated']);
        $this->assertSame([], $report['notices']);
        $this->assertStringNotContainsString('Private creator draft', $raw);
        $this->assertStringNotContainsString('Confidential customer job', $raw);
        $this->assertStringNotContainsString('@example.test', $raw);

        $this->assertSame($before, [
            'listings' => MarketplaceListing::query()->count(),
            'jobs' => MarketplaceJob::query()->count(),
            'knowledge' => KnowledgeArticle::query()->count(),
            'audits' => AuditEvent::query()->count(),
        ]);
        $this->assertSame('published', $public->fresh()->status);
        $this->assertSame('published', $job->fresh()->status);
    }

    public function test_no_published_help_articles_is_review_notice_without_automatic_publication(): void
    {
        $owner = $this->user();
        $this->knowledge($owner, 'draft');

        $this->assertSame(2, Artisan::call('marketplace:readiness-audit', [
            '--strict' => true, '--json' => true,
        ]));
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('review_required', $report['status']);
        $this->assertSame(0, $report['counts']['published_knowledge_articles']);
        $this->assertSame(1, $report['counts']['draft_knowledge_articles']);
        $this->assertSame('knowledge_base_unpublished', $report['notices'][0]['code']);
        $this->assertSame('draft', KnowledgeArticle::query()->firstOrFail()->status);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_clean_public_inventory_passes_and_is_repeatable(): void
    {
        $creator = $this->user('freelancer');
        $client = $this->user('customer');
        $this->listing($creator, 'Professional responsive interface design', 'published');
        $this->job($client, 'Long-term Laravel developer opportunity', 'published');
        $this->knowledge($creator, 'published');

        $this->assertSame(0, Artisan::call('marketplace:readiness-audit', [
            '--json' => true, '--strict' => true,
        ]));
        $first = Artisan::output();

        $this->assertSame(0, Artisan::call('marketplace:readiness-audit', [
            '--json' => true, '--strict' => true,
        ]));
        $second = Artisan::output();

        $this->assertSame(
            json_decode($first, true, flags: JSON_THROW_ON_ERROR),
            json_decode($second, true, flags: JSON_THROW_ON_ERROR)
        );
        $this->assertSame('pass', MarketplaceReadinessAudit::inspect()['status']);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_sample_is_bounded_but_total_marker_count_is_not(): void
    {
        $owner = $this->user('freelancer');
        for ($i = 1; $i <= 28; $i++) {
            $this->listing($owner, '[LAYOUT TEST] Published sample number '.$i, 'published');
        }
        $this->knowledge($owner, 'published');

        $report = MarketplaceReadinessAudit::inspect();
        $this->assertSame(28, $report['preview_markers']['public_listings']);
        $this->assertCount(25, $report['samples']);
        $this->assertTrue($report['sample_truncated']);
        $this->assertSame('review_required', $report['status']);
    }
}
