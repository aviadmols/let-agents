<?php

namespace App\Core\Http;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;
use RuntimeException;

/**
 * An address a store, a shopper or a demo gave, fetched only where it is on the public internet: never Railway's private
 * network, the cloud's metadata address, a loopback or a private range, in IPv4 or in IPv6, however it is written. The host
 * is resolved once and the connection pinned to what was checked (no DNS rebinding between the check and the fetch); each
 * redirect is checked again; a body is read up to a cap, never into the memory of a worker without end.
 *
 *   $request = OutboundUrl::request($url);           // null when the address may not be fetched
 *   $response = $request?->timeout(10)->get($url);
 *
 * Every fetch of an address that did not come from the platform itself goes through it. The AI providers', Shopify's and
 * the SMS provider's own APIs are the platform's and do not.
 */
final class OutboundUrl
{
    /** The most a fetched body may be. */
    public const MAX_BYTES = 25 * 1024 * 1024;

    private const PORTS = [80, 443];

    /** IPv4 ranges never reached, besides what PHP's private and reserved flags cover. */
    private const V4 = ['0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24',
        '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4'];

    /** IPv6 ranges never reached: loopback, unspecified, unique local, link and site local, multicast, documentation. */
    private const V6 = ['::/128', '::1/128', 'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8', '2001:db8::/32', '100::/64'];

    /**
     * Why an address may not be fetched, or null when it may: only http and https, on their own ports, to a host every
     * address of which is public.
     */
    public static function refusal(string $url): ?string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || ($parts['host'] ?? '') === '') {
            return 'not_http';
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'credentials';
        }
        $port = (int) ($parts['port'] ?? (strtolower((string) $parts['scheme']) === 'https' ? 443 : 80));
        if (! in_array($port, self::PORTS, true)) {
            return 'port';
        }
        // in tests a reserved ".test" host is made up and its answers faked (a developer's machine may point all of them
        // at itself); no such host exists on the internet
        if (app()->runningUnitTests() && str_ends_with(strtolower((string) $parts['host']), '.test')) {
            return null;
        }

        $ips = self::addresses((string) $parts['host']);
        if ($ips === []) {
            // a host that does not resolve reaches nothing; in tests the hosts are made up and their answers faked
            return app()->runningUnitTests() ? null : 'unresolved';
        }

        return array_filter($ips, fn (string $ip): bool => ! self::isPublic($ip)) === [] ? null : 'private';
    }

    /**
     * A request for an address that may be fetched, pinned to the address checked, its redirects checked again and its
     * body capped; null when it may not be fetched.
     */
    public static function request(string $url, int $maxBytes = self::MAX_BYTES): ?PendingRequest
    {
        return self::refusal($url) === null ? Http::withOptions(self::options($url, $maxBytes)) : null;
    }

    /**
     * The Guzzle options that keep a request to a checked address: the host pinned to one of its checked addresses, each
     * redirect refused unless its address is public too, and the transfer stopped past the cap.
     *
     * @return array<string, mixed>
     */
    public static function options(string $url, int $maxBytes = self::MAX_BYTES): array
    {
        $options = [
            'allow_redirects' => [
                'max' => 5,
                'protocols' => ['http', 'https'],
                'on_redirect' => function (RequestInterface $request, $response, UriInterface $to): void {
                    if (($why = self::refusal((string) $to)) !== null) {
                        throw new RuntimeException('Redirect to an address that may not be fetched ('.$why.').');
                    }
                },
            ],
            'progress' => function ($total, $downloaded) use ($maxBytes): void {
                if ($total > $maxBytes || $downloaded > $maxBytes) {
                    throw new RuntimeException('The answer is larger than '.$maxBytes.' bytes.');
                }
            },
        ];

        $parts = parse_url($url);
        $host = is_array($parts) ? (string) ($parts['host'] ?? '') : '';
        $ip = $host === '' || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false ? null : (self::addresses($host)[0] ?? null);
        if ($ip !== null && defined('CURLOPT_RESOLVE')) {
            $port = (int) ($parts['port'] ?? (strtolower((string) ($parts['scheme'] ?? '')) === 'https' ? 443 : 80));
            $options['curl'] = [CURLOPT_RESOLVE => [$host.':'.$port.':'.(str_contains($ip, ':') ? '['.$ip.']' : $ip)]];
        }

        return $options;
    }

    /**
     * Every address a host resolves to, IPv4 and IPv6 (a host written as an address is that address).
     *
     * @return list<string>
     */
    public static function addresses(string $host): array
    {
        $host = strtolower(rtrim(trim($host, '[]'), '.'));
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.internal') || str_ends_with($host, '.local')) {
            return ['127.0.0.1'];
        }
        $ips = [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        foreach (is_array($records) ? $records : [] as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($ip) && $ip !== '') {
                $ips[] = $ip;
            }
        }
        foreach (@gethostbynamel($host) ?: [] as $ip) {
            $ips[] = $ip;
        }

        return array_values(array_unique($ips));
    }

    /** Whether an address is on the public internet. */
    public static function isPublic(string $ip): bool
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return false;
        }
        if (strlen($bin) === 16) {
            // an IPv4 address carried in IPv6 (mapped ::ffff:a.b.c.d, NAT64 64:ff9b::a.b.c.d, 6to4 2002:aabb:ccdd::) is that address
            $v4 = match (true) {
                str_starts_with($bin, str_repeat("\0", 10)."\xff\xff") => substr($bin, 12, 4),
                str_starts_with($bin, "\x00\x64\xff\x9b".str_repeat("\0", 8)) => substr($bin, 12, 4),
                str_starts_with($bin, "\x20\x02") => substr($bin, 2, 4),
                str_starts_with($bin, str_repeat("\0", 12)) => substr($bin, 12, 4),
                default => null,
            };
            if ($v4 !== null) {
                return self::isPublic((string) inet_ntop($v4));
            }
            foreach (self::V6 as $range) {
                if (self::inRange($bin, $range)) {
                    return false;
                }
            }

            return true;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        foreach (self::V4 as $range) {
            if (self::inRange($bin, $range)) {
                return false;
            }
        }

        return true;
    }

    private static function inRange(string $bin, string $cidr): bool
    {
        [$net, $bits] = explode('/', $cidr);
        $base = (string) inet_pton($net);
        if (strlen($base) !== strlen($bin)) {
            return false;
        }
        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        if (strncmp($bin, $base, $bytes) !== 0) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($bin[$bytes]) & $mask) === (ord($base[$bytes]) & $mask);
    }
}
