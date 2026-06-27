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
     * @return array<string,mixed>|null   le payload si autorisé, sinon null
     */
    public static function resolvePayload(
        ?array $payload,
        bool $isKioskPath,
        bool $localBypass,
        array $trustedIps,
        ?string $clientIp,
        ?string $requestSecret = null,
        string $configuredSecret = ''
    ): ?array {
        if (! $isKioskPath || $payload === null) {
            return null;
        }

        if ($localBypass) {
            return $payload;
        }

        // Lien secret (RÉSEAU-INDÉPENDANT : survit au changement d'IP/box/fibre) —
        // ?machine_key=<secret> == KIOSK_AUTO_LOGIN_SECRET, comparaison timing-safe.
        // Secret configuré vide ⇒ chemin inactif (jamais de bypass par secret vide).
        $configuredSecret = trim($configuredSecret);
        if ($configuredSecret !== '' && is_string($requestSecret) && $requestSecret !== ''
            && hash_equals($configuredSecret, $requestSecret)) {
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
}
