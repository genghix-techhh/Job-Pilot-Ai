<?php

namespace App\Services\Http;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Outbound GET client for everything a user can influence (feed URLs, board tokens ...).
 *
 * Defences against SSRF and resource abuse:
 *  - only http/https, only ports 80/443, no credentials in the URL;
 *  - hostnames like localhost / *.local / *.internal / single-label names are refused;
 *  - the hostname is resolved and EVERY returned address must be a public IP
 *    (loopback, private, link-local, CGNAT, multicast, reserved and IPv4-mapped/6to4/NAT64
 *    forms of those are refused);
 *  - the connection is pinned to the validated address (no DNS-rebinding window);
 *  - redirects are followed manually (max N) and each hop is re-validated;
 *  - connect/total timeouts and a hard response-size cap while streaming.
 */
class SafeHttp
{
    public function __construct(private readonly HostResolver $resolver) {}

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, scalar|list<scalar>>  $query
     *
     * @throws UnsafeUrlException when the URL, a redirect, or the response breaks a rule
     * @throws ConnectionException on network failure / timeout
     */
    public function get(string $url, array $headers = [], array $query = [], ?int $maxBytes = null, ?int $timeout = null): SafeResponse
    {
        $maxBytes ??= (int) config('jobpilot.http.max_bytes');
        $timeout ??= (int) config('jobpilot.http.timeout');
        $maxRedirects = (int) config('jobpilot.http.max_redirects');

        if ($query) {
            $url .= (str_contains($url, '?') ? '&' : '?').http_build_query($query);
        }

        $redirects = 0;
        $current = $url;

        while (true) {
            ['host' => $host, 'ip' => $ip, 'port' => $port] = $this->assertSafe($current);

            // NOTE: do not set Guzzle's `stream` option: it forces the PHP stream handler, which cannot
            // pin the connection with CURLOPT_RESOLVE. The size cap is enforced from curl callbacks instead.
            $tooLarge = false;
            try {
                $response = Http::withHeaders(array_merge([
                    'User-Agent' => (string) config('jobpilot.http.user_agent'),
                    'Accept' => '*/*',
                ], $headers))
                    ->withOptions([
                        'allow_redirects' => false,
                        'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:".(str_contains($ip, ':') ? "[{$ip}]" : $ip)]],
                        'on_headers' => function (ResponseInterface $r) use ($maxBytes, &$tooLarge) {
                            if ((int) $r->getHeaderLine('Content-Length') > $maxBytes) {
                                $tooLarge = true;
                                throw new UnsafeUrlException('too large');
                            }
                        },
                        'progress' => function ($dlTotal, $dlNow) use ($maxBytes, &$tooLarge) {
                            if ($dlNow > $maxBytes) {
                                $tooLarge = true;
                                throw new UnsafeUrlException('too large');
                            }
                        },
                    ])
                    ->timeout($timeout)
                    ->connectTimeout(min(8, $timeout))
                    ->get($current);
            } catch (\Throwable $e) {
                // Callback exceptions can be wrapped by Guzzle/Laravel; the flag tells us what really happened.
                if ($tooLarge) {
                    throw new UnsafeUrlException('The response was larger than the allowed '.($maxBytes >= 1048576 ? round($maxBytes / 1048576, 1).' MB' : round($maxBytes / 1024).' KB').'.');
                }
                throw $e;
            }
            if ($tooLarge) {
                throw new UnsafeUrlException('The response was larger than the allowed '.($maxBytes >= 1048576 ? round($maxBytes / 1048576, 1).' MB' : round($maxBytes / 1024).' KB').'.');
            }

            $status = $response->status();

            if (in_array($status, [301, 302, 303, 307, 308], true) && $response->header('Location')) {
                if (++$redirects > $maxRedirects) {
                    throw new UnsafeUrlException('Too many redirects.');
                }
                $current = $this->resolveLocation($current, (string) $response->header('Location'));

                continue;
            }

            $body = $this->readBounded($response->toPsrResponse()->getBody(), $maxBytes);

            $flat = [];
            foreach ($response->headers() as $k => $v) {
                $flat[strtolower($k)] = is_array($v) ? (string) ($v[0] ?? '') : (string) $v;
            }

            return new SafeResponse($status, $body, $flat, $current);
        }
    }

    /**
     * Validate a URL without fetching it. Used when saving a source so unsafe URLs are
     * rejected up-front with a useful message.
     *
     * @return array{host: string, ip: string, port: int, scheme: string}
     *
     * @throws UnsafeUrlException
     */
    public function assertSafe(string $url): array
    {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host']) || empty($parts['scheme'])) {
            throw new UnsafeUrlException('That is not a valid URL.');
        }

        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new UnsafeUrlException('Only http:// and https:// URLs are allowed.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new UnsafeUrlException('URLs containing credentials are not allowed.');
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        if (! in_array($port, [80, 443], true)) {
            throw new UnsafeUrlException('Only the standard web ports (80 and 443) are allowed.');
        }

        $host = strtolower(trim($parts['host'], '[]'));
        $host = rtrim($host, '.');

        if ($this->isBlockedHostname($host)) {
            throw new UnsafeUrlException('That host name points to a local or internal address and is not allowed.');
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $this->resolver->resolve($host);
        if ($ips === []) {
            throw new UnsafeUrlException('The host name could not be resolved.');
        }
        foreach ($ips as $ip) {
            if (! self::isPublicIp($ip)) {
                throw new UnsafeUrlException('That address resolves to a private, local or reserved network and is not allowed.');
            }
        }

        return ['host' => $host, 'ip' => $ips[0], 'port' => $port, 'scheme' => $scheme];
    }

    public static function isPublicIp(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        if (str_contains($ip, ':')) {
            $bin = inet_pton($ip);
            if ($bin === false) {
                return false;
            }
            // IPv4-mapped (::ffff:a.b.c.d) and NAT64 (64:ff9b::/96): judge the embedded IPv4.
            if (strncmp($bin, hex2bin('00000000000000000000ffff'), 12) === 0
                || strncmp($bin, hex2bin('0064ff9b0000000000000000'), 12) === 0) {
                return self::isPublicIp((string) inet_ntop(substr($bin, 12)));
            }
            // 6to4 (2002::/16) embeds an IPv4 in bytes 2..5.
            if (strncmp($bin, "\x20\x02", 2) === 0) {
                return self::isPublicIp((string) inet_ntop(substr($bin, 2, 4)));
            }
            // Documentation prefix 2001:db8::/32 and unspecified/loopback handled below by the filter.
            if (strncmp($bin, hex2bin('20010db8'), 4) === 0) {
                return false;
            }

            return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
                && ! (ord($bin[0]) === 0xFF); // multicast ff00::/8
        }

        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }

        $long = ip2long($ip);
        foreach (['100.64.0.0/10', '192.0.0.0/24', '192.0.2.0/24', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4', '169.254.0.0/16', '127.0.0.0/8', '0.0.0.0/8'] as $cidr) {
            [$net, $bits] = explode('/', $cidr);
            $mask = -1 << (32 - (int) $bits);
            if (($long & $mask) === (ip2long($net) & $mask)) {
                return false;
            }
        }

        return true;
    }

    private function isBlockedHostname(string $host): bool
    {
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost')) {
            return true;
        }
        foreach (['.local', '.internal', '.lan', '.home.arpa', '.localdomain', '.intranet', '.corp'] as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }
        if ($host === 'metadata.google.internal') {
            return true;
        }
        // Single-label names ("intranet", "router") are never public web hosts.
        if (! filter_var($host, FILTER_VALIDATE_IP) && ! str_contains($host, '.')) {
            return true;
        }

        return false;
    }

    private function resolveLocation(string $base, string $location): string
    {
        $location = trim($location);
        if (preg_match('~^https?://~i', $location)) {
            return $location;
        }
        $b = parse_url($base);
        $origin = ($b['scheme'] ?? 'https').'://'.($b['host'] ?? '').(isset($b['port']) ? ':'.$b['port'] : '');
        if (str_starts_with($location, '//')) {
            return ($b['scheme'] ?? 'https').':'.$location;
        }
        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }
        if (preg_match('~^[a-z][a-z0-9+.\-]*:~i', $location)) {
            throw new UnsafeUrlException('Redirect to a non-web address was refused.');
        }
        $dir = rtrim(dirname($b['path'] ?? '/'), '/');

        return $origin.$dir.'/'.$location;
    }

    private function readBounded(StreamInterface $stream, int $maxBytes): string
    {
        $buffer = '';
        while (! $stream->eof()) {
            $buffer .= $stream->read(8192);
            if (strlen($buffer) > $maxBytes) {
                throw new UnsafeUrlException('The response was larger than the allowed '.($maxBytes >= 1048576 ? round($maxBytes / 1048576, 1).' MB' : round($maxBytes / 1024).' KB').'.');
            }
        }

        return $buffer;
    }
}
