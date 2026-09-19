<?php

namespace App\Services\UberDirect;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * [UBER-DIRECT 2026-09-06] Transport HTTP vers l'API Uber Direct (OAuth2 client_credentials).
 *
 * ⚠️ POURQUOI UN SECOND CLIENT, ET PAS UNE EXTENSION DE `UberClient`
 * -------------------------------------------------------------------
 * `App\Services\Uber\UberClient` sert la MARKETPLACE (Uber nous envoie des commandes). Il est
 * réutilisable en FORME, pas en l'état, pour trois raisons vérifiées :
 *   1. sa clé de cache de jeton est une CONSTANTE (`UberClient.php:18`) — deux jeux
 *      d'identifiants s'écraseraient mutuellement, et une intégration parlerait avec le
 *      jeton de l'autre ;
 *   2. il lit `config('uber.*')` en dur ;
 *   3. les scopes diffèrent (`eats.store eats.order` contre `eats.deliveries`).
 * Le chemin Marketplace est EN SERVICE : on ne le refactore pas pour lui greffer un second
 * usage. On copie le patron éprouvé, avec son propre cache.
 *
 * NE LÈVE JAMAIS D'EXCEPTION FATALE : une panne Uber ne doit pas faire tomber le checkout.
 * Les méthodes rendent `null` (ou `false`) et journalisent — l'appelant décide.
 *
 * ⚠️ AUCUN SECRET N'EST JOURNALISÉ. Les corps de réponse d'erreur sont tronqués et les
 * en-têtes ne sont jamais tracés.
 */
class UberDirectClient
{
    /** Clé PROPRE à Uber Direct — ne doit jamais coïncider avec celle de la marketplace. */
    private const TOKEN_CACHE_KEY = 'uber_direct_access_token';

    /** L'intégration est-elle armée ? Faux ⇒ aucun appel réseau n'est jamais émis. */
    public function isConfigured(): bool
    {
        return (bool) config('uber_direct.enabled', false)
            && $this->conf('client_id') !== ''
            && $this->conf('client_secret') !== ''
            && $this->conf('customer_id') !== '';
    }

    /** Jeton d'accès (client_credentials), mis en cache. Null si indisponible. */
    public function accessToken(): ?string
    {
        $cached = Cache::get(self::TOKEN_CACHE_KEY);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        if (! $this->isConfigured()) {
            Log::warning('[UberDirect] intégration inactive ou identifiants manquants — aucun appel émis.');

            return null;
        }

        try {
            $res = Http::asForm()
                ->timeout($this->timeout())
                ->post($this->conf('token_url'), [
                    'grant_type' => 'client_credentials',
                    'client_id' => $this->conf('client_id'),
                    'client_secret' => $this->conf('client_secret'),
                    'scope' => $this->conf('scopes'),
                ]);

            if (! $res->successful()) {
                // Corps tronqué ET expurgé : une réponse OAuth peut renvoyer en écho les
                // identifiants qu'on vient de lui envoyer. Tronquer ne suffit pas — un banc
                // l'a démontré en faisant fuiter le secret dans ce journal même.
                Log::warning('[UberDirect] OAuth échec', [
                    'status' => $res->status(),
                    'body' => $this->redact(mb_substr($res->body(), 0, 300)),
                ]);

                return null;
            }

            $token = (string) ($res->json('access_token') ?? '');
            if ($token === '') {
                return null;
            }

            // Défaut 1 h quand `expires_in` manque : un jeton invalide ne doit pas rester
            // collé en cache (leçon du client marketplace, qui gardait 30 jours).
            $ttl = (int) ($res->json('expires_in') ?? 3600);
            Cache::put(self::TOKEN_CACHE_KEY, $token, max(60, $ttl - 60));

            return $token;
        } catch (\Throwable $e) {
            Log::warning('[UberDirect] OAuth exception : '.$e->getMessage());

            return null;
        }
    }

    /** Force le renouvellement du jeton (utilisé après un 401). */
    public function forgetToken(): void
    {
        Cache::forget(self::TOKEN_CACHE_KEY);
    }

    /**
     * Demande un devis de livraison.
     *
     * La méthode HTTP est configurable : la documentation publique d'Uber décrit les devis
     * en GET sur une page et en POST sur une autre. Elle sera tranchée contre la
     * documentation du compte plutôt que devinée ici.
     *
     * @return array|null la réponse brute d'Uber, ou null en cas d'échec
     */
    public function createQuote(array $body): ?array
    {
        $method = strtoupper((string) config('uber_direct.quote_method', 'POST'));

        return $this->authed($method, $this->url('quote'), $body);
    }

    /** Crée une course. `$body` doit porter le `quote_id` et l'identifiant externe. */
    public function createDelivery(array $body): ?array
    {
        return $this->authed('POST', $this->url('create'), $body);
    }

    /** État d'une course. */
    public function getDelivery(string $deliveryId): ?array
    {
        return $this->authed('GET', $this->url('get', ['delivery_id' => $deliveryId]));
    }

    /** Annule une course. */
    public function cancelDelivery(string $deliveryId): ?array
    {
        return $this->authed('POST', $this->url('cancel', ['delivery_id' => $deliveryId]));
    }

    /**
     * Appel authentifié, avec UN seul réessai après 401 (jeton périmé côté Uber).
     *
     * Un seul : au-delà, c'est une panne ou un identifiant révoqué, et insister ferait
     * attendre le client pour rien.
     */
    private function authed(string $method, string $url, array $body = [], bool $retried = false): ?array
    {
        $token = $this->accessToken();
        if ($token === null) {
            return null;
        }

        try {
            $req = Http::withToken($token)->timeout($this->timeout())->acceptJson();
            $res = $method === 'GET'
                ? $req->get($url, $body)
                : $req->send($method, $url, ['json' => $body]);

            if ($res->status() === 401 && ! $retried) {
                $this->forgetToken();

                return $this->authed($method, $url, $body, true);
            }

            if (! $res->successful()) {
                Log::warning('[UberDirect] appel non-2xx', [
                    'method' => $method,
                    // Jamais l'URL complète : elle porte le customer_id.
                    'endpoint' => parse_url($url, PHP_URL_PATH),
                    'status' => $res->status(),
                    'body' => $this->redact(mb_substr($res->body(), 0, 500)),
                ]);

                return null;
            }

            $json = $res->json();

            return is_array($json) ? $json : null;
        } catch (\Throwable $e) {
            Log::warning('[UberDirect] exception réseau : '.$e->getMessage());

            return null;
        }
    }

    /** Construit une URL à partir des gabarits configurables. */
    private function url(string $key, array $params = []): string
    {
        $path = (string) config('uber_direct.endpoints.'.$key, '');
        $params['customer_id'] = $this->conf('customer_id');

        foreach ($params as $k => $v) {
            $path = str_replace('{'.$k.'}', rawurlencode((string) $v), $path);
        }

        return rtrim($this->conf('api_base'), '/').$path;
    }

    /**
     * Expurge les identifiants d'un texte destiné aux journaux.
     *
     * Exigence propriétaire : « Jamais de Client Secret dans les logs ». Uber renvoie parfois
     * en écho ce qu'on lui a envoyé ; tronquer le corps ne suffit donc pas. Un banc de
     * régression le prouve (`le_secret_client_ne_fuit_jamais_dans_les_journaux`).
     */
    private function redact(string $texte): string
    {
        foreach (['client_secret', 'client_id'] as $cle) {
            $valeur = $this->conf($cle);
            if ($valeur !== '') {
                $texte = str_replace($valeur, '[expurgé]', $texte);
            }
        }

        return $texte;
    }

    private function conf(string $key): string
    {
        return trim((string) config('uber_direct.'.$key, ''));
    }

    private function timeout(): int
    {
        return max(1, (int) config('uber_direct.timeout_seconds', 8));
    }
}
