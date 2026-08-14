<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\Rule;
use Illuminate\Support\Arr;

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
 *   config('security.safe_remote_host_allowlist') = array of entries.
 *   When set, an IPv4 literal matching one of those entries PASSES even if
 *   it would otherwise be blocked by isDangerousIpv4(). Empty by default —
 *   V1 LOCAL Le Cayenne ships closed and admin must opt in.
 *
 * ---------------------------------------------------------------------------
 * HOST+PORT allowlist (owner decision 2026-08-13, GOAL_COMMERCANT_BACKEND_ACCES
 * option (b) "allowlist fermée")
 * ---------------------------------------------------------------------------
 * The real Le Cayenne printer architecture routes EVERY printer through a
 * per-station LOCAL bridge (127.0.0.1:9100 comptoir, :9101 cuisine — see
 * CLAUDE.md). The 127.0.0.0/8 blocklist entry therefore forbids the only
 * address shape the restaurant can actually use. The pre-existing host-ONLY
 * allowlist would have re-opened the exact primitive this rule exists to
 * close: TcpPrinterTransport::send() does a bare fsockopen($host,$port) and
 * reports distinct errors per outcome → a port-scan oracle over all 65535
 * ports of the box, reachable by POS-Operator through testPrint.
 *
 * Entry grammar (comma-separated in SAFE_REMOTE_HOST_ALLOWLIST):
 *
 *     <IPv4 CIDR or bare IPv4>:<port>            e.g. 192.168.1.20/32:9100
 *     <IPv4 CIDR or bare IPv4>:<first>-<last>    e.g. 127.0.0.1/32:9100-9103
 *     <IPv4 CIDR or bare IPv4>                   LEGACY host-only (see below)
 *
 * Decision table — host AND port are decided by the SAME entry:
 *
 *   entry            | port-aware field | verdict
 *   -----------------+------------------+--------------------------------------
 *   CIDR:ports       | yes              | PASS iff host ∈ CIDR AND port ∈ ports
 *   CIDR:ports       | no  (mail_host)  | NO MATCH — a port-scoped entry never
 *                    |                  | unlocks a field whose port is not
 *                    |                  | checked (adding the printer bridge
 *                    |                  | line must not open SMTP to loopback)
 *   CIDR  (legacy)   | yes              | NO MATCH — refused with an explicit
 *                    |                  | message naming the required format.
 *                    |                  | A host-only entry cannot express the
 *                    |                  | closed variant the owner chose, so we
 *                    |                  | fail closed rather than silently
 *                    |                  | granting all 65535 ports.
 *   CIDR  (legacy)   | no  (mail_host)  | PASS iff host ∈ CIDR (unchanged 2026-05-24
 *                    |                  | behaviour — full backward compatibility
 *                    |                  | where no port exists to scope)
 *
 * Malformed entries (bad CIDR, port outside 1-65535, inverted range) are
 * IGNORED — fail-closed, never fail-open.
 *
 * Port-aware mode is opt-in per call site:
 *     new SafeRemoteHost(portField: 'port', defaultPort: 9100)
 * `defaultPort` mirrors the transport default so the rule decides on the port
 * the socket would REALLY target when the field is left blank
 * (TcpPrinterTransport::send() falls back to 9100).
 */
class SafeRemoteHost implements Rule, DataAwareRule
{
    public string $message = '';

    /** Full validator payload — injected by the Validator (DataAwareRule). */
    private array $data = [];

    /**
     * @param string|null $portField   Sibling field holding the port. When null
     *                                 the rule runs in host-only mode (mail_host,
     *                                 AppServiceProvider boot guard).
     * @param int|null    $defaultPort Port the transport uses when the field is
     *                                 absent/blank.
     */
    public function __construct(
        private ?string $portField = null,
        private ?int $defaultPort = null,
    ) {
    }

    /**
     * @param array<string,mixed> $data
     */
    public function setData($data): static
    {
        $this->data = is_array($data) ? $data : [];

        return $this;
    }

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
            // [L2-HEAL-03 2026-05-24 / host+port 2026-08-13] Owner-configured
            // allowlist takes precedence over the blocklist for legit internal
            // printer bridges. Empty by default — opt-in via .env only.
            $verdict = $this->allowlistVerdict($host);

            if ($verdict === true) {
                return true;
            }

            if ($this->isDangerousIpv4($host)) {
                $this->message = "The :attribute resolves to a forbidden IP range (loopback/link-local/private). "
                    . "Use a public hostname (e.g. smtp.mailgun.org, smtp.sendgrid.net).";

                if (is_string($verdict)) {
                    $this->message .= ' ' . $verdict;
                }

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
     * Decide whether the owner allowlist unlocks $ip (and, in port-aware mode,
     * the sibling port) — see the decision table in the class docblock.
     *
     * @return true|string|false  true  = allowlisted (host AND port);
     *                            string = near-miss hint appended to the error
     *                                     message (host matched, port did not,
     *                                     or the entry is legacy host-only);
     *                            false = no entry matched at all.
     */
    private function allowlistVerdict(string $ip): bool|string
    {
        $portAware = $this->portField !== null;
        $port      = $portAware ? $this->resolvePort() : null;
        $hint      = false;

        foreach ((array) config('security.safe_remote_host_allowlist', []) as $entry) {
            if (!is_string($entry) || trim($entry) === '') {
                continue;
            }

            $parsed = $this->parseAllowlistEntry(trim($entry));
            if ($parsed === null) {
                // Malformed entry — ignored (fail-closed).
                continue;
            }

            [$cidr, $portRange] = $parsed;

            if (!$this->ipv4InCidr($ip, $cidr)) {
                continue;
            }

            if (!$portAware) {
                // Host-only field (mail_host, MAIL_HOST boot guard): only a
                // legacy host-only entry unlocks it. A port-scoped entry is
                // scoped to a port-checked field by construction.
                if ($portRange === null) {
                    return true;
                }

                $hint = $hint ?: sprintf(
                    'The SAFE_REMOTE_HOST_ALLOWLIST entry "%s" is port-scoped and only applies to '
                    . 'fields whose port is validated (printer host). Add a host-only entry if this '
                    . 'field must be allowlisted.',
                    trim($entry)
                );

                continue;
            }

            if ($portRange === null) {
                // Legacy host-only entry on a port-aware field: REFUSED. It
                // would grant all 65535 ports and re-open the fsockopen
                // port-scan oracle — the exact thing option (b) closes.
                $hint = sprintf(
                    'The SAFE_REMOTE_HOST_ALLOWLIST entry "%s" declares no port and is no longer '
                    . 'accepted for this field: it would allow every one of the 65535 ports. '
                    . 'Use the host+port format, e.g. SAFE_REMOTE_HOST_ALLOWLIST=%s:9100-9103.',
                    trim($entry),
                    $cidr
                );

                continue;
            }

            if ($port !== null && $port >= $portRange[0] && $port <= $portRange[1]) {
                return true;
            }

            $hint = sprintf(
                'Host %s is allowlisted but only on port(s) %s — port %s is refused.',
                $ip,
                $portRange[0] === $portRange[1] ? (string) $portRange[0] : $portRange[0] . '-' . $portRange[1],
                $port === null ? '(unreadable)' : (string) $port
            );
        }

        return $hint;
    }

    /**
     * Resolve the port this host would really be dialled on. Returns null when
     * the value cannot be read as a valid port — fail-closed: an unreadable
     * port never matches an allowlist range (the `integer` rule on the port
     * field reports the shape error separately).
     */
    private function resolvePort(): ?int
    {
        $raw = Arr::get($this->data, (string) $this->portField);

        if ($raw === null || $raw === '' || $raw === []) {
            // Field omitted/blank → the transport falls back to its default
            // (TcpPrinterTransport::send() uses 9100), so that is the port the
            // socket would really target.
            return $this->defaultPort;
        }

        if (is_bool($raw) || is_array($raw) || !is_numeric($raw) || (int) $raw != $raw) {
            return null;
        }

        $port = (int) $raw;

        return ($port >= 1 && $port <= 65535) ? $port : null;
    }

    /**
     * Parse one allowlist entry into [cidr, portRange|null].
     *
     *   "127.0.0.1/32:9100-9103" → ['127.0.0.1/32', [9100, 9103]]
     *   "192.168.1.20:9100"      → ['192.168.1.20', [9100, 9100]]
     *   "192.168.1.0/24"         → ['192.168.1.0/24', null]  (legacy)
     *
     * Returns null for anything malformed (fail-closed). IPv6 is intentionally
     * unsupported in the allowlist (V1 LOCAL = IPv4-only printer LAN), which is
     * what makes the single ':' separator unambiguous.
     */
    private function parseAllowlistEntry(string $entry): ?array
    {
        $portRange = null;

        if (str_contains($entry, ':')) {
            [$cidr, $spec] = explode(':', $entry, 2);
            $cidr = trim($cidr);
            $spec = trim($spec);

            if (!preg_match('/^(\d{1,5})(?:-(\d{1,5}))?$/', $spec, $m)) {
                return null;
            }

            $first = (int) $m[1];
            $last  = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : $first;

            if ($first < 1 || $first > 65535 || $last < 1 || $last > 65535 || $last < $first) {
                return null;
            }

            $portRange = [$first, $last];
        } else {
            $cidr = $entry;
        }

        if (!$this->isValidIpv4Cidr($cidr)) {
            return null;
        }

        return [$cidr, $portRange];
    }

    /**
     * Structural validation of an allowlist host part: bare IPv4 or a.b.c.d/n.
     */
    private function isValidIpv4Cidr(string $cidr): bool
    {
        if ($cidr === '') {
            return false;
        }

        if (!str_contains($cidr, '/')) {
            return (bool) filter_var($cidr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
        }

        [$subnet, $bits] = explode('/', $cidr, 2);

        return (bool) filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            && ctype_digit($bits)
            && (int) $bits >= 0
            && (int) $bits <= 32;
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
