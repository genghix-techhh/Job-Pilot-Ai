<?php

namespace App\Services\Http;

class DnsHostResolver implements HostResolver
{
    public function resolve(string $host): array
    {
        $ips = [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
        foreach ($records as $r) {
            if (! empty($r['ip'])) {
                $ips[] = $r['ip'];
            }
            if (! empty($r['ipv6'])) {
                $ips[] = $r['ipv6'];
            }
        }
        if ($ips === []) {
            $v4 = @gethostbynamel($host) ?: [];
            $ips = array_merge($ips, $v4);
        }

        return array_values(array_unique($ips));
    }
}
