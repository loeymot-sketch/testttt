<?php

namespace App\Services\Wheel;

use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * Le déverrouillage du tour — la seule vérification d'avis/abonnement qui tienne réellement.
 *
 * ── POURQUOI UN HUMAIN DANS LA BOUCLE ────────────────────────────────────────────────────────
 * Aucune API publique ne permet de vérifier qu'une personne PRÉCISE a laissé un avis Google ou
 * s'est abonnée à un compte : Google n'expose pas les avis par auteur, les API Instagram/TikTok ne
 * donnent pas les abonnés d'un compte tiers. Toute solution « automatique » se réduit donc à un
 * bouton « j'ai mis mon avis » que le client coche lui-même — c'est-à-dire à rien, et rejouable à
 * l'infini.
 *
 * La vérification qui marche existe déjà dans le restaurant : le client montre son écran, l'équipe
 * regarde, l'équipe valide. Ce service transforme ce geste humain en un JETON SIGNÉ à usage
 * unique. Ce n'est pas un pis-aller : c'est plus fiable que n'importe quelle API, parce que c'est
 * un œil humain sur la preuve réelle.
 *
 * ── CE QUI REND LE JETON SÛR ─────────────────────────────────────────────────────────────────
 *  1. SIGNÉ (HMAC-SHA256, clé d'application) : un jeton fabriqué à la main ne passe pas.
 *  2. COURT — quelques minutes. Il doit être consommé devant l'équipe, pas envoyé à des amis dans
 *     la soirée. Un jeton qui vit une heure est un jeton qui circule.
 *  3. À USAGE UNIQUE, garanti en BASE : `wheel_spins.unlock_token_hash` porte un index UNIQUE.
 *     Rejouer le même jeton viole la contrainte, quelle que soit la concurrence. On stocke
 *     l'EMPREINTE, jamais le jeton — un journal volé ne rend personne capable de rejouer.
 *  4. PORTE SA BRANCHE ET SON ÉMETTEUR : on sait toujours qui a validé, et pour quel comptoir.
 *
 * La comparaison de signature utilise `hash_equals` : un `===` sur des chaînes fuit, par son temps
 * d'exécution, le nombre d'octets corrects — de quoi reconstituer une signature valide octet par
 * octet.
 */
class WheelUnlockService
{
    private const SEP = '.';

    /**
     * Émis par l'équipe APRÈS avoir vu l'avis / l'abonnement sur le téléphone du client.
     *
     * @return array{token: string, expires_at: string}
     */
    public function issue(int $branchId, ?int $userId): array
    {
        $ttl = max(1, (int) config('wheel.unlock_token_ttl_minutes', 15));
        $expire = Carbon::now()->addMinutes($ttl);

        $charge = [
            'b' => $branchId,
            'u' => $userId,
            // Le nonce rend deux jetons émis dans la même seconde distincts : sans lui, deux
            // validations simultanées produiraient le même jeton, donc la même empreinte, et la
            // seconde serait refusée comme un rejeu. Un client se verrait privé de son tour parce
            // qu'un autre a été validé en même temps.
            'n' => Str::random(16),
            'e' => $expire->timestamp,
        ];

        $corps = $this->encode($charge);

        return [
            'token'      => $corps . self::SEP . $this->sign($corps),
            'expires_at' => $expire->toIso8601String(),
        ];
    }

    /**
     * @return array{branch_id: int, user_id: int|null, token_hash: string}
     *
     * @throws WheelException
     */
    public function verify(string $token): array
    {
        $token = trim($token);
        $morceaux = explode(self::SEP, $token);
        if (count($morceaux) !== 2 || $morceaux[0] === '' || $morceaux[1] === '') {
            throw new WheelException('Validation introuvable. Demande à l\'équipe de revalider.', 403);
        }

        [$corps, $signature] = $morceaux;

        if (! hash_equals($this->sign($corps), $signature)) {
            throw new WheelException('Validation invalide. Demande à l\'équipe de revalider.', 403);
        }

        $charge = $this->decode($corps);
        if ($charge === null || ! isset($charge['b'], $charge['e'])) {
            throw new WheelException('Validation illisible. Demande à l\'équipe de revalider.', 403);
        }

        if (Carbon::createFromTimestamp((int) $charge['e'])->isPast()) {
            throw new WheelException('Cette validation a expiré. Demande à l\'équipe de revalider.', 403);
        }

        return [
            'branch_id'  => (int) $charge['b'],
            'user_id'    => isset($charge['u']) ? (int) $charge['u'] : null,
            // L'empreinte, jamais le jeton : c'est elle qui va en base et qui porte l'usage unique.
            'token_hash' => hash('sha256', $token),
        ];
    }

    private function sign(string $corps): string
    {
        return hash_hmac('sha256', $corps, $this->secret());
    }

    private function secret(): string
    {
        $cle = (string) config('app.key');
        if ($cle === '') {
            // Sans clé, toute signature est forgeable. On refuse d'émettre plutôt que d'émettre du
            // faux — un jeton non signé serait pire que pas de jeton du tout.
            throw new WheelException('Le jeu n\'est pas configuré côté serveur.', 503);
        }

        return 'wheel-unlock' . $cle;
    }

    private function encode(array $charge): string
    {
        return rtrim(strtr(base64_encode((string) json_encode($charge)), '+/', '-_'), '=');
    }

    private function decode(string $corps): ?array
    {
        $brut = base64_decode(strtr($corps, '-_', '+/'), true);
        if ($brut === false) {
            return null;
        }
        $d = json_decode($brut, true);

        return is_array($d) ? $d : null;
    }
}
