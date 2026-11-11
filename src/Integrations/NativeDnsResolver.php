<?php

namespace TelescopeMonitor\Integrations;

final class NativeDnsResolver implements DnsResolver
{
    public function addresses(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        $ips = [];
        foreach ($records ?: [] as $r) {
            if (isset($r['ip'])) {
                $ips[] = $r['ip'];
            }if (isset($r['ipv6'])) {
                $ips[] = $r['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }
}
