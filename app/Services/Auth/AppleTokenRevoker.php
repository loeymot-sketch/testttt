<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * [STORES 2026-11-12 · Apple 5.1.1(v)] Révocation des jetons « Se connecter avec Apple » à la SUPPRESSION du compte.
 *
 * Apple exige, pour une application qui propose « Se connecter avec Apple », que la suppression du compte révoque les
 * jetons de l'utilisateur par l'API REST d'Apple (POST https://appleid.apple.com/auth/revoke). Il faut pour cela un
 * jeton de rafraîchissement, obtenu UNE fois à la connexion en échangeant le `authorizationCode` que l'application reçoit
 * d'Apple (POST /auth/token). Les deux appels s'authentifient par un « client secret » : un JWT ES256 signé avec la clé
 * privée Sign in with Apple (fichier .p8) du compte développeur.
 *
 * TANT QUE LA CLÉ N'EST PAS POSÉE (APPLE_TEAM_ID, APPLE_KEY_ID, APPLE_PRIVATE_KEY), tout est INACTIF : la connexion et la
 * suppression se déroulent comme avant. Un échec d'Apple ne bloque JAMAIS une connexion ni une suppression (journalisé).
 *
 * Aucune bibliothèque JWT ajoutée : même choix que SocialIdentityVerifier (extension openssl). ES256 = ECDSA P-256 +
 * SHA-256 ; openssl rend une signature DER, que JOSE veut en R‖S brut (64 octets) — conversion ci-dessous.
 */
class AppleTokenRevoker
{
    private const APPLE = 'https://appleid.apple.com';

    public function actif(): bool
    {
        return $this->cle() !== null
            && filled(config('services.apple.team_id'))
            && filled(config('services.apple.key_id'))
            && filled($this->clientId());
    }

    /** Échange le code d'autorisation contre un jeton de rafraîchissement ; null si inactif ou en échec. */
    public function echangerCode(string $authorizationCode): ?string
    {
        if (! $this->actif() || trim($authorizationCode) === '') {
            return null;
        }
        try {
            $reponse = Http::asForm()->timeout(8)->post(self::APPLE . '/auth/token', [
                'client_id' => $this->clientId(),
                'client_secret' => $this->clientSecret(),
                'code' => $authorizationCode,
                'grant_type' => 'authorization_code',
            ]);
            if (! $reponse->successful()) {
                Log::warning('[apple-revoke] échange du code refusé', ['statut' => $reponse->status(), 'erreur' => $reponse->json('error')]);

                return null;
            }
            $jeton = $reponse->json('refresh_token');

            return is_string($jeton) && $jeton !== '' ? $jeton : null;
        } catch (Throwable $e) {
            Log::warning('[apple-revoke] échange du code impossible', ['raison' => $e->getMessage()]);

            return null;
        }
    }

    /** Révoque le jeton de rafraîchissement chez Apple. true si Apple l'a accepté. */
    public function revoquer(string $refreshToken): bool
    {
        if (! $this->actif() || trim($refreshToken) === '') {
            return false;
        }
        try {
            $reponse = Http::asForm()->timeout(8)->post(self::APPLE . '/auth/revoke', [
                'client_id' => $this->clientId(),
                'client_secret' => $this->clientSecret(),
                'token' => $refreshToken,
                'token_type_hint' => 'refresh_token',
            ]);
            if (! $reponse->successful()) {
                Log::warning('[apple-revoke] révocation refusée', ['statut' => $reponse->status(), 'erreur' => $reponse->json('error')]);

                return false;
            }

            return true;
        } catch (Throwable $e) {
            Log::warning('[apple-revoke] révocation impossible', ['raison' => $e->getMessage()]);

            return false;
        }
    }

    /** JWT ES256 « client secret » (valide 5 minutes) — public pour les tests. */
    public function clientSecret(): string
    {
        $cle = $this->cle();
        if ($cle === null) {
            throw new RuntimeException('Clé Apple absente.');
        }
        $b64 = fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $maintenant = time();
        $entete = $b64(json_encode(['alg' => 'ES256', 'kid' => (string) config('services.apple.key_id')]));
        $corps = $b64(json_encode([
            'iss' => (string) config('services.apple.team_id'),
            'iat' => $maintenant,
            'exp' => $maintenant + 300,
            'aud' => self::APPLE,
            'sub' => $this->clientId(),
        ]));
        $signee = $entete . '.' . $corps;
        $der = '';
        if (! openssl_sign($signee, $der, $cle, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Signature ES256 impossible.');
        }

        return $signee . '.' . $b64(self::derVersBrut($der, 32));
    }

    /** Signature ECDSA DER (SEQUENCE{INTEGER r, INTEGER s}) → R‖S de longueur fixe (JOSE). */
    public static function derVersBrut(string $der, int $taille): string
    {
        $pos = 0;
        if (ord($der[$pos++]) !== 0x30) {
            throw new RuntimeException('Signature DER invalide.');
        }
        $long = ord($der[$pos++]);
        if ($long & 0x80) {
            $pos += $long & 0x7f;
        }
        $parts = [];
        for ($i = 0; $i < 2; $i++) {
            if (ord($der[$pos++]) !== 0x02) {
                throw new RuntimeException('Signature DER invalide.');
            }
            $l = ord($der[$pos++]);
            $entier = ltrim(substr($der, $pos, $l), "\x00");
            $pos += $l;
            $parts[] = str_pad($entier, $taille, "\x00", STR_PAD_LEFT);
        }

        return $parts[0] . $parts[1];
    }

    private function clientId(): string
    {
        $id = (string) config('services.apple.client_id', '');
        if ($id !== '') {
            return $id;
        }
        $audiences = (array) config('services.apple.audiences', []);

        return (string) ($audiences[0] ?? '');
    }

    /** @return \OpenSSLAsymmetricKey|null */
    private function cle()
    {
        $pem = (string) config('services.apple.private_key', '');
        if ($pem === '') {
            return null;
        }
        // Clé posée sur une seule ligne dans .env : « \n » littéraux.
        $pem = str_replace('\n', "\n", $pem);
        $cle = @openssl_pkey_get_private($pem);

        return $cle === false ? null : $cle;
    }
}
