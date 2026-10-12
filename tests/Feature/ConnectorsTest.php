<?php

namespace Tests\Feature;

use App\Enums\SourceType;
use App\Models\JobPosting;
use App\Services\Jobs\ConnectorRegistry;
use App\Services\Jobs\Connectors\SourceFetchException;
use App\Services\Jobs\JobSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\JobPilotHelpers;
use Tests\TestCase;

class ConnectorsTest extends TestCase
{
    use JobPilotHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeDns();
    }

    private function fetch(SourceType $type, array $config)
    {
        $source = $this->source($type, $config);

        return app(ConnectorRegistry::class)->for($type)->fetchJobs($source);
    }

    public function test_greenhouse_jobs_are_normalised(): void
    {
        Http::fake([
            'boards-api.greenhouse.io/v1/boards/acme' => Http::response(['name' => 'Acme Corp'], 200),
            'boards-api.greenhouse.io/v1/boards/acme/jobs*' => Http::response(['jobs' => [[
                'id' => 4012345, 'title' => 'Backend Engineer (PHP)', 'updated_at' => '2026-10-01T10:00:00-04:00',
                'absolute_url' => 'https://boards.greenhouse.io/acme/jobs/4012345?gh_src=abc',
                'location' => ['name' => 'Remote - Worldwide'],
                'content' => '&lt;p&gt;Build APIs with &lt;strong&gt;PHP&lt;/strong&gt; and Laravel.&lt;/p&gt;&lt;ul&gt;&lt;li&gt;MySQL&lt;/li&gt;&lt;/ul&gt;&lt;p&gt;Salary range: $90,000 - $120,000 per year&lt;/p&gt;',
                'departments' => [['name' => 'Engineering']],
            ]]], 200),
        ]);

        $res = $this->fetch(SourceType::Greenhouse, ['board_token' => 'acme']);

        $this->assertCount(1, $res->jobs);
        $j = $res->jobs[0];
        $this->assertSame('4012345', $j->externalId);
        $this->assertSame('Acme Corp', $j->company);
        $this->assertSame('worldwide', $j->remoteScope);
        $this->assertTrue($j->isRemote);
        $this->assertStringContainsString('Build APIs with PHP and Laravel.', $j->description);
        $this->assertStringNotContainsString('<', $j->description, 'HTML must be reduced to text');
        $this->assertSame([90000, 120000, 'USD', 'year'], [$j->salaryMin, $j->salaryMax, $j->salaryCurrency, $j->salaryPeriod]);
        $this->assertSame(['Engineering'], $j->meta['departments']);
    }

    public function test_greenhouse_bad_token_gives_clear_error(): void
    {
        Http::fake(['boards-api.greenhouse.io/*' => Http::response('', 404)]);
        $this->expectException(SourceFetchException::class);
        $this->expectExceptionMessage('Not found');
        $this->fetch(SourceType::Greenhouse, ['board_token' => 'nope']);
    }

    public function test_lever_jobs_are_normalised_and_paginated_in_the_eu_region(): void
    {
        Http::fake(['api.eu.lever.co/v0/postings/acme*' => Http::response([[
            'id' => 'abc-123', 'text' => 'Senior Frontend Engineer', 'hostedUrl' => 'https://jobs.eu.lever.co/acme/abc-123',
            'applyUrl' => 'https://jobs.eu.lever.co/acme/abc-123/apply', 'createdAt' => 1790000000000,
            'categories' => ['location' => 'Berlin or Remote', 'commitment' => 'Full-time', 'team' => 'Web'],
            'country' => 'DE', 'workplaceType' => 'remote',
            'descriptionPlain' => 'Work with React and TypeScript.',
            'lists' => [['text' => 'Requirements', 'content' => '<li>5 years experience</li>']],
            'additionalPlain' => 'We value diversity.',
            'salaryRange' => ['min' => 70000, 'max' => 90000, 'currency' => 'EUR', 'interval' => 'per-year-salary'],
        ]], 200)]);

        $res = $this->fetch(SourceType::Lever, ['site' => 'acme', 'region' => 'eu']);

        $j = $res->jobs[0];
        $this->assertSame('abc-123', $j->externalId);
        $this->assertSame('full_time', $j->employmentType);
        $this->assertStringContainsString('React', $j->description);
        $this->assertStringContainsString('5 years experience', $j->description);
        $this->assertSame([70000, 90000, 'EUR', 'year'], [$j->salaryMin, $j->salaryMax, $j->salaryCurrency, $j->salaryPeriod]);
        $this->assertTrue($j->isRemote);
        $this->assertContains('Germany', $j->eligibleCountries);
        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://api.eu.lever.co/'));
    }

    public function test_remoteok_skips_the_legal_notice_keeps_attribution_link_and_filters_by_keyword(): void
    {
        Http::fake(['remoteok.com/api' => Http::response([
            ['last_updated' => 1790000000, 'legal' => 'Please link back (with follow) to the URL on Remote OK and mention Remote OK as a source'],
            ['id' => '111', 'slug' => 'remote-php-dev-acme-111', 'epoch' => 1790000000, 'date' => '2026-10-01T00:00:00+00:00', 'company' => 'Acme', 'position' => 'PHP Developer',
                'tags' => ['php', 'laravel', 'full time'], 'description' => '<p>Laravel work</p>', 'location' => 'Worldwide', 'salary_min' => 80000, 'salary_max' => 100000,
                'apply_url' => 'https://remoteOK.com/l/111', 'url' => 'https://remoteok.com/remote-jobs/remote-php-dev-acme-111'],
            ['id' => '222', 'slug' => 'remote-chef-222', 'company' => 'Foodco', 'position' => 'Chef', 'tags' => ['cooking'], 'description' => 'Cook', 'url' => 'https://remoteok.com/remote-jobs/remote-chef-222'],
        ], 200)]);

        $res = $this->fetch(SourceType::RemoteOk, ['keywords' => ['php']]);

        $this->assertCount(1, $res->jobs);
        $j = $res->jobs[0];
        $this->assertSame('https://remoteok.com/remote-jobs/remote-php-dev-acme-111', $j->url, 'primary link must be the Remote OK listing');
        $this->assertSame('Remote OK', $j->meta['attribution']);
        $this->assertSame('worldwide', $j->remoteScope);
        $this->assertSame(80000, $j->salaryMin);
        $this->assertSame(1, $res->skipped);
    }

    public function test_rss_and_atom_feeds_are_parsed_with_conditional_get_and_ttl(): void
    {
        $rss = <<<'XML'
<?xml version="1.0"?><rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/"><channel><title>WWR</title><ttl>180</ttl>
<item><title>Acme: Laravel Developer</title><link>https://example.com/jobs/1?utm_source=x</link><guid>job-1</guid><pubDate>Mon, 05 Oct 2026 10:00:00 +0000</pubDate>
<description><![CDATA[<p>Remote. Build with Laravel.</p>]]></description><category>php</category></item>
<item><title>No link here</title></item></channel></rss>
XML;
        Http::fake(['feeds.example.com/*' => Http::response($rss, 200, ['ETag' => '"v1"'])]);

        $res = $this->fetch(SourceType::Rss, ['url' => 'https://feeds.example.com/jobs.rss', 'kind' => 'employment', 'default_company' => '']);

        $this->assertCount(1, $res->jobs);
        $this->assertSame('Laravel Developer', $res->jobs[0]->title);
        $this->assertSame('Acme', $res->jobs[0]->company);
        $this->assertSame('job-1', $res->jobs[0]->externalId);
        $this->assertSame(180, $res->meta['feed_ttl_minutes']);
        $this->assertSame('"v1"', $res->meta['http_cache']['etag']);
        $this->assertSame(1, $res->skipped);

        // Atom
        $atom = '<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom"><title>T</title><entry><id>tag:x,1</id><title>Python Dev at Beta</title><link rel="alternate" href="https://example.com/a/1"/><updated>2026-10-01T00:00:00Z</updated><summary>Django</summary></entry></feed>';
        Http::fake(['atom.example.com/*' => Http::response($atom, 200)]);
        $res2 = $this->fetch(SourceType::Rss, ['url' => 'https://atom.example.com/feed', 'kind' => 'employment', 'default_company' => '']);
        $this->assertSame('Python Dev', $res2->jobs[0]->title);
        $this->assertSame('Beta', $res2->jobs[0]->company);
    }

    public function test_rss_conditional_request_sends_etag_and_handles_304(): void
    {
        $source = $this->source(SourceType::Rss, ['url' => 'https://feeds.example.com/jobs.rss', 'kind' => 'employment']);
        $source->update(['last_stats' => ['http_cache' => ['etag' => '"v1"']]]);
        Http::fake(['feeds.example.com/*' => Http::response('', 304)]);

        $res = app(ConnectorRegistry::class)->for(SourceType::Rss)->fetchJobs($source);

        $this->assertTrue($res->notModified);
        Http::assertSent(fn ($r) => $r->hasHeader('If-None-Match', '"v1"'));
    }

    public function test_rss_rejects_xml_entity_declarations(): void
    {
        $bomb = '<?xml version="1.0"?><!DOCTYPE r [<!ENTITY a "aaaa"><!ENTITY b "&a;&a;&a;">]><rss><channel><item><title>&b;</title><link>https://x.test/1</link></item></channel></rss>';
        Http::fake(['feeds.example.com/*' => Http::response($bomb, 200)]);
        $this->expectException(SourceFetchException::class);
        $this->fetch(SourceType::Rss, ['url' => 'https://feeds.example.com/x', 'kind' => 'employment']);
    }

    public function test_rss_feed_url_is_validated_when_saved(): void
    {
        $c = app(ConnectorRegistry::class)->for(SourceType::Rss);
        foreach (['http://localhost/feed', 'http://127.0.0.1/x', 'file:///etc/passwd', 'ftp://example.com/x', 'http://10.0.0.1/rss'] as $bad) {
            try {
                $c->validateConfig(['url' => $bad]);
                $this->fail("should reject {$bad}");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('url', $e->errors());
            }
        }
        $this->assertSame('https://example.com/rss', $c->validateConfig(['url' => 'https://example.com/rss'])['config']['url']);
    }

    public function test_board_tokens_cannot_inject_paths(): void
    {
        $registry = app(ConnectorRegistry::class);
        foreach ([[SourceType::Greenhouse, ['board_token' => '../../admin']], [SourceType::Lever, ['site' => 'a/b']], [SourceType::Greenhouse, ['board_token' => 'x?y=1']]] as [$type, $in]) {
            try {
                $registry->for($type)->validateConfig($in);
                $this->fail('should reject');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_adzuna_stays_unconfigured_without_credentials_and_confirmation(): void
    {
        $c = app(ConnectorRegistry::class)->for(SourceType::Adzuna);
        try {
            $c->validateConfig(['country' => 'gb', 'what' => 'laravel']);
            $this->fail('expected validation error');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('app_id', $e->errors());
            $this->assertArrayHasKey('usage_confirmed', $e->errors());
        }

        Http::fake();
        $unconfigured = $this->source(SourceType::Adzuna, ['country' => 'gb', 'what' => 'laravel'], 'Adzuna');
        $this->assertSame('unconfigured', $c->healthCheck($unconfigured)->status);
        Http::assertNothingSent();
    }

    public function test_adzuna_normalises_results_and_keeps_secrets_out_of_errors(): void
    {
        $cfg = ['country' => 'gb', 'what' => 'laravel', 'app_id' => 'id1', 'app_key' => 'SUPERSECRETKEY', 'usage_confirmed' => true, 'max_pages' => 1, 'remote_only' => true];
        Http::fake(['api.adzuna.com/*' => Http::response(['results' => [[
            'id' => '99', 'title' => 'Laravel <strong>Developer</strong>', 'description' => 'Snippet…', 'redirect_url' => 'https://www.adzuna.co.uk/land/ad/99',
            'created' => '2026-10-02T09:00:00Z', 'company' => ['display_name' => 'Gamma'], 'location' => ['display_name' => 'London'],
            'salary_min' => 50000, 'salary_max' => 60000, 'salary_is_predicted' => '0', 'contract_time' => 'full_time',
        ]]], 200)]);

        $res = $this->fetch(SourceType::Adzuna, $cfg);
        $this->assertSame('Laravel Developer', $res->jobs[0]->title);
        $this->assertSame('GBP', $res->jobs[0]->salaryCurrency);
        $this->assertTrue($res->jobs[0]->meta['snippet_only']);

        $source = $this->source(SourceType::Adzuna, $cfg, 'Adzuna 2');
        Http::fake(['api.adzuna.com/*' => Http::response('', 500)]);
        $result = app(JobSyncService::class)->sync($source);
        $this->assertSame('error', $result->status);
        $this->assertStringNotContainsString('SUPERSECRETKEY', (string) $result->error);
        $this->assertStringNotContainsString('SUPERSECRETKEY', (string) $source->refresh()->last_error);
    }

    public function test_source_config_secrets_are_encrypted_at_rest(): void
    {
        $this->source(SourceType::Adzuna, ['app_key' => 'PLAINTEXT-SECRET'], 'Adz');
        $raw = DB::table('job_sources')->value('config');
        $this->assertStringNotContainsString('PLAINTEXT-SECRET', $raw);
    }

    public function test_one_failing_source_does_not_block_the_others(): void
    {
        $bad = $this->source(SourceType::Greenhouse, ['board_token' => 'broken'], 'Broken');
        $good = $this->source(SourceType::Lever, ['site' => 'fine'], 'Fine');
        Http::fake([
            'boards-api.greenhouse.io/*' => Http::response('boom', 500),
            'api.lever.co/*' => Http::response([[
                'id' => 'z1', 'text' => 'PHP Developer', 'hostedUrl' => 'https://jobs.lever.co/fine/z1', 'categories' => ['location' => 'Remote'],
                'descriptionPlain' => 'PHP and Laravel work.', 'workplaceType' => 'remote',
            ]], 200),
        ]);

        $runs = app(JobSyncService::class)->syncAll(force: true);

        $this->assertCount(2, $runs);
        $this->assertSame('error', $bad->refresh()->health_status);
        $this->assertSame(1, $bad->consecutive_failures);
        $this->assertNotNull($bad->next_sync_at, 'a failing source backs off');
        $this->assertSame('ok', $good->refresh()->health_status);
        $this->assertSame(1, JobPosting::where('source_id', $good->id)->count());
    }

    public function test_full_listing_sources_expire_jobs_that_disappeared(): void
    {
        $source = $this->source(SourceType::Lever, ['site' => 'fine'], 'Fine');
        $posting = fn (string $id) => ['id' => $id, 'text' => "Dev $id", 'hostedUrl' => "https://jobs.lever.co/fine/$id", 'categories' => ['location' => 'Remote'], 'descriptionPlain' => 'PHP work', 'workplaceType' => 'remote'];

        Http::fake(['api.lever.co/*' => Http::sequence()->push([$posting('a'), $posting('b')], 200)->push([$posting('a')], 200)]);
        app(JobSyncService::class)->sync($source);
        $this->assertSame(2, JobPosting::where('state', 'active')->count());

        $this->travel(1)->hours();
        app(JobSyncService::class)->sync($source->refresh());

        $this->assertSame(1, JobPosting::where('state', 'active')->count());
        $this->assertSame('expired', JobPosting::where('external_id', 'b')->value('state'));
    }
}
