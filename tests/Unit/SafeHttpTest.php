<?php

namespace Tests\Unit;

use App\Services\Http\HostResolver;
use App\Services\Http\SafeHttp;
use App\Services\Http\UnsafeUrlException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SafeHttpTest extends TestCase
{
    private function http(array $dns = []): SafeHttp
    {
        return new SafeHttp(new class($dns) implements HostResolver
        {
            public function __construct(private array $map) {}

            public function resolve(string $host): array
            {
                return $this->map[$host] ?? ['93.184.216.34'];
            }
        });
    }

    public static function unsafeUrls(): array
    {
        return [
            'localhost' => ['http://localhost/feed'],
            'sub of localhost' => ['http://api.localhost/feed'],
            'loopback v4' => ['http://127.0.0.1/feed'],
            'private 10/8' => ['http://10.1.2.3/feed'],
            'private 192.168' => ['https://192.168.1.1/feed'],
            'private 172.16' => ['http://172.16.5.4/feed'],
            'link-local metadata' => ['http://169.254.169.254/latest/meta-data'],
            'CGNAT' => ['http://100.64.0.1/'],
            'zero net' => ['http://0.0.0.0/'],
            'loopback v6' => ['http://[::1]/feed'],
            'ula v6' => ['http://[fd00::1]/feed'],
            'link-local v6' => ['http://[fe80::1]/feed'],
            'ipv4-mapped loopback' => ['http://[::ffff:127.0.0.1]/'],
            'file scheme' => ['file:///etc/passwd'],
            'ftp scheme' => ['ftp://example.com/feed'],
            'gopher scheme' => ['gopher://example.com/'],
            'javascript scheme' => ['javascript:alert(1)'],
            'credentials in url' => ['https://user:pass@example.com/feed'],
            'odd port' => ['http://example.com:6379/'],
            'internal tld' => ['http://printer.internal/'],
            'single label' => ['http://intranet/'],
            'local tld' => ['http://nas.local/'],
            'not a url' => ['not a url'],
        ];
    }

    #[DataProvider('unsafeUrls')]
    public function test_unsafe_urls_are_refused(string $url): void
    {
        $this->expectException(UnsafeUrlException::class);
        $this->http()->assertSafe($url);
    }

    public function test_hostname_resolving_to_private_address_is_refused(): void
    {
        $this->expectException(UnsafeUrlException::class);
        $this->http(['evil.example' => ['10.0.0.5']])->assertSafe('https://evil.example/feed');
    }

    public function test_hostname_with_one_private_and_one_public_record_is_refused(): void
    {
        $this->expectException(UnsafeUrlException::class);
        $this->http(['mixed.example' => ['93.184.216.34', '127.0.0.1']])->assertSafe('https://mixed.example/');
    }

    public function test_public_https_url_is_allowed(): void
    {
        $r = $this->http()->assertSafe('https://jobs.example.com/feed.xml');
        $this->assertSame('jobs.example.com', $r['host']);
        $this->assertSame(443, $r['port']);
    }

    public function test_redirect_to_a_private_address_is_blocked(): void
    {
        Http::fake(['https://feeds.example.com/*' => Http::response('', 302, ['Location' => 'http://127.0.0.1/admin'])]);
        $this->expectException(UnsafeUrlException::class);
        $this->http()->get('https://feeds.example.com/jobs.rss');
    }

    public function test_redirect_to_non_http_scheme_is_blocked(): void
    {
        Http::fake(['https://feeds.example.com/*' => Http::response('', 301, ['Location' => 'file:///etc/passwd'])]);
        $this->expectException(UnsafeUrlException::class);
        $this->http()->get('https://feeds.example.com/jobs.rss');
    }

    public function test_redirect_loop_is_limited(): void
    {
        Http::fake(['https://feeds.example.com/*' => Http::response('', 302, ['Location' => 'https://feeds.example.com/again'])]);
        $this->expectException(UnsafeUrlException::class);
        $this->expectExceptionMessage('Too many redirects');
        $this->http()->get('https://feeds.example.com/start');
    }

    public function test_safe_redirect_is_followed(): void
    {
        Http::fake([
            'https://feeds.example.com/old' => Http::response('', 301, ['Location' => '/new']),
            'https://feeds.example.com/new' => Http::response('<rss/>', 200),
        ]);
        $r = $this->http()->get('https://feeds.example.com/old');
        $this->assertSame(200, $r->status);
        $this->assertSame('https://feeds.example.com/new', $r->finalUrl);
    }

    public function test_oversized_response_is_rejected(): void
    {
        Http::fake(['https://big.example.com/*' => Http::response(str_repeat('a', 50_000), 200)]);
        $this->expectException(UnsafeUrlException::class);
        $this->http()->get('https://big.example.com/feed', maxBytes: 10_000);
    }
}
