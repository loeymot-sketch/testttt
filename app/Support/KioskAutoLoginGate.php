<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Gate de sécurité de l'auto-login borne (logique extraite de master.blade.php
 * pour être testable sans DB).
 *
 * Avant ce heal, le gate faisait `in_array($clientIp, $trustedIps, true)` —
 * exact-match strict. Or une borne CLOUD (serveur OVH, borne distante) présente
 * une IPv6 publique qui TOURNE (privacy extensions) tout en gardant un /64
 * stable. L'exact-match cassait donc à chaque rotation. On délègue le matching
 * à `IpUtils::checkIp`, qui accepte des IP exactes ET des plages CIDR (IPv4 et
 * IPv6) → on peut faire confiance au /64 de la borne, robuste à la rotation.
 *
 * Sécurité inchangée par ailleurs : les identifiants machine ne sont émis que
 * pour un chemin /kiosk* ET (APP_ENV=local OU IP cliente dans l'allowlist).
 * Une liste vide + pas de bypass ⇒ null (formulaire/erreur côté SPA).
 *
 * @see resources/views/master.blade.php (point d'appel)
 * @see tests/Unit/KioskAutoLoginGateResolverTest.php (logique pure)
 * @see tests/Feature/Kiosk/KioskAutoLoginGateTest.php (intégration HTTP)
 */
class KioskAutoLoginGate
{
    /**
     * @param  array<string,mixed>|null  $payload          identifiants machine (config kiosk.spa_payload) ou null
     * @param  bool                       $isKioskPath      request()->is('kiosk*')
     * @param  bool                       $localBypass      APP_ENV=local (config kiosk.auto_login_local_bypass)
     * @param  array<int,string>          $trustedIps       KIOSK_AUTO_LOGIN_TRUSTED_IPS (IP exactes et/ou CIDR)
     * @param  string|null                $clientIp         request()->ip()
     * @param  string|null                $requestSecret    ?machine_key=… de l'URL borne (lien secret)
     * @param  string                     $configuredSecret KIOSK_AUTO_LOGIN_SECRET (vide = chemin secret inactif)
     * @param  bool                       $persistentGrant  cookie HttpOnly chiffré émis après validation du lien machine
     * @return array<string,mixed>|null   le payload si autorisé, sinon null
     */
    public static function resolvePayload(
        ?array $payload,
        bool $isKioskPath,
        bool $localBypass,
        array $trustedIps,
        ?string $clientIp,
        ?string $requestSecret = null,
        string $configuredSecret = '',
        bool $persistentGrant = false,
    ): ?array {
        if (! $isKioskPath || $payload === null) {
            return null;
        }

        if ($localBypass) {
            return $payload;
        }

        // Le grant n'est créé que par le middleware après validation timing-safe
        // du lien machine et arrive via EncryptCookies : une valeur forgée côté
        // navigateur ne peut donc jamais autoriser l'injection des identifiants.
        if ($persistentGrant) {
            return $payload;
        }

        // Lien secret (RÉSEAU-INDÉPENDANT : survit au changement d'IP/box/fibre) —
        // ?machine_key=<secret> == KIOSK_AUTO_LOGIN_SECRET, comparaison timing-safe.
        // Secret configuré vide ⇒ chemin inactif (jamais de bypass par secret vide).
        if (self::matchesMachineSecret($requestSecret, $configuredSecret)) {
            return $payload;
        }

        $list = array_values(array_filter(
            array_map('trim', $trustedIps),
            static fn (string $v): bool => $v !== ''
        ));

        if ($clientIp !== null && $clientIp !== '' && $list !== [] && IpUtils::checkIp($clientIp, $list)) {
            return $payload;
        }

        return null;
    }

    /**
     * [QA_LOOP_NEXT_ACTION_2026-09-29] Pourquoi l'auto-login a été refusé.
     *
     * Le garde ci-dessus est volontairement fermé, et c'est bien. Mais un refus ne
     * laissait AUCUNE trace : la borne affichait « Borne momentanément indisponible »,
     * le HTML portait `kioskAutoLogin: null`, et il fallait remonter la cause à la main
     * — c'est exactement ce qu'a dû faire le rapport de recette du 29/09, en lisant le
     * code et en testant à l'aveugle avec et sans `machine_key`.
     *
     * Cette méthode NE DÉCIDE RIEN : elle nomme la première condition qui a manqué, pour
     * que l'exploitant sache quoi configurer. Aucun secret, aucun identifiant, aucune IP
     * ne sort d'ici — seulement un motif court et stable.
     *
     * @return string|null le motif, ou null si l'accès est accordé
     */
    public static function motifDeRefus(
        ?array $payload,
        bool $isKioskPath,
        bool $localBypass,
        array $trustedIps,
        ?string $clientIp,
        ?string $requestSecret = null,
        string $configuredSecret = '',
        bool $persistentGrant = false,
    ): ?string {
        if (! $isKioskPath) {
            return 'chemin_hors_borne';
        }
        if ($payload === null) {
            // La cause la plus fréquente à la mise en service : identifiants machine
            // absents de la configuration, ou aucune machine borne active en base.
            return 'identifiants_machine_absents';
        }
        if ($localBypass || $persistentGrant || self::matchesMachineSecret($requestSecret, $configuredSecret)) {
            return null;
        }

        $list = array_values(array_filter(
            array_map('trim', $trustedIps),
            static fn (string $v): bool => $v !== ''
        ));

        if ($clientIp !== null && $clientIp !== '' && $list !== [] && IpUtils::checkIp($clientIp, $list)) {
            return null;
        }

        // Distinguer « rien n'est configuré » de « configuré, mais cette borne n'y est
        // pas » : ce sont deux gestes d'exploitation différents.
        $secretConfigure = trim($configuredSecret) !== '';
        if (! $secretConfigure && $list === []) {
            return 'aucune_voie_configuree';
        }
        if (is_string($requestSecret) && $requestSecret !== '') {
            return 'secret_fourni_invalide';
        }

        return 'borne_non_autorisee';
    }

    public static function matchesMachineSecret(?string $requestSecret, string $configuredSecret): bool
    {
        $configuredSecret = trim($configuredSecret);

        return $configuredSecret !== ''
            && is_string($requestSecret)
            && $requestSecret !== ''
            && hash_equals($configuredSecret, $requestSecret);
    }
}
