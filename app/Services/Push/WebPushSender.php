<?php

namespace App\Services\Push;

use App\Models\WebPushSubscription;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * [NFC / NOTIFICATIONS 2026-11-20] Envoi d'une notification Web Push à TOUS les appareils d'un client.
 *
 * Contrat : ne lève jamais d'exception vers l'appelant (une notification ratée ne doit ni bloquer la caisse, ni la
 * cuisine, ni un paiement). Retourne le nombre d'appareils qui ont ACCEPTÉ la notification.
 *  - désactivé ou clés absentes → 0, aucune requête, aucune erreur ;
 *  - adresse hors de la liste des services de notification (PushEndpoint) → jamais jointe, ligne supprimée (défense en profondeur :
 *    la route refuse déjà ces adresses) ;
 *  - un abonnement inutilisable (clé invalide, adresse mal formée) est supprimé SANS empêcher les autres appareils du client ;
 *  - 404 / 410 du service de notification → l'abonnement est mort : supprimé ;
 *  - autre échec → compté ; au-delà de `max_failures` consécutifs → supprimé ;
 *  - succès → compteur remis à zéro.
 * Aucune redirection n'est suivie, délai total borné à 4 s par appareil (une adresse lente ne retient pas un worker). Le journal ne contient jamais
 * l'adresse d'un appareil (secret) : seulement le code HTTP.
 * Le `WebPush` est injectable pour les tests (aucun test ne sort sur Internet).
 */
class WebPushSender
{
    /** @var null|callable(array<string,mixed>):WebPush */
    private $fabrique;

    /** @param null|callable(array<string,mixed>):WebPush $fabrique */
    public function __construct(?callable $fabrique = null)
    {
        $this->fabrique = $fabrique;
    }

    public static function actif(): bool
    {
        return (bool) config('webpush.enabled')
            && (string) config('webpush.public_key') !== ''
            && (string) config('webpush.private_key') !== '';
    }

    /** Clé de rapprochement stable : la même adresse normalisée par Guzzle des deux côtés (hôte en minuscules, port par défaut retiré). */
    private static function cle(string $endpoint): string
    {
        return (string) Utils::uriFor($endpoint);
    }

    /**
     * Urgence Web Push (RFC 8030 §5.3) : seules ces quatre valeurs existent. Une autre (faute de frappe dans WEBPUSH_URGENCY) est un 400
     * du service de notification : elle ferait compter un échec contre CHAQUE appareil et viderait le parc (audit adverse S-02).
     */
    private static function urgence(mixed $voulue): string
    {
        $permises = ['very-low', 'low', 'normal', 'high'];
        foreach ([$voulue, config('webpush.urgency', 'high')] as $v) {
            $v = is_string($v) ? strtolower(trim($v)) : '';
            if (in_array($v, $permises, true)) {
                return $v;
            }
        }

        return 'high';
    }

    /** @param array<string,mixed> $charge */
    public function sendToUser(int $userId, array $charge): int
    {
        if (! self::actif()) {
            return 0;
        }
        try {
            $abonnements = WebPushSubscription::query()->where('user_id', $userId)->get();
            if ($abonnements->isEmpty()) {
                return 0;
            }
            $urgence = self::urgence($charge['urgency'] ?? null);
            unset($charge['urgency']); // un réglage d'envoi, pas une donnée du message
            $corps = json_encode($charge, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
            $acceptes = 0;
            // Configuration VAPID invalide (clé tronquée, guillemets…) = faute du SERVEUR, jamais celle d'un appareil : on le dit et on ne
            // supprime RIEN (sinon une faute de frappe dans le .env effaçait tous les abonnements au premier envoi — audit adverse tour 3).
            try {
                $this->webpush();
                // La construction ne vérifie que les LONGUEURS : une clé de bonne longueur mais corrompue (un caractère changé, hors courbe)
                // n'échoue qu'à la signature. On signe donc un jeton d'essai ICI (audit adverse tour 4) ; avec un `WebPush` injecté (tests), on saute.
                if ($this->fabrique === null) {
                    // `validate` DÉCODE les clés (base64url → octets), que `getVapidHeaders` attend ; la bibliothèque fait la même chose avant de signer.
                    $v = \Minishlink\WebPush\VAPID::validate(['subject' => (string) config('webpush.subject'), 'publicKey' => (string) config('webpush.public_key'), 'privateKey' => (string) config('webpush.private_key')]);
                    \Minishlink\WebPush\VAPID::getVapidHeaders('https://fcm.googleapis.com', $v['subject'], $v['publicKey'], $v['privateKey'], 'aes128gcm');
                }
            } catch (\Throwable $e) {
                Log::error('[webpush] configuration VAPID invalide : aucun envoi', ['erreur' => get_class($e)]);

                return 0;
            }
            // OpenSSL incapable de créer une clé locale = faute du SERVEUR (extension, mémoire), jamais celle d'un appareil : sans cette
            // sonde, la bibliothèque levait « Unable to create the local key. » et chaque abonnement valide était supprimé (audit adverse S-01).
            if ($this->fabrique === null && @openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]) === false) {
                Log::error('[webpush] OpenSSL ne crée pas de clé locale : aucun envoi, aucun abonnement supprimé');

                return 0;
            }
            // Liste d'hôtes vide (variable d'environnement vide ou mal écrite) = faute de configuration : on n'efface rien.
            if ((array) config('webpush.allowed_hosts', []) === []) {
                Log::error('[webpush] liste d\'hôtes autorisés vide : aucun envoi');

                return 0;
            }
            // UN appareil à la fois, chacun dans son propre envoi : la bibliothèque chiffre et signe dans `flush()` (pas dans
            // `queueNotification()`), si bien qu'une clé invalide d'UN appareil faisait échouer l'envoi de TOUS (audit adverse tour 2).
            foreach ($abonnements as $a) {
                if (! PushEndpoint::estAutorise((string) $a->endpoint)) {
                    $a->delete();

                    continue;
                }
                try {
                    $cle = self::cle((string) $a->endpoint); // avant la mise en file : une adresse que Guzzle refuse ne laisse rien en attente
                    $webpush = $this->webpush();
                    $webpush->queueNotification(
                        Subscription::create([
                            'endpoint' => $a->endpoint,
                            'publicKey' => $a->p256dh,
                            'authToken' => $a->auth,
                            'contentEncoding' => $a->content_encoding ?: 'aes128gcm',
                        ]),
                        $corps,
                        ['urgency' => $urgence]
                    );
                    $rapports = iterator_to_array($webpush->flush(), false);
                } catch (\Throwable $e) {
                    // Inutilisable pour de bon (clé publique invalide, adresse mal formée) : supprimé, les autres appareils ne sont pas touchés.
                    Log::warning('[webpush] abonnement inutilisable supprimé', ['user' => $userId, 'erreur' => get_class($e)]);
                    $a->delete();

                    continue;
                }
                foreach ($rapports as $rapport) {
                    if (self::cle((string) $rapport->getEndpoint()) !== $cle) {
                        continue;
                    }
                    $http = $rapport->getResponse() ? $rapport->getResponse()->getStatusCode() : null;
                    // Jamais comptés contre l'APPAREIL : une panne PASSAGÈRE du service de notification (5xx, 429, 408, coupure réseau) et un refus
                    // d'AUTHENTIFICATION de NOTRE serveur (401, 403 : clé VAPID inexacte — une clé privée à un caractère près signe sans erreur et
                    // n'est refusée que par le service). Ces cas relèvent du serveur : on le journalise, on n'efface aucun abonnement valide.
                    $passager = $http === null || $http >= 500 || in_array($http, [401, 403, 408, 413, 429], true);
                    if ($rapport->isSuccess() && ($http === null || ($http >= 200 && $http < 300))) {
                        $acceptes++;
                        $a->forceFill(['last_success_at' => now(), 'failure_count' => 0])->save();
                    } elseif ($rapport->isSubscriptionExpired()) {
                        $a->delete();
                    } else {
                        if (! $passager) {
                            $n = (int) $a->failure_count + 1;
                            if ($n >= (int) config('webpush.max_failures', 5)) {
                                $a->delete();
                            } else {
                                $a->forceFill(['failure_count' => $n, 'last_failure_at' => now()])->save();
                            }
                        }
                        // Le code HTTP seulement : la raison de Guzzle contient l'adresse complète de l'appareil.
                        Log::warning('[webpush] envoi refusé', ['user' => $userId, 'http' => $http]);
                        if (in_array($http, [401, 403], true)) {
                            Log::error('[webpush] le service de notification refuse notre signature (clé VAPID ?)', ['http' => $http]);
                        }
                    }
                }
            }

            return $acceptes;
        } catch (\Throwable $e) {
            Log::warning('[webpush] échec d\'envoi isolé', ['user' => $userId, 'erreur' => get_class($e)]);

            return 0;
        }
    }

    protected function webpush(): WebPush
    {
        $auth = [
            'VAPID' => [
                'subject' => (string) config('webpush.subject'),
                'publicKey' => (string) config('webpush.public_key'),
                'privateKey' => (string) config('webpush.private_key'),
            ],
        ];
        // TTL hors bornes (négatif, absurde) = refus 400 du service de notification : retombe sur 15 min (audit adverse tour 2, S2-02).
        $ttl = (int) config('webpush.ttl', 900);
        $defaut = ['TTL' => ($ttl >= 60 && $ttl <= 86400) ? $ttl : 900, 'urgency' => self::urgence(null)];
        if ($this->fabrique !== null) {
            return ($this->fabrique)(['auth' => $auth, 'defaut' => $defaut]);
        }

        // Aucune redirection suivie (une réponse 307 vers http://127.0.0.1 contournerait la liste d'hôtes), connexion bornée à 5 s.
        // Délai total 4 s par appareil : 5 appareils au pire = 20 s, sous le délai de la tâche (40 s) et sa reprise (90 s).
        return new WebPush($auth, $defaut, 4, ['allow_redirects' => false, 'connect_timeout' => 2]);
    }
}
