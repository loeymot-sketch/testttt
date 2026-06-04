<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;

/**
 * @FK-ID FK-RULE-SAFE-REMOTE-HOST
 * @source GOAL ULTRA-FINAL Phase L L7.2 (2026-05-24) — SSRF audit
 * @see reports/test-e2e/goal-2026-05-23/phase-l/L7.2-ssrf-findings.json
 *   - L7-2-F-01 (Printer host fsockopen — port-scan primitive)
 *   - L7-2-F-02 (SMTP MAIL_HOST — internal-VPC probe primitive)
 *
 * Refuses host strings pointing into IPv4/IPv6 ranges that the application
 * MUST NOT open outbound sockets to from an admin-controlled value:
 *
 *   - 127.0.0.0/8        loopback (cross-service probe)
 *   - 169.254.0.0/16     link-local + AWS/GCE/Azure metadata service
 *   - 10.0.0.0/8         RFC1918 private (LAN probe)
 *   - 172.16.0.0/12      RFC1918 private
 *   - 192.168.0.0/16     RFC1918 private
 *   - 0.0.0.0/8          "this" network / unspecified
 *   - 100.64.0.0/10      carrier-grade NAT (CGN, shared by ISPs)
 *   - 224.0.0.0/4        multicast
 *   - 240.0.0.0/4        reserved
 *   - ::1                IPv6 loopback
 *   - fe80::/10          IPv6 link-local
 *   - fc00::/7           IPv6 unique-local
 *
 * Design notes (advisor 2026-05-24):
 *   - Fail-closed on EXPLICIT dangerous IP literals. Garbage strings PASS
 *     here (Mail::send / fsockopen will fail downstream anyway); we do not
 *     brick the request on unparseable input.
 *   - DNS-rebind defense is INTENTIONALLY OUT OF SCOPE (deferred to V1.0.2
 *     per L7.2 findings recommendations P3). fsockopen does not support
 *     CURLOPT_RESOLVE-style binding so a full TOCTOU-safe defense requires
 *     post-resolve socket binding which is more invasive.
 *   - Hostnames (FQDNs containing letters) are passed through — DNS rebind
 *     into a private IP is a known residual risk documented for V1.0.2.
 *   - Used by L2-HEAL-04 (MailRequest) and L2-HEAL-03 (PrinterRequest).
 *
 * Allowlist override (added 2026-05-24 by L2-HEAL-03 for PrinterRequest):
 *   config('security.safe_remote_host_allowlist') = array of IPv4 CIDRs.
 *   When set, an IPv4 literal matching one of those CIDRs PASSES even if
 *   it would otherwise be blocked by isDangerousIpv4(). Used to whitelist
 *   the legit LAN printer subnet (e.g. '192.168.1.0/24') when owner opts
 *   in via SAFE_REMOTE_HOST_ALLOWLIST=192.168.1.0/24 in .env. Empty by
 *   default — V1 LOCAL Le Cayenne ships closed and admin must opt in.
 */
class SafeRemoteHost implements Rule
{
    public string $message = '';

    public function passes($attribute, $value): bool
    {
        if (!is_string($value) || trim($value) === '') {
            // Required-rule handles emptiness. Non-string = let other rules
            // catch it. We only fire on dangerous IP literals.
            return true;
        }

        $host = trim($value);

        // Strip surrounding brackets for IPv6 literals (e.g. "[::1]")
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        // IPv4 literal check
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            // [L2-HEAL-03 2026-05-24] Owner-configured allowlist takes
            // precedence over the blocklist for legit internal printer
            // subnets. Empty by default — opt-in via .env only.
            foreach ((array) config('security.safe_remote_host_allowlist', []) as $cidr) {
                if (is_string($cidr) && $cidr !== '' && $this->ipv4InCidr($host, $cidr)) {
                    return true;
                }
            }

            if ($this->isDangerousIpv4($host)) {
                $this->message = "The :attribute resolves to a forbidden IP range (loopback/link-local/private). "
                    . "Use a public hostname (e.g. smtp.mailgun.org, smtp.sendgrid.net).";

                return false;
            }

            return true;
        }

        // IPv6 literal check
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            if ($this->isDangerousIpv6($host)) {
                $this->message = "The :attribute resolves to a forbidden IPv6 range (loopback/link-local/unique-local). "
                    . "Use a public hostname.";

                return false;
            }

            return true;
        }

        // Not an IP literal — treat as hostname. DNS-rebind defense deferred
        // to V1.0.2 per L7.2 findings recommendations P3.
        return true;
    }

    public function message(): string
    {
        return $this->message;
    }

    /**
     * Match $ip against forbidden IPv4 CIDR ranges.
     */
    private function isDangerousIpv4(string $ip): bool
    {
        $forbiddenRanges = [
            '127.0.0.0/8',     // loopback
            '169.254.0.0/16',  // link-local + cloud metadata
            '10.0.0.0/8',      // RFC1918 private
            '172.16.0.0/12',   // RFC1918 private
            '192.168.0.0/16',  // RFC1918 private
            '0.0.0.0/8',       // this-network / unspecified
            '100.64.0.0/10',   // carrier-grade NAT
            '224.0.0.0/4',     // multicast
            '240.0.0.0/4',     // reserved
        ];

        foreach ($forbiddenRanges as $cidr) {
            if ($this->ipv4InCidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Match $ip against forbidden IPv6 ranges (prefix-based).
     */
    private function isDangerousIpv6(string $ip): bool
    {
        // Normalize via inet_pton for prefix comparison
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }

        // ::1 (loopback) — full 16-byte match against all-zero + trailing 0x01
        if ($packed === "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x01") {
            return true;
        }

        // :: (unspecified) — all zero
        if ($packed === str_repeat("\x00", 16)) {
            return true;
        }

        // fe80::/10  — link-local (first 10 bits = 1111 1110 10)
        // First byte = 0xFE, second byte top 2 bits = 10 (0x80..0xBF)
        if (($packed[0] === "\xfe") && ((ord($packed[1]) & 0xC0) === 0x80)) {
            return true;
        }

        // fc00::/7 — unique-local (first 7 bits = 1111 110)
        // First byte = 0xFC or 0xFD
        if (in_array($packed[0], ["\xfc", "\xfd"], true)) {
            return true;
        }

        // ::ffff:0:0/96 — IPv4-mapped — re-check the embedded IPv4
        if (substr($packed, 0, 12) === "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff") {
            $embedded = inet_ntop("\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff" . substr($packed, 12));
            if (is_string($embedded) && str_starts_with($embedded, '::ffff:')) {
                $v4 = substr($embedded, 7);
                if (filter_var($v4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && $this->isDangerousIpv4($v4)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Pure-PHP IPv4 CIDR containment check (no Symfony IpUtils dependency to
     * keep the rule self-contained and side-effect-free in tests).
     */
    private function ipv4InCidr(string $ip, string $cidr): bool
    {
        if (!str_contains($cidr, '/')) {
            return $ip === $cidr;
        }

        [$subnet, $bits] = explode('/', $cidr, 2);
        $bits = (int) $bits;

        if ($bits < 0 || $bits > 32) {
            return false;
        }

        $ipLong     = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        if ($bits === 0) {
            return true;
        }

        $mask = -1 << (32 - $bits);

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }
}
