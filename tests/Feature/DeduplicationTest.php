<?php

namespace Tests\Feature;

use App\Enums\SourceType;
use App\Models\JobPosting;
use App\Services\Jobs\JobUpserter;
use App\Services\Jobs\NormalizedJob;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\JobPilotHelpers;
use Tests\TestCase;

class DeduplicationTest extends TestCase
{
    use JobPilotHelpers, RefreshDatabase;

    private function n(array $over = []): NormalizedJob
    {
        return new NormalizedJob(...array_merge([
            'title' => 'PHP Developer', 'url' => 'https://example.com/jobs/1', 'externalId' => 'ext-1', 'company' => 'Acme',
            'description' => 'Laravel and MySQL work, fully remote.', 'location' => 'Remote',
        ], $over));
    }

    public function test_same_source_and_external_id_never_creates_a_second_row(): void
    {
        $s = $this->source();
        $u = app(JobUpserter::class);

        [$a, $o1] = $u->upsert($s, $this->n());
        [$b, $o2] = $u->upsert($s, $this->n());

        $this->assertSame(JobUpserter::CREATED, $o1);
        $this->assertSame(JobUpserter::UNCHANGED, $o2);
        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, JobPosting::count());
    }

    public function test_changed_listing_is_updated_and_freshness_is_kept(): void
    {
        $s = $this->source();
        $u = app(JobUpserter::class);
        [$job] = $u->upsert($s, $this->n());
        $first = $job->first_seen_at->copy();

        $this->travel(2)->days();
        [$job2, $outcome] = $u->upsert($s, $this->n(['description' => 'Laravel, MySQL and Vue work, fully remote.']));

        $this->assertSame(JobUpserter::UPDATED, $outcome);
        $this->assertTrue($job2->first_seen_at->equalTo($first), 'first_seen_at must not move');
        $this->assertTrue($job2->last_seen_at->gt($first));
        $this->assertStringContainsString('Vue', $job2->description);
        $this->assertContains('Vue.js', $job2->skills);
    }

    public function test_unchanged_sighting_only_bumps_last_seen(): void
    {
        $s = $this->source();
        $u = app(JobUpserter::class);
        [$job] = $u->upsert($s, $this->n());
        $before = $job->last_seen_at->copy();

        $this->travel(3)->hours();
        $u->upsert($s, $this->n());

        $this->assertTrue($job->refresh()->last_seen_at->gt($before));
    }

    public function test_expired_job_is_reopened_when_it_reappears(): void
    {
        $s = $this->source();
        $u = app(JobUpserter::class);
        [$job] = $u->upsert($s, $this->n());
        $job->update(['state' => 'expired']);

        $u->upsert($s, $this->n());

        $this->assertSame('active', $job->refresh()->state);
    }

    public function test_sources_without_ids_dedupe_on_url_company_and_title_together(): void
    {
        $s = $this->source(SourceType::Rss, ['url' => 'https://feeds.example.com/x']);
        $u = app(JobUpserter::class);

        $u->upsert($s, $this->n(['externalId' => null]));
        $u->upsert($s, $this->n(['externalId' => null, 'url' => 'https://example.com/jobs/1?utm_source=newsletter']));   // same job, tracking param
        $this->assertSame(1, JobPosting::count());

        // Same title+company but a different posting URL is a different job; title alone is never enough.
        $u->upsert($s, $this->n(['externalId' => null, 'url' => 'https://example.com/jobs/2']));
        $this->assertSame(2, JobPosting::count());

        $u->upsert($s, $this->n(['externalId' => null, 'url' => 'https://example.com/jobs/1', 'company' => 'Other Co']));
        $this->assertSame(3, JobPosting::count());
    }

    public function test_same_posting_from_two_sources_is_linked_not_duplicated_in_the_feed(): void
    {
        $a = $this->source(SourceType::Greenhouse, ['board_token' => 'acme'], 'A');
        $b = $this->source(SourceType::Rss, ['url' => 'https://feeds.example.com/x'], 'B');
        $u = app(JobUpserter::class);

        [$first] = $u->upsert($a, $this->n());
        [$second] = $u->upsert($b, $this->n(['externalId' => null]));

        $this->assertSame(2, JobPosting::count());
        $this->assertSame($first->id, $second->refresh()->duplicate_of);
        $this->assertNull($first->refresh()->duplicate_of);
    }

    public function test_unique_index_prevents_database_level_duplicates(): void
    {
        $s = $this->source();
        $this->job($s, ['externalId' => 'dup']);

        $this->expectException(UniqueConstraintViolationException::class);
        JobPosting::create([
            'source_id' => $s->id, 'external_id' => 'dup', 'title' => 'x', 'url' => 'https://x.test', 'fingerprint' => str_repeat('a', 64),
            'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
    }

    public function test_html_is_stripped_and_stored_as_plain_text(): void
    {
        $s = $this->source();
        [$job] = app(JobUpserter::class)->upsert($s, $this->n(['description' => '<script>alert(1)</script><p>Hello</p>']));
        $this->assertStringNotContainsString('<script', $job->description);
    }
}
