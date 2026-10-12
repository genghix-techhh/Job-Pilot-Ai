<?php

namespace App\Services\Http;

/** Resolves a hostname to every IP address it points at. Swappable so tests never hit real DNS. */
interface HostResolver
{
    /** @return list<string> IPv4 and IPv6 addresses (empty when the host does not resolve) */
    public function resolve(string $host): array;
}
