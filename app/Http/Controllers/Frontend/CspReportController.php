<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * K-9 ADR-5 — CSP report endpoint.
 *
 * Route : POST /api/frontend/csp-report
 *
 * Endpoint anonyme (CSP natif du navigateur envoie sans auth). Throttle 20/min
 * par IP pour éviter le flood. **Log-only** vers canal `observability` (ADR-4)
 * avec catégorie `csp_violation` — aucune persistance DB (volume imprévisible,
 * rétention gérée par le daily rotate Monolog 90j).
 *
 * Payload attendu (format navigateur standard, `Content-Type: application/csp-report`) :
 *  {
 *    "csp-report": {
 *      "document-uri": "...",
 *      "referrer": "...",
 *      "violated-directive": "...",
 *      "effective-directive": "...",
 *      "original-policy": "...",
 *      "blocked-uri": "...",
 *      "status-code": 0,
 *      "source-file": "...",
 *      "line-number": 0,
 *      "column-number": 0
 *    }
 *  }
 *
 * Toujours HTTP 204 (No Content) — pas de réponse exploitable pour le navigateur.
 * Invariants :
 *  - Jamais persisté en DB (volume).
 *  - Pas de PII : on NE journalise PAS le body intégral si des query params
 *    sensibles apparaissent dans `document-uri` (ex : `?token=...`).
 *  - `Log::withContext` actif via `CorrelationIdMiddleware` (si header présent)
 *    donc le log observability agrège déjà la requête.
 */
class CspReportController extends Controller
{
    /** Masque les query params sensibles dans les URLs loggées. */
    private const SENSITIVE_QUERY_KEYS = [
        'token', 'password', 'email', 'phone', 'pin', 'code', 'api_key',
    ];

    public function store(Request $request): JsonResponse
    {
        $report = $request->input('csp-report');

        if (!is_array($report)) {
            // Payload malformé : on log minimalement sans révéler le body.
            Log::channel('observability')->warning('csp_violation.malformed', [
                'category' => 'csp_violation',
                'shape'    => 'invalid',
                'ip'       => $request->ip(),
            ]);
            return response()->json(null, 204);
        }

        $safe = [
            'category'            => 'csp_violation',
            'document_uri'        => $this->sanitizeUrl($report['document-uri'] ?? null),
            'referrer'            => $this->sanitizeUrl($report['referrer'] ?? null),
            'violated_directive'  => self::truncate($report['violated-directive'] ?? null, 120),
            'effective_directive' => self::truncate($report['effective-directive'] ?? null, 120),
            'blocked_uri'         => $this->sanitizeUrl($report['blocked-uri'] ?? null),
            'status_code'         => isset($report['status-code']) ? (int) $report['status-code'] : null,
            'source_file'         => $this->sanitizeUrl($report['source-file'] ?? null),
            'line_number'         => isset($report['line-number']) ? (int) $report['line-number'] : null,
            'column_number'       => isset($report['column-number']) ? (int) $report['column-number'] : null,
            'user_agent'          => self::truncate((string) $request->userAgent(), 300),
            'ip'                  => $request->ip(),
        ];

        Log::channel('observability')->info('csp_violation', $safe);

        return response()->json(null, 204);
    }

    /**
     * Retire les query params sensibles tout en gardant l'URL auditable.
     * Exemple : https://kiosk/api?token=abcd → https://kiosk/api?token=***REDACTED***
     */
    private function sanitizeUrl(?string $url): ?string
    {
        if (!$url || !is_string($url)) {
            return null;
        }
        $url = self::truncate($url, 1000);
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['query'])) {
            return $url;
        }
        parse_str((string) $parts['query'], $q);
        foreach (self::SENSITIVE_QUERY_KEYS as $k) {
            if (array_key_exists($k, $q)) {
                $q[$k] = '***REDACTED***';
            }
        }
        $parts['query'] = http_build_query($q);

        $scheme = isset($parts['scheme']) ? $parts['scheme'].'://' : '';
        $host   = $parts['host']  ?? '';
        $port   = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path   = $parts['path']  ?? '';
        $query  = $parts['query'] ? '?'.$parts['query'] : '';
        $frag   = isset($parts['fragment']) ? '#'.$parts['fragment'] : '';
        return $scheme.$host.$port.$path.$query.$frag;
    }

    private static function truncate(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        return mb_substr($value, 0, $max);
    }
}
