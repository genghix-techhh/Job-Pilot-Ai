<?php

namespace App\Services\Jobs\Connectors;

use App\Enums\SourceType;
use App\Models\JobSource;
use App\Services\Http\UnsafeUrlException;
use App\Services\Jobs\NormalizedJob;
use App\Services\Jobs\SalaryParser;
use App\Support\Text;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Owner-defined RSS 2.0 / Atom / RDF feeds. The URL passes the SSRF guard both when
 * it is saved and on every fetch. Conditional requests (ETag / Last-Modified) and the
 * feed's own <ttl> are respected so we never hammer a publisher.
 */
class RssFeedConnector extends AbstractConnector
{
    public function type(): SourceType
    {
        return SourceType::Rss;
    }

    public function minIntervalMinutes(): int
    {
        return 60;
    }

    public function validateConfig(array $input): array
    {
        $url = trim((string) ($input['url'] ?? ''));
        try {
            $this->http->assertSafe($url);
        } catch (UnsafeUrlException $e) {
            throw ValidationException::withMessages(['url' => $e->getMessage()]);
        }

        $host = (string) parse_url($url, PHP_URL_HOST);

        return [
            'name' => trim((string) ($input['name'] ?? '')) ?: Str::limit('Feed: '.$host, 80, ''),
            'config' => [
                'url' => $url,
                'kind' => ($input['kind'] ?? 'employment') === 'freelance' ? 'freelance' : 'employment',
                'default_company' => Str::limit(trim((string) ($input['default_company'] ?? '')), 120, ''),
            ],
        ];
    }

    public function fetchJobs(JobSource $source): FetchResult
    {
        $url = (string) $source->cfg('url');
        $cache = (array) ($source->last_stats['http_cache'] ?? []);
        $headers = array_filter([
            'Accept' => 'application/rss+xml, application/atom+xml, application/xml;q=0.9, text/xml;q=0.8',
            'If-None-Match' => $cache['etag'] ?? null,
            'If-Modified-Since' => $cache['last_modified'] ?? null,
        ]);

        $response = $this->get($url, [], $headers, 3 * 1024 * 1024);

        if ($response->status === 304) {
            return new FetchResult([], notModified: true, httpStatus: 304, meta: ['http_cache' => $cache]);
        }

        $parsed = $this->parseFeed($response->body);

        $jobs = [];
        $skipped = 0;
        foreach (array_slice($parsed['items'], 0, (int) config('jobpilot.max_jobs_per_source', 500)) as $raw) {
            $raw['_feed_title'] = $parsed['title'];
            $job = $this->normalizeJob($raw, $source);
            $job ? $jobs[] = $job : $skipped++;
        }

        return new FetchResult($jobs, httpStatus: $response->status, meta: array_filter([
            'http_cache' => array_filter(['etag' => $response->header('etag'), 'last_modified' => $response->header('last-modified')]),
            'feed_ttl_minutes' => $parsed['ttl'],
        ]), skipped: $skipped);
    }

    public function normalizeJob(array $raw, JobSource $source): ?NormalizedJob
    {
        $title = Text::normalizeWhitespace(Text::htmlToText((string) ($raw['title'] ?? '')));
        $link = trim((string) ($raw['link'] ?? ''));
        if ($title === '' || ! preg_match('~^https?://~i', $link)) {
            return null;
        }

        $company = trim((string) ($raw['author'] ?? ''));
        [$title, $titleCompany] = $this->splitTitle($title);
        $company = $titleCompany ?: $company ?: (string) $source->cfg('default_company') ?: null;

        $description = Text::htmlToText((string) ($raw['content'] ?? ''));
        $isFreelance = $source->cfg('kind') === 'freelance';

        $job = new NormalizedJob(
            title: Str::limit($title, 250, ''),
            url: $link,
            externalId: ($raw['id'] ?? '') !== '' ? Str::limit((string) $raw['id'], 250, '') : null,
            company: $company ? Str::limit($company, 200, '') : null,
            description: $description,
            location: ($raw['location'] ?? '') ?: null,
            employmentType: $isFreelance ? 'contract' : $this->employmentType($title.' '.implode(' ', $raw['categories'] ?? [])),
            kind: $isFreelance ? 'freelance' : 'employment',
            postedAt: $this->date($raw['date'] ?? null),
            meta: array_filter(['tags' => array_slice($raw['categories'] ?? [], 0, 12), 'feed' => Str::limit((string) ($raw['_feed_title'] ?? ''), 80, '')]),
        );

        if ($pay = SalaryParser::fromText($description)) {
            [$job->salaryMin, $job->salaryMax, $job->salaryCurrency, $job->salaryPeriod] = [$pay['min'], $pay['max'], $pay['currency'], $pay['period']];
        }

        return $this->withLocation($job);
    }

    /** "Senior Dev at Acme" -> [title, company];  "Acme: Senior Dev" -> [title, company]. */
    private function splitTitle(string $title): array
    {
        if (preg_match('/^(.{3,120}?)\s+(?:at|@)\s+(.{2,80})$/i', $title, $m)) {
            return [trim($m[1]), trim($m[2])];
        }
        if (preg_match('/^([^:]{2,60}):\s+(.{4,160})$/', $title, $m)) {
            return [trim($m[2]), trim($m[1])];
        }

        return [$title, null];
    }

    /**
     * @return array{title: string, ttl: ?int, items: list<array<string, mixed>>}
     */
    public function parseFeed(string $xml): array
    {
        // Refuse entity declarations outright (XML bomb / XXE hardening).
        if (stripos($xml, '<!ENTITY') !== false) {
            throw new SourceFetchException('The feed uses XML entity declarations and was refused for safety.');
        }

        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument;
        $ok = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT | LIBXML_NOCDATA);
        libxml_use_internal_errors($previous);
        if (! $ok || ! $dom->documentElement) {
            throw new SourceFetchException('The URL did not return a valid RSS/Atom feed.');
        }

        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('atom', 'http://www.w3.org/2005/Atom');
        $xp->registerNamespace('content', 'http://purl.org/rss/1.0/modules/content/');
        $xp->registerNamespace('dc', 'http://purl.org/dc/elements/1.1/');
        $xp->registerNamespace('rdf', 'http://www.w3.org/1999/02/22-rdf-syntax-ns#');
        $xp->registerNamespace('rss1', 'http://purl.org/rss/1.0/');

        $root = $dom->documentElement->localName;
        $items = [];

        if ($root === 'feed') {                                   // Atom
            $title = $this->text($xp, 'string(/atom:feed/atom:title)');
            foreach ($xp->query('/atom:feed/atom:entry') ?: [] as $e) {
                $link = $this->text($xp, 'string(atom:link[@rel="alternate" or not(@rel)][1]/@href)', $e);
                $items[] = [
                    'id' => $this->text($xp, 'string(atom:id)', $e),
                    'title' => $this->text($xp, 'string(atom:title)', $e),
                    'link' => $link,
                    'content' => $this->text($xp, 'string(atom:content)', $e) ?: $this->text($xp, 'string(atom:summary)', $e),
                    'date' => $this->text($xp, 'string(atom:published)', $e) ?: $this->text($xp, 'string(atom:updated)', $e),
                    'author' => $this->text($xp, 'string(atom:author/atom:name)', $e),
                    'categories' => $this->list($xp, 'atom:category/@term', $e),
                ];
            }

            return ['title' => $title, 'ttl' => null, 'items' => $items];
        }

        if (in_array($root, ['rss', 'RDF'], true)) {              // RSS 2.0 / RSS 1.0 (RDF)
            $isRdf = $root === 'RDF';
            $title = $this->text($xp, $isRdf ? 'string(//rss1:channel/rss1:title)' : 'string(/rss/channel/title)');
            $ttl = (int) $this->text($xp, 'string(/rss/channel/ttl)');
            $nodes = $isRdf ? $xp->query('//rss1:item') : $xp->query('/rss/channel/item');
            foreach ($nodes ?: [] as $e) {
                $p = $isRdf ? 'rss1:' : '';
                $items[] = [
                    'id' => $this->text($xp, "string({$p}guid)", $e),
                    'title' => $this->text($xp, "string({$p}title)", $e),
                    'link' => $this->text($xp, "string({$p}link)", $e) ?: $this->text($xp, 'string(guid[@isPermaLink="true"])', $e),
                    'content' => $this->text($xp, 'string(content:encoded)', $e) ?: $this->text($xp, "string({$p}description)", $e),
                    'date' => $this->text($xp, 'string(pubDate)', $e) ?: $this->text($xp, 'string(dc:date)', $e),
                    'author' => $this->text($xp, 'string(dc:creator)', $e) ?: $this->text($xp, 'string(author)', $e),
                    'location' => $this->text($xp, 'string(region)', $e),
                    'categories' => $this->list($xp, 'category', $e),
                ];
            }

            return ['title' => $title, 'ttl' => $ttl > 0 ? $ttl : null, 'items' => $items];
        }

        throw new SourceFetchException('Unrecognised feed format (expected RSS or Atom).');
    }

    private function text(\DOMXPath $xp, string $expr, ?\DOMNode $ctx = null): string
    {
        $v = $ctx ? $xp->evaluate($expr, $ctx) : $xp->evaluate($expr);

        return trim((string) $v);
    }

    /** @return list<string> */
    private function list(\DOMXPath $xp, string $expr, \DOMNode $ctx): array
    {
        $out = [];
        foreach ($xp->query($expr, $ctx) ?: [] as $n) {
            $v = trim($n->textContent);
            if ($v !== '') {
                $out[] = Str::limit($v, 40, '');
            }
        }

        return $out;
    }
}
