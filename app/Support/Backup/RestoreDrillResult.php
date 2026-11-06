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

    /**
     * Au-delà, le résultat ne dit plus rien de l'état courant (drill quotidien à 5 h).
     *
     * [GOAL G4 2026-09-03 · T4.3 · défaut V-12] 48 → 26. Ce seuil valait 48 h pendant que
     * TOUT le reste du contrat de fraîcheur parle de 26 h : `HealthController::checkBackupAge`
     * (`> 26`), l'alerte de fraîcheur du cockpit (`> 26`), et le `attendu_max_h` publié.
     * Un drill de 27 h était donc « vert » sur les deux surfaces alors que la sauvegarde du
     * même âge y était déjà déclarée en retard. Deux seuils pour un seul fait, c'est-à-dire
     * aucun seuil. Le drill tourne toutes les 24 h : 26 h laisse la même marge de deux heures
     * que pour la sauvegarde elle-même.
     */
    public const MAX_AGE_HOURS = 26;

    /**
     * [GOAL G4 2026-09-03 · T4.1 · défaut V-08] Le drill a réussi — sur un AUTRE fichier
     * que la sauvegarde courante. Ce n'est ni un succès (il n'atteste pas ce qu'on garde)
     * ni un échec (le drill lui-même n'a rien raté). C'est une absence de preuve, et elle
     * doit se dire.
     */
    public const STATUT_AUTRE_FICHIER = 'autre_fichier';

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

    /**
     * Le motif de la chaîne de sauvegarde automatique.
     *
     * [GOAL G4 2026-09-03 · suite de T4.1] Trois surfaces lisaient ce dossier avec DEUX
     * motifs : le cockpit et `/health/ready` prenaient le plus récent `*.sql.gz`, la
     * commande `backup:verify-restore` restaurait le plus récent `daily-*.sql.gz`. Un seul
     * dump manuel plus récent qu'une quotidienne suffisait donc à rendre le rapprochement
     * fichier ↔ drill **structurellement impossible** : l'écran désignait un fichier que le
     * drill ne testerait jamais. Le symptôme n'est pas un faux vert, c'est un rouge
     * permanent — et un rouge permanent, on cesse de le regarder.
     *
     * La règle : la garantie de santé porte sur la chaîne AUTOMATIQUE. Un `pre-*.sql.gz`
     * déposé à la main avant une migration n'en fait pas partie et ne doit pas masquer une
     * quotidienne en retard.
     */
    public const MOTIF_SAUVEGARDE = 'daily-*.sql.gz';

    /**
     * Le dossier de la chaîne quotidienne. Un seul endroit le nomme.
     */
    public static function dossierSauvegardes(): string
    {
        return storage_path('backups'.DIRECTORY_SEPARATOR.'db-daily');
    }

    /**
     * [GOAL G4 2026-09-03 · T4.1 · défaut V-08] Le chemin de la sauvegarde qui serait
     * restaurée aujourd'hui : la sauvegarde quotidienne la plus récente.
     *
     * Une seule règle, un seul endroit — le cockpit et `/health/ready` désignaient le
     * « dernier fichier » chacun de leur côté.
     */
    public static function cheminSauvegardeCourante(): ?string
    {
        $dossier = self::dossierSauvegardes();
        if (! is_dir($dossier)) {
            return null;
        }

        $fichiers = @glob($dossier.DIRECTORY_SEPARATOR.self::MOTIF_SAUVEGARDE) ?: [];
        if ($fichiers === []) {
            return null;
        }

        return self::plusRecent($fichiers);
    }

    /**
     * Le plus récent d'une liste de candidats, par date de fichier — pas par le nom, qui
     * ment dès que l'horloge dérive.
     *
     * Primitive pure, partagée avec `BackupVerifyRestoreCommand::pickNewest()` pour que les
     * deux surfaces trient à l'identique. La carte de dates permet de l'éprouver sans
     * toucher au disque.
     *
     * @param  list<string>  $candidats
     * @param  array<string,int>|null  $dates  chemin => date, pour les bancs
     */
    public static function plusRecent(array $candidats, ?array $dates = null): ?string
    {
        if ($candidats === []) {
            return null;
        }

        $dateDe = static function (string $chemin) use ($dates): int {
            if ($dates !== null) {
                return (int) ($dates[$chemin] ?? 0);
            }

            return (int) (@filemtime($chemin) ?: 0);
        };

        usort($candidats, static fn ($a, $b) => $dateDe($b) <=> $dateDe($a));

        return $candidats[0];
    }

    /**
     * L'empreinte SHA-256 d'un fichier de sauvegarde, mémorisée par (nom, date, taille).
     *
     * Le cockpit est interrogé en boucle : rehacher un dump de plusieurs centaines de Mo à
     * chaque affichage serait une façon coûteuse de rendre l'écran inutilisable. La clé
     * inclut la date et la taille, donc un fichier réécrit sous le MÊME nom produit une
     * autre clé — c'est exactement le cas que le rapprochement doit attraper.
     */
    public static function empreinteDe(?string $chemin): ?string
    {
        if ($chemin === null || ! is_file($chemin) || ! is_readable($chemin)) {
            return null;
        }

        $cle = sprintf(
            'backup:sha256:%s:%d:%d',
            basename($chemin),
            (int) @filemtime($chemin),
            (int) @filesize($chemin)
        );

        try {
            $memo = Cache::get($cle);
            if (is_string($memo) && $memo !== '') {
                return $memo;
            }
        } catch (\Throwable $e) {
            // Cache indisponible : on recalcule, on ne renonce pas à la preuve.
        }

        $empreinte = @hash_file('sha256', $chemin);
        if (! is_string($empreinte) || $empreinte === '') {
            return null;
        }

        try {
            Cache::put($cle, $empreinte, now()->addDays(30));
        } catch (\Throwable $e) {
            Log::warning('[restore-drill] sha256 cache write failed', ['error' => $e->getMessage()]);
        }

        return $empreinte;
    }

    /**
     * [GOAL G4 2026-09-03 · T4.1 · défaut V-08] Rapproche le verdict du drill de LA
     * sauvegarde que l'on garde aujourd'hui.
     *
     * Le cockpit publiait côte à côte le dernier fichier et le dernier résultat de
     * restauration sans jamais vérifier qu'ils parlent du même fichier. Scénario réel : le
     * drill de 5 h remonte A et écrit « vert » ; la sauvegarde de 3 h du lendemain produit
     * B, corrompu. Jusqu'à la nuit suivante, l'écran affirme qu'un fichier jamais restauré
     * l'a été — et c'est précisément le seul écran qui protège d'une perte de données.
     *
     * Le nom ET l'empreinte sont persistés tous les deux par `store()` : il ne manquait que
     * la comparaison. Un vert ne survit qu'aux deux.
     *
     * @param  array<string,mixed>  $etat  la sortie de `current()`
     * @param  ?string  $cheminCourant  la sauvegarde la plus récente sur le disque
     * @return array<string,mixed>
     */
    public static function rapprocher(array $etat, ?string $cheminCourant): array
    {
        // Un drill déjà rouge, périmé ou jamais joué dit déjà ce qu'il faut : le rapprocher
        // ne ferait que remplacer une raison exacte par une raison plus vague.
        if (($etat['status'] ?? null) !== 'green') {
            return $etat;
        }

        $nomCourant = $cheminCourant !== null ? basename($cheminCourant) : null;
        $nomDrill = $etat['file'] ?? null;

        if ($nomCourant === null) {
            $ecart = sprintf(
                'la restauration vérifiée porte sur « %s », mais plus aucune sauvegarde n\'est présente',
                $nomDrill ?? 'un fichier non nommé'
            );
        } elseif ($nomDrill === null || $nomDrill === '') {
            $ecart = sprintf(
                'la restauration vérifiée ne nomme aucun fichier : rien ne prouve qu\'elle porte sur « %s »',
                $nomCourant
            );
        } elseif ($nomDrill !== $nomCourant) {
            $ecart = sprintf(
                'la restauration vérifiée porte sur « %s », pas sur la dernière sauvegarde « %s »',
                $nomDrill,
                $nomCourant
            );
        } else {
            // Même nom ne veut pas dire même contenu : la rotation quotidienne réécrit
            // `daily-<date>.sql.gz` sous le même nom.
            $empreinteDrill = $etat['sha256'] ?? null;
            $empreinteCourante = self::empreinteDe($cheminCourant);

            if (! is_string($empreinteDrill) || $empreinteDrill === '' || $empreinteCourante === null) {
                $ecart = sprintf(
                    'empreinte SHA-256 indisponible pour « %s » : la restauration vérifiée ne peut pas être rapprochée de ce fichier',
                    $nomCourant
                );
            } elseif (! hash_equals($empreinteDrill, $empreinteCourante)) {
                $ecart = sprintf(
                    '« %s » a été réécrit depuis la restauration vérifiée : empreinte SHA-256 différente',
                    $nomCourant
                );
            } else {
                $ecart = null;
            }
        }

        if ($ecart === null) {
            return $etat;
        }

        return array_merge($etat, [
            'status' => self::STATUT_AUTRE_FICHIER,
            'reasons' => array_values(array_merge([$ecart], (array) ($etat['reasons'] ?? []))),
        ]);
    }

    /** La phrase à afficher quand le drill n'atteste pas une restauration récente et réussie. */
    public static function alerte(array $etat): ?string
    {
        return match ($etat['status']) {
            'green' => null,
            'failed' => 'restauration de vérification ÉCHOUÉE'
                .($etat['reasons'] !== [] ? ' : '.$etat['reasons'][0] : ''),
            // [G4 · T4.3] Le seuil est passé à 26 h : compter en jours en dessous de 48 h
            // affichait « depuis 1 jours » pour un drill de 27 h — une phrase fausse et
            // rassurante à la fois.
            'stale' => 'restauration de vérification non rejouée depuis '.self::depuis($etat),
            self::STATUT_AUTRE_FICHIER => $etat['reasons'][0]
                ?? 'la restauration vérifiée ne porte pas sur la dernière sauvegarde',
            default => "restauration de vérification jamais mesurée — une sauvegarde non restaurée ne prouve rien",
        };
    }

    /** « 27 h » ou « 3 jours » — l'unité qui décrit honnêtement l'écart. */
    private static function depuis(array $etat): string
    {
        $heures = (float) ($etat['age_hours'] ?? 0);

        return $heures < 48
            ? ((int) round($heures)).' h'
            : ((int) round($heures / 24)).' jours';
    }
}
