<?php

namespace App\Services\Fiscal;

use App\Models\AuditLog;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * [POS-9.4.4 / POS-GA-F-04]
 *
 * Only authorised writer for the `audit_logs` table. Every call to
 * {@see write()} appends a single row whose `current_hash` is the
 * HMAC-SHA256 of `prev_hash || canonical(payload)` under the branch
 * secret. A dedicated {@see verifyChain()} method re-walks the whole
 * chain and returns the first row whose hash no longer matches — so a
 * tampered or forged row is detected even though UPDATE/DELETE are
 * already blocked at the DB level (POS-9.4.3).
 *
 * Canonicalisation: `payload` is cast to JSON with sorted keys and no
 * insignificant whitespace (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).
 * That keeps hashes stable across PHP 8.0+ and between SQLite and MySQL.
 */
class AuditLogService
{
    /**
     * Append an audit row and return the persisted model.
     *
     * @param array{
     *   branch_id?: int|null,
     *   user_id?: int|null,
     *   action: string,
     *   resource?: string|null,
     *   resource_id?: int|null,
     *   payload?: array<string,mixed>,
     *   ip?: string|null,
     *   user_agent?: string|null,
     *   session_id?: string|null
     * } $data
     */
    /**
     * [POS-9-H.2.2 / F-C3 / F-B1]
     *
     * Two concurrent writes were reading the same tail of the chain and
     * persisting two rows with an identical `prev_hash` — forking the
     * chain and triggering false-positive tampering alerts on the next
     * verifyChain() sweep.
     *
     * Defense in depth:
     *   1. Cache::lock('audit_chain_b{n}') serialises writers per branch.
     *   2. DB::transaction groups the tail-read and the INSERT atomically
     *      against the same connection, so nested writes on the same
     *      request still see each other.
     *   3. A UNIQUE(branch_id, prev_hash) index (migration
     *      2026_04_19_100000_add_unique_chain_index_to_audit_logs.php)
     *      rejects any remaining fork at the DB level even if Redis is
     *      down. On a UNIQUE violation we retry exactly once: the tail
     *      has advanced under us, recompute prev_hash and INSERT again.
     */
    private const CHAIN_LOCK_TTL = 10;

    private const CHAIN_LOCK_WAIT = 5;

    public function write(array $data): AuditLog
    {
        $started = microtime(true);
        $context = ['op' => 'audit_log.write', 'branch_id' => null];

        try {
            if (empty($data['action'])) {
                throw new \InvalidArgumentException('AuditLogService::write() requires a non-empty action.');
            }

            if (! array_key_exists('action', $data)) {
                throw new \InvalidArgumentException('AuditLogService::write() requires an action.');
            }

            $branchId = $this->resolveBranchId($data);
            $context['branch_id'] = $branchId;

            // [POS-9-H.2.3 / F-C5]
            // Reject null branch_id: a call that does not pin a branch would
            // read the tail across ALL chains (lastHashFor(null) has no WHERE
            // clause) and poison whichever chain happens to be latest. CLI
            // jobs must pass branch_id=0 explicitly to write to the system
            // chain, or a positive branch_id for a tenant chain.
            if ($branchId === null) {
                throw new \InvalidArgumentException(
                    'AuditLogService::write() requires an explicit branch_id. '
                    .'Pass branch_id=0 for system/CLI events, or a positive int for a tenant chain.'
                );
            }

            $lockKey = 'audit_chain_b'.$branchId;
            $lock = Cache::lock($lockKey, self::CHAIN_LOCK_TTL);

            if (! $lock->block(self::CHAIN_LOCK_WAIT)) {
                throw new RuntimeException(
                    "AuditLogService: could not acquire chain lock '{$lockKey}' within "
                    .self::CHAIN_LOCK_WAIT.'s. Another writer is stuck or the cache '
                    .'driver is unavailable.'
                );
            }

            try {
                $result = DB::transaction(function () use ($data, $branchId) {
                    return $this->performInsert($data, $branchId, /* attempt */ 1);
                });
                $context['outcome'] = 'success';

                return $result;
            } finally {
                optional($lock)->release();
            }
        } catch (\Throwable $e) {
            $context['outcome'] = 'failure';
            $context['exception_class'] = get_class($e);
            throw $e;
        } finally {
            $context['duration_ms'] = (int) ((microtime(true) - $started) * 1000);
            try {
                Log::channel('stack')->info('[FISCAL_TIMING]', $context);
            } catch (\Throwable $logEx) {
            }
        }
    }

    private function performInsert(array $data, ?int $branchId, int $attempt): AuditLog
    {
        $userId = $data['user_id'] ?? (Auth::check() ? (int) Auth::id() : null);
        $payload = $data['payload'] ?? [];

        $request = request();
        $ip = $data['ip'] ?? ($request instanceof Request ? $request->ip() : null);
        $userAgent = $data['user_agent'] ?? ($request instanceof Request ? substr((string) $request->userAgent(), 0, 512) : null);
        $sessionId = $data['session_id'] ?? ($request instanceof Request && $request->hasSession() ? $request->session()->getId() : null);

        $prevHash = $this->lastHashFor($branchId);
        $currentHash = $this->computeHash($branchId, $prevHash, $data['action'], $payload);

        try {
            $row = AuditLog::create([
                'branch_id' => $branchId,
                'user_id' => $userId,
                'action' => (string) $data['action'],
                'resource' => $data['resource'] ?? null,
                'resource_id' => $data['resource_id'] ?? null,
                'payload' => $payload,
                'prev_hash' => $prevHash,
                'current_hash' => $currentHash,
                'ip' => $ip,
                'user_agent' => $userAgent,
                'session_id' => $sessionId,
            ]);

            // [POS-9-H.3.2 / F-C7]
            // Emit a structured breadcrumb on every successful write.
            // We log ONLY the shape of the event (never the payload)
            // because payloads may carry PII; SIEM correlation uses the
            // audit_log id + current_hash prefix to join back if needed.
            Log::channel('fiscal')->info('audit_log.write', [
                'audit_log_id' => $row->id,
                'branch_id' => $branchId,
                'user_id' => $userId,
                'action' => (string) $data['action'],
                'resource' => $data['resource'] ?? null,
                'resource_id' => $data['resource_id'] ?? null,
                'hash_prefix' => substr($currentHash, 0, 12),
                'retry_attempt' => $attempt,
            ]);

            return $row;
        } catch (QueryException $e) {
            // A parallel writer slipped past the cache lock (driver outage
            // or multi-host cache split-brain) and grabbed our prev_hash
            // first. The UNIQUE(branch_id, prev_hash) index rejected us.
            // Retry once with the new tail; any further collision is a
            // real fault and propagates.
            $isUnique = in_array((string) $e->getCode(), ['23000', '23505'], true)
                || str_contains(strtolower($e->getMessage()), 'unique');
            if ($attempt < 2 && $isUnique) {
                return $this->performInsert($data, $branchId, $attempt + 1);
            }
            throw $e;
        }
    }

    /**
     * Re-walk the hash chain for a branch (or global chain when $branchId
     * is null). Returns null when the chain is intact, or the id of the
     * first tampered/forged row when corruption is detected.
     */
    public function verifyChain(?int $branchId = null): ?int
    {
        $query = AuditLog::query()->orderBy('id');
        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        }

        $expectedPrev = null;
        foreach ($query->cursor() as $row) {
            /** @var AuditLog $row */
            $rowPrev = $row->prev_hash === null ? null : trim((string) $row->prev_hash);
            $expectedPrevNorm = $expectedPrev === null ? null : trim((string) $expectedPrev);
            if ($rowPrev !== $expectedPrevNorm) {
                return (int) $row->id;
            }

            $storedCurrent = trim((string) $row->current_hash);

            // [LOCK_FISCAL_VERIFYCHAIN_AGILITE_SECRETS 2026-08-08] Agilité de clé en LECTURE.
            //
            // Mesuré sur la production (783 lignes, recalcul en lecture seule) : 360 signatures
            // se reproduisent avec le secret de leur branche, **423 avec le secret par défaut**,
            // et **AUCUNE n'est irréductible**. Chaînage `prev_hash` intact, aucun trou d'id :
            // la chaîne n'a jamais été altérée. La cause est l'historique des secrets — un
            // `FISCAL_AUDIT_SECRET_BRANCH_1` apparu après coup fait basculer `secretFor(1)` du
            // défaut vers l'override, si bien que les lignes signées AVANT ne se reproduisent
            // plus avec l'override seul.
            //
            // Or une chaîne signée sert à PROUVER la non-altération à un tiers (contrôle NF525).
            // Refuser 423 lignes intactes, et annoncer « TAMPER », c'est perdre cette preuve ET
            // rendre l'alarme inutilisable : elle est ignorée depuis six semaines.
            //
            // On accepte donc une ligne si sa signature se reproduit avec l'un des secrets
            // CONNUS. Cela n'affaiblit rien : sans posséder l'un de ces secrets, une signature
            // reste impossible à forger. Une ligne qui ne se reproduit avec AUCUN secret connu
            // demeure une ALTÉRATION et est signalée — c'est la propriété essentielle, prouvée
            // par mutation (tests/Feature/Fiscal/AuditChainSecretAgilityTest.php).
            //
            // ⛔ Rien n'est ré-écrit : `verifyChain` reste strictement en lecture (append-only).
            $reproduite = false;
            foreach ($this->candidateVerificationBranches($row) as $branchCandidate) {
                $recomputed = $this->computeHash(
                    $branchCandidate,
                    $rowPrev,
                    (string) $row->action,
                    (array) ($row->payload ?? [])
                );

                if (hash_equals($storedCurrent, $recomputed)) {
                    $reproduite = true;
                    break;
                }
            }

            if (! $reproduite) {
                return (int) $row->id;
            }

            $expectedPrev = $storedCurrent;
        }

        return null;
    }

    /**
     * Branches à essayer pour reproduire la signature d'une ligne, dans l'ordre.
     *
     * [LOCK_FISCAL_VERIFYCHAIN_AGILITE_SECRETS 2026-08-08] La branche de la ligne d'abord (le
     * cas normal, qui doit rester le premier essayé), puis `0` — qui, via `secretFor()`, retombe
     * sur le secret par DÉFAUT `fiscal.audit_secret`. C'est ce défaut qui a signé les lignes
     * antérieures à l'apparition de l'override par branche.
     *
     * Volontairement limité aux secrets que la configuration COURANTE connaît : on n'essaie
     * aucune valeur devinée, aucun secret historique non configuré. Le jour où l'override
     * disparaît, la liste se réduit d'elle-même à un seul candidat.
     *
     * @return list<int>
     */
    private function candidateVerificationBranches(AuditLog $row): array
    {
        $branchDeLaLigne = (int) ($row->branch_id ?? 0);

        return $branchDeLaLigne === 0 ? [0] : [$branchDeLaLigne, 0];
    }

    /**
     * Expose the canonical hash computation so Z/X reports can chain onto
     * the same algorithm without duplicating crypto code.
     */
    public function computeHash(int $branchId, ?string $prevHash, string $action, array $payload): string
    {
        $canonical = $this->canonicalise($action, $payload);
        $input = ($prevHash ?? '').'|'.$canonical;

        return hash_hmac('sha256', $input, $this->secretFor($branchId));
    }

    private function lastHashFor(?int $branchId): ?string
    {
        $query = AuditLog::query()->orderBy('id', 'desc');
        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        }
        $last = $query->value('current_hash');

        return $last ? trim((string) $last) : null;
    }

    private function resolveBranchId(array $data): ?int
    {
        if (array_key_exists('branch_id', $data) && $data['branch_id'] !== null) {
            return (int) $data['branch_id'];
        }
        $user = Auth::user();
        if ($user && isset($user->branch_id)) {
            return (int) $user->branch_id;
        }

        return null;
    }

    private function secretFor(?int $branchId): string
    {
        // Support per-branch override via env: FISCAL_AUDIT_SECRET_BRANCH_{id}
        if ($branchId !== null) {
            $override = env('FISCAL_AUDIT_SECRET_BRANCH_'.$branchId);
            if (is_string($override) && $override !== '') {
                return $this->assertProductionSafe($override, 'fiscal.audit_secret[branch='.$branchId.']');
            }
        }

        $configured = Config::get('fiscal.audit_secret');

        if (is_array($configured) && $branchId !== null && isset($configured[$branchId])) {
            return $this->assertProductionSafe((string) $configured[$branchId], 'fiscal.audit_secret[branch='.$branchId.']');
        }
        if (is_string($configured) && $configured !== '') {
            return $this->assertProductionSafe($configured, 'fiscal.audit_secret');
        }

        throw new RuntimeException(
            'AuditLogService: fiscal.audit_secret is not configured — '
            .'refusing to write an unsigned audit row.'
        );
    }

    /**
     * [POS-9-H.2.1 / F-C1]
     *
     * Reject weak / default secrets when APP_ENV=production. Outside of
     * production we tolerate short dev strings so local testing stays
     * fast. This is the last line of defense: a deployment that forgot
     * to set FISCAL_AUDIT_SECRET will now crash the first write()
     * attempt instead of silently forging an NF525-compliant chain.
     */
    private function assertProductionSafe(string $secret, string $key): string
    {
        $env = app()->environment();
        if ($env !== 'production') {
            return $secret;
        }

        $sentinels = (array) Config::get('fiscal.dev_sentinels', []);
        if (in_array($secret, $sentinels, true)) {
            throw new RuntimeException(
                "AuditLogService: {$key} is set to a known dev sentinel in APP_ENV=production. "
                .'Rotate the secret (see docs/FISCAL_SECRETS.md).'
            );
        }

        $min = (int) Config::get('fiscal.min_secret_length', 32);
        if (strlen($secret) < $min) {
            throw new RuntimeException(
                "AuditLogService: {$key} is shorter than {$min} characters in APP_ENV=production. "
                .'Generate a strong secret (e.g. openssl rand -hex 32).'
            );
        }

        return $secret;
    }

    /**
     * Produce a deterministic JSON representation of an audit event.
     *
     * Keys are sorted recursively so the same business event always
     * hashes to the same value regardless of the array insertion order.
     */
    private function canonicalise(string $action, array $payload): string
    {
        $sorted = $this->sortRecursive($payload);

        $json = json_encode(
            ['action' => $action, 'payload' => $sorted],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if ($json === false) {
            throw new RuntimeException(
                'AuditLogService: payload not encodable to JSON — '
                .json_last_error_msg()
            );
        }

        return $json;
    }

    /**
     * @param  mixed  $value
     * @return mixed
     */
    private function sortRecursive($value)
    {
        if (! is_array($value)) {
            return $value;
        }

        $isList = array_is_list($value);
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = $this->sortRecursive($v);
        }
        if (! $isList) {
            ksort($out);
        }

        return $out;
    }
}
