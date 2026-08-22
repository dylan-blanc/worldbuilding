<?php

declare(strict_types=1);

/**
 * Resolves external hosts and rejects every private, reserved or local destination.
 * RemoteContentInspector pins the returned public IP with CURLOPT_RESOLVE to prevent DNS rebinding.
 */
final class NetworkTargetValidator implements NetworkTargetResolver
{
    private const BLOCKED_CIDRS = [
        "0.0.0.0/8", "10.0.0.0/8", "100.64.0.0/10", "127.0.0.0/8", "169.254.0.0/16",
        "172.16.0.0/12", "192.0.0.0/24", "192.0.2.0/24", "192.88.99.0/24", "192.168.0.0/16",
        "198.18.0.0/15", "198.51.100.0/24", "203.0.113.0/24", "224.0.0.0/4", "240.0.0.0/4",
        "::/96", "::ffff:0:0/96", "64:ff9b::/96", "64:ff9b:1::/48", "100::/64",
        "2001::/32", "2001:db8::/32", "2001:10::/28", "2001:20::/28", "2002::/16",
        "fc00::/7", "fe80::/10", "fec0::/10", "ff00::/8",
    ];

    public function resolvePublicIp(LinkTarget $target): string
    {
        if ($target->isInternal() || $target->host === "") {
            throw new DomainException("Destination reseau invalide");
        }

        if ($target->host === "localhost" || str_ends_with($target->host, ".localhost")) {
            throw new DomainException("Cette destination reseau est interdite");
        }

        $ips = filter_var($target->host, FILTER_VALIDATE_IP)
            ? [$target->host]
            : $this->resolveHost($target->host);

        foreach ($ips as $ip) {
            if (!$this->isPublicIp($ip)) {
                throw new DomainException("Cette destination reseau est interdite");
            }
        }

        return $ips[0];
    }

    private function resolveHost(string $host): array
    {
        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        $ips = [];

        foreach (is_array($records) ? $records : [] as $record) {
            isset($record["ip"]) && $ips[] = (string) $record["ip"];
            isset($record["ipv6"]) && $ips[] = (string) $record["ipv6"];
        }

        if ($ips === []) {
            throw new DomainException("Le nom de domaine ne peut pas etre resolu");
        }

        return array_values(array_unique($ips));
    }

    private function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        foreach (self::BLOCKED_CIDRS as $cidr) {
            if ($this->matchesCidr($ip, $cidr)) {
                return false;
            }
        }

        return true;
    }

    private function matchesCidr(string $ip, string $cidr): bool
    {
        [$network, $prefixLength] = explode("/", $cidr, 2);
        $ipBytes = inet_pton($ip);
        $networkBytes = inet_pton($network);

        if ($ipBytes === false || $networkBytes === false || strlen($ipBytes) !== strlen($networkBytes)) {
            return false;
        }

        $bits = (int) $prefixLength;
        $fullBytes = intdiv($bits, 8);
        $remainingBits = $bits % 8;

        if ($fullBytes > 0 && substr($ipBytes, 0, $fullBytes) !== substr($networkBytes, 0, $fullBytes)) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($ipBytes[$fullBytes]) & $mask) === (ord($networkBytes[$fullBytes]) & $mask);
    }
}
