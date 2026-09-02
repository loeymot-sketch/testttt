<?php

namespace App\Support\Backup;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * [GOAL DASHBOARD-CONTRÔLE 2026-09-02 · Sub 4.1 · Codex P1-A]
 *
 * Le résultat du drill de restauration, écrit une fois et lu partout.
 *
 * `backup:verify-restore` (planifié à 5 h) restaure la dernière sauvegarde dans une base
 * jetable, compare les comptes de lignes et vérifie la chaîne NF525. Jusqu'ici son verdict
 * finissait dans `storage/logs/backup-verify-restore.log` — un fichier que personne
 * n'ouvre, et qui n'existe même pas sur cette machine (le drill n'y a jamais tourné). Le
 * cockpit et `/health/ready` ne regardaient QUE la date du fichier `.sql.gz` : une
 * sauvegarde de 2 h totalement corrompue affichait « Tout va bien ».
 *
 * Deux supports, volontairement :
 *  - un FICHIER JSON (`storage/app/backups/restore-drill-last.json`) — survit à un vidage
 *    de cache, lisible à la main pendant un incident ;
 *  - le CACHE — lecture sans I/O disque à chaque affichage du cockpit.
 * La lecture privilégie le cache et retombe sur le fichier. Écrire ne doit JAMAIS faire
 * échouer le drill lui-même : les deux écritures sont au mieux-effort.
 */
final class RestoreDrillResult
{
    public const CACHE_KEY = 'backup:restore_drill:last';

    public const FILE_PATH = 'backups/restore-drill-last.json';

    /** Au-delà, le résultat ne dit plus rien de l'état courant (drill quotidien à 5 h). */
    public const MAX_AGE_HOURS = 48;

    /**
     * @param  array{status:string, verified_at:string, file:?string, sha256:?string, duration_s:?float, reasons:array<int,string>}  $result
     */
    public static function store(array $result): void
    {
        $payload = [
            'status' => (string) ($result['status'] ?? 'unknown'),
            'verified_at' => (string) ($result['verified_at'] ?? now()->toIso8601String()),
            'file' => $result['file'] ?? null,
            'sha256' => $result['sha256'] ?? null,
            'duration_s' => isset($result['duration_s']) ? round((float) $result['duration_s'], 1) : null,
            'reasons' => array_values(array_map('strval', (array) ($result['reasons'] ?? []))),
        ];

        try {
            Cache::forever(self::CACHE_KEY, $payload);
        } catch (\Throwable $e) {
            Log::warning('[restore-drill] cache write failed', ['error' => $e->getMessage()]);
        }

        try {
            Storage::disk('local')->put(
                self::FILE_PATH,
                json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            );
        } catch (\Throwable $e) {
            Log::warning('[restore-drill] file write failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * L'état du drill tel que doivent le lire le cockpit et la sonde de readiness.
     *
     * `status` :
     *   - `unknown` : jamais exécuté, ou résultat illisible — à DIRE, pas à taire ;
     *   - `failed`  : le drill a échoué, avec ses raisons ;
     *   - `stale`   : vert, mais trop vieux pour attester quoi que ce soit aujourd'hui ;
     *   - `green`   : vert et récent.
     *
     * @return array{status:string, verified_at:?string, age_hours:?float, file:?string, sha256:?string, duration_s:?float, reasons:array<int,string>, max_age_hours:int}
     */
    public static function current(): array
    {
        $raw = null;

        try {
            $raw = Cache::get(self::CACHE_KEY);
        } catch (\Throwable $e) {
            $raw = null;
        }

        if (! is_array($raw)) {
            try {
                if (Storage::disk('local')->exists(self::FILE_PATH)) {
                    $decoded = json_decode((string) Storage::disk('local')->get(self::FILE_PATH), true);
                    $raw = is_array($decoded) ? $decoded : null;
                }
            } catch (\Throwable $e) {
                $raw = null;
            }
        }

        $vide = [
            'status' => 'unknown',
            'verified_at' => null,
            'age_hours' => null,
            'file' => null,
            'sha256' => null,
            'duration_s' => null,
            'reasons' => [],
            'max_age_hours' => self::MAX_AGE_HOURS,
        ];

        if ($raw === null) {
            return $vide;
        }

        $verifiedAt = $raw['verified_at'] ?? null;
        $timestamp = $verifiedAt !== null ? strtotime((string) $verifiedAt) : false;

        // Un horodatage illisible ou dans le futur ne prouve rien : `unknown`, pas vert.
        if ($timestamp === false || $timestamp > time() + 60) {
            return array_merge($vide, [
                'file' => $raw['file'] ?? null,
                'reasons' => array_values((array) ($raw['reasons'] ?? [])),
            ]);
        }

        $ageHours = round((time() - $timestamp) / 3600, 2);
        $status = (string) ($raw['status'] ?? 'unknown');

        if ($status !== 'green' && $status !== 'failed') {
            $status = 'unknown';
        } elseif ($status === 'green' && $ageHours > self::MAX_AGE_HOURS) {
            $status = 'stale';
        }

        return [
            'status' => $status,
            'verified_at' => (string) $verifiedAt,
            'age_hours' => $ageHours,
            'file' => $raw['file'] ?? null,
            'sha256' => $raw['sha256'] ?? null,
            'duration_s' => isset($raw['duration_s']) ? (float) $raw['duration_s'] : null,
            'reasons' => array_values(array_map('strval', (array) ($raw['reasons'] ?? []))),
            'max_age_hours' => self::MAX_AGE_HOURS,
        ];
    }

    /** La phrase à afficher quand le drill n'atteste pas une restauration récente et réussie. */
    public static function alerte(array $etat): ?string
    {
        return match ($etat['status']) {
            'green' => null,
            'failed' => 'restauration de vérification ÉCHOUÉE'
                .($etat['reasons'] !== [] ? ' : '.$etat['reasons'][0] : ''),
            'stale' => 'restauration de vérification non rejouée depuis '
                .(int) round(((float) ($etat['age_hours'] ?? 0)) / 24).' jours',
            default => "restauration de vérification jamais mesurée — une sauvegarde non restaurée ne prouve rien",
        };
    }
}
