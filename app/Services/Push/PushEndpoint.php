<?php

namespace App\Services\Push;

/**
 * [NFC / NOTIFICATIONS 2026-11-20 · audit adverse P1 SSRF] Un point d'accès Web Push est une adresse FOURNIE PAR LE CLIENT vers laquelle
 * le serveur enverra une requête. Sans contrôle, n'importe quel client pouvait faire viser au serveur une adresse interne
 * (https://127.0.0.1, 169.254.169.254, un hôte du réseau privé) ou un hôte qui répond au compte-gouttes pour retenir un worker.
 *
 * Seuls les VRAIS services de notification des navigateurs sont acceptés (liste `config/webpush.php › allowed_hosts`) :
 *   - https obligatoire, port 443 (ou absent), aucun identifiant dans l'adresse, aucune adresse IP littérale ;
 *   - nom d'hôte égal à une entrée de la liste, ou sous-domaine d'une entrée `*.exemple.com` (frontière de point respectée :
 *     `evilgoogleapis.com` n'est PAS `*.googleapis.com`).
 * Réserve de banc local : `webpush.insecure_endpoints` (env WEBPUSH_ALLOW_INSECURE_ENDPOINTS, jamais en production) lève les
 * contraintes de schéma / IP / port pour qu'un faux service de notification local puisse recevoir ; la liste d'hôtes reste vérifiée.
 */
final class PushEndpoint
{
    /** Adresse normalisée (hôte en minuscules, port par défaut retiré) ou null si Guzzle la refuse (ex. `https://x\\.hote.autorise/`). */
    public static function normaliser(string $url): ?string
    {
        try {
            return (string) \GuzzleHttp\Psr7\Utils::uriFor(trim($url));
        } catch (\Throwable) {
            return null;
        }
    }

    public static function estAutorise(string $url): bool
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 2048 || self::normaliser($url) === null) {
            return false; // une adresse que Guzzle refuse ne sera jamais enregistrée (sinon 500 à l'abonnement)
        }
        $p = parse_url($url);
        if ($p === false || empty($p['host'])) {
            return false;
        }
        // Interrupteur de BANC : sans effet hors environnement local / test, même si la variable est posée par erreur ailleurs.
        $insecure = (bool) config('webpush.insecure_endpoints', false) && app()->environment(['local', 'testing']);
        $schema = strtolower((string) ($p['scheme'] ?? ''));
        if (! $insecure && $schema !== 'https') {
            return false;
        }
        if ($insecure && ! in_array($schema, ['http', 'https'], true)) {
            return false;
        }
        if (isset($p['user']) || isset($p['pass'])) {
            return false;
        }
        $hote = strtolower(rtrim((string) $p['host'], '.'));
        if (! $insecure) {
            if (isset($p['port']) && (int) $p['port'] !== 443) {
                return false;
            }
            if (str_starts_with($hote, '[') || filter_var($hote, FILTER_VALIDATE_IP) !== false) {
                return false; // aucune adresse IP littérale, IPv4 ni IPv6
            }
        }
        foreach ((array) config('webpush.allowed_hosts', []) as $motif) {
            $motif = strtolower(trim((string) $motif));
            if ($motif === '') {
                continue;
            }
            if (str_starts_with($motif, '*.')) {
                $suffixe = substr($motif, 1); // « .exemple.com »
                if (strlen($hote) > strlen($suffixe) && str_ends_with($hote, $suffixe)) {
                    return true;
                }
            } elseif ($hote === $motif) {
                return true;
            }
        }

        return false;
    }
}
