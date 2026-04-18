<?php

namespace App\Services\Fiscal;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
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
    public function write(array $data): AuditLog
    {
        if (empty($data['action'])) {
            throw new \InvalidArgumentException('AuditLogService::write() requires a non-empty action.');
        }

        $branchId = $this->resolveBranchId($data);
        $userId   = $data['user_id'] ?? (Auth::check() ? (int) Auth::id() : null);
        $payload  = $data['payload'] ?? [];

        $request = request();
        $ip        = $data['ip']         ?? ($request instanceof Request ? $request->ip() : null);
        $userAgent = $data['user_agent'] ?? ($request instanceof Request ? substr((string) $request->userAgent(), 0, 512) : null);
        $sessionId = $data['session_id'] ?? ($request instanceof Request && $request->hasSession() ? $request->session()->getId() : null);

        $prevHash    = $this->lastHashFor($branchId);
        $currentHash = $this->computeHash($branchId, $prevHash, $data['action'], $payload);

        return AuditLog::create([
            'branch_id'    => $branchId,
            'user_id'      => $userId,
            'action'       => (string) $data['action'],
            'resource'     => $data['resource']    ?? null,
            'resource_id'  => $data['resource_id'] ?? null,
            'payload'      => $payload,
            'prev_hash'    => $prevHash,
            'current_hash' => $currentHash,
            'ip'           => $ip,
            'user_agent'   => $userAgent,
            'session_id'   => $sessionId,
        ]);
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
            if ($row->prev_hash !== $expectedPrev) {
                return (int) $row->id;
            }

            $recomputed = $this->computeHash(
                (int) ($row->branch_id ?? 0),
                $row->prev_hash,
                (string) $row->action,
                (array) ($row->payload ?? [])
            );

            if (!hash_equals((string) $row->current_hash, $recomputed)) {
                return (int) $row->id;
            }

            $expectedPrev = $row->current_hash;
        }

        return null;
    }

    /**
     * Expose the canonical hash computation so Z/X reports can chain onto
     * the same algorithm without duplicating crypto code.
     */
    public function computeHash(int $branchId, ?string $prevHash, string $action, array $payload): string
    {
        $canonical = $this->canonicalise($action, $payload);
        $input     = ($prevHash ?? '') . '|' . $canonical;
        return hash_hmac('sha256', $input, $this->secretFor($branchId));
    }

    private function lastHashFor(?int $branchId): ?string
    {
        $query = AuditLog::query()->orderBy('id', 'desc');
        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        }
        $last = $query->value('current_hash');
        return $last ? (string) $last : null;
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
        $configured = Config::get('fiscal.audit_secret');

        if (is_array($configured) && $branchId !== null && isset($configured[$branchId])) {
            return (string) $configured[$branchId];
        }
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        throw new RuntimeException(
            'AuditLogService: fiscal.audit_secret is not configured — '
            . 'refusing to write an unsigned audit row.'
        );
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
                . json_last_error_msg()
            );
        }

        return $json;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function sortRecursive($value)
    {
        if (!is_array($value)) {
            return $value;
        }

        $isList = array_is_list($value);
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = $this->sortRecursive($v);
        }
        if (!$isList) {
            ksort($out);
        }
        return $out;
    }
}
