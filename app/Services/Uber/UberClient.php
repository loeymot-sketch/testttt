<?php

namespace App\Services\Uber;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * [UBER-EATS 2026-07-01] Client API Uber Eats (OAuth2 client_credentials + Orders API).
 *
 * - Token mis en cache jusqu'à ~60s avant expiration (évite un appel OAuth par requête).
 * - Ne lève pas d'exception fatale : logge + retourne null en cas d'échec (le webhook ne doit
 *   jamais 500 sur une panne Uber transitoire — Uber rejouera).
 */
class UberClient
{
    private const TOKEN_CACHE_KEY = 'uber_eats_access_token';

    /** Récupère un access token (client_credentials), mis en cache. Null si échec. */
    public function accessToken(): ?string
    {
        $cached = Cache::get(self::TOKEN_CACHE_KEY);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $clientId = (string) config('uber.client_id');
        $clientSecret = (string) config('uber.client_secret');
        if ($clientId === '' || $clientSecret === '') {
            Log::warning('[Uber] client_id/secret manquants (.env) — intégration inactive.');
            return null;
        }

        try {
            $res = Http::asForm()->post((string) config('uber.token_url'), [
                'grant_type'    => 'client_credentials',
                'client_id'     => $clientId,
                'client_secret' => $clientSecret,
                'scope'         => (string) config('uber.scopes'),
            ]);
            if (! $res->successful()) {
                Log::warning('[Uber] OAuth échec', ['status' => $res->status(), 'body' => $res->body()]);
                return null;
            }
            $token = (string) ($res->json('access_token') ?? '');
            // [GO-LIVE UBER 2026-07-04] Défaut 1h (au lieu de 30 j) quand expires_in est absent :
            // un token invalide ne peut plus rester collé en cache un mois.
            $ttl = (int) ($res->json('expires_in') ?? 3600);
            if ($token === '') {
                return null;
            }
            Cache::put(self::TOKEN_CACHE_KEY, $token, max(60, $ttl - 60));
            return $token;
        } catch (\Throwable $e) {
            Log::warning('[Uber] OAuth exception: ' . $e->getMessage());
            return null;
        }
    }

    /** Détail complet d'une commande Uber. Null si échec. */
    public function fetchOrder(string $orderId): ?array
    {
        return $this->authedGet($this->url('order', ['order_id' => $orderId]));
    }

    /**
     * [UBER-VALIDATION 2026-08-02] Détail d'une commande via le resource_href du webhook
     * (flux « Get Orders » exigé par la validation Uber). Le domaine du href est réécrit
     * vers api_base : en sandbox Uber émet https://api.uber.com/... mais sert test-api.
     */
    public function fetchOrderByHref(string $href): ?array
    {
        $path = (string) (parse_url($href, PHP_URL_PATH) ?: '');
        if ($path === '') {
            return null;
        }
        return $this->authedGet(rtrim((string) config('uber.api_base'), '/') . $path);
    }

    /** Accepte une commande (POS accept). True si 2xx. */
    public function acceptOrder(string $orderId, array $body = []): bool
    {
        return $this->authedPost($this->url('accept', ['order_id' => $orderId]), $body);
    }

    /** Refuse une commande (rupture stock, etc.). True si 2xx. */
    public function denyOrder(string $orderId, array $body = []): bool
    {
        return $this->authedPost($this->url('deny', ['order_id' => $orderId]), $body);
    }

    /** Statut du store (ouvert/fermé/pausé). Null si échec. */
    public function storeStatus(): ?array
    {
        return $this->authedGet($this->url('store', ['store_id' => (string) config('uber.store_id')]));
    }

    // ── [UBER-BASIC-PROD 2026-08-02] Famille /v1/delivery + menus v2 — capacités exigées
    //    par la checklist « Basic Production validation » d'Uber (email Case# 58936938). ──

    /** Tous les stores rattachés à l'app (famille delivery). Null si échec. */
    public function deliveryStores(): ?array
    {
        return $this->authedGet($this->url('delivery_stores', []));
    }

    /** Détail d'un store (famille delivery). Null si échec. */
    public function deliveryStore(?string $storeId = null): ?array
    {
        return $this->authedGet($this->url('delivery_store', ['store_id' => $storeId ?? (string) config('uber.store_id')]));
    }

    /** Statut restaurant (famille delivery : ONLINE/PAUSED/OFFLINE). Null si échec. */
    public function deliveryStoreStatus(?string $storeId = null): ?array
    {
        return $this->authedGet($this->url('store_status_get', ['store_id' => $storeId ?? (string) config('uber.store_id')]));
    }

    /** Change le statut du store (ex. ONLINE / PAUSED + reason). True si 2xx. */
    public function setStoreStatus(string $status, ?string $reason = null, ?string $storeId = null): bool
    {
        $body = ['status' => $status];
        if ($reason !== null) {
            $body['reason'] = $reason;
        }
        return $this->authedPost($this->url('store_status_set', ['store_id' => $storeId ?? (string) config('uber.store_id')]), $body);
    }

    /** Annule une commande côté Uber (famille delivery). True si 2xx. */
    public function cancelOrder(string $orderId, array $body = []): bool
    {
        return $this->authedPost($this->url('order_cancel', ['order_id' => $orderId]), $body);
    }

    /** Refuse une commande (famille delivery). True si 2xx. */
    public function denyOrderDelivery(string $orderId, array $body = []): bool
    {
        return $this->authedPost($this->url('order_deny', ['order_id' => $orderId]), $body);
    }

    /** Signale « commande prête au retrait » (déclenche/cale le dispatch coursier). True si 2xx. */
    public function readyOrder(string $orderId): bool
    {
        return $this->authedPost($this->url('order_ready', ['order_id' => $orderId]), []);
    }

    /** Upload complet du menu (PUT v2). True si 2xx. */
    public function putMenu(array $menu, ?string $storeId = null): bool
    {
        return $this->authedPut($this->url('menu_put', ['store_id' => $storeId ?? (string) config('uber.store_id')]), $menu);
    }

    /** Met à jour un item du menu (suspension 86 / prix). True si 2xx. */
    public function updateMenuItem(string $menuItemId, array $body, ?string $storeId = null): bool
    {
        return $this->authedPost(
            $this->url('menu_item', ['store_id' => $storeId ?? (string) config('uber.store_id'), 'item_id' => $menuItemId]),
            $body
        );
    }

    private function url(string $key, array $params): string
    {
        $path = (string) config('uber.endpoints.' . $key);
        foreach ($params as $k => $v) {
            $path = str_replace('{' . $k . '}', $v, $path);
        }
        return rtrim((string) config('uber.api_base'), '/') . $path;
    }

    // [GO-LIVE UBER 2026-07-04] 401 = token révoqué/rotaté côté Uber AVANT son TTL local :
    // on invalide le cache, on re-authentifie et on retente UNE fois. Avant : le token mort
    // restait en cache (aucun Cache::forget) → chaque fetchOrder 401→null → commandes payées
    // perdues pendant toute la fenêtre de cache.
    private function authedGet(string $url, bool $retried = false): ?array
    {
        $token = $this->accessToken();
        if (! $token) {
            return null;
        }
        try {
            $res = Http::withToken($token)->acceptJson()->get($url);
            if ($res->status() === 401 && ! $retried) {
                Cache::forget(self::TOKEN_CACHE_KEY);
                Log::warning('[Uber] 401 — token invalidé, refresh + retry.');
                return $this->authedGet($url, true);
            }
            return $res->successful() ? (array) $res->json() : null;
        } catch (\Throwable $e) {
            Log::warning('[Uber] GET exception: ' . $e->getMessage());
            return null;
        }
    }

    private function authedPost(string $url, array $body, bool $retried = false): bool
    {
        $token = $this->accessToken();
        if (! $token) {
            return false;
        }
        try {
            // [UBER-SANDBOX 2026-08-02] Body vide : Laravel sérialise [] en tableau JSON « [] »
            // qu'Uber rejette (400 Bad Request prouvé sur accept_pos_order) — il faut l'objet « {} ».
            $req = Http::withToken($token)->acceptJson();
            $res = $body === []
                ? $req->withBody('{}', 'application/json')->post($url)
                : $req->post($url, $body);
            if ($res->status() === 401 && ! $retried) {
                Cache::forget(self::TOKEN_CACHE_KEY);
                Log::warning('[Uber] 401 — token invalidé, refresh + retry.');
                return $this->authedPost($url, $body, true);
            }
            if (! $res->successful()) {
                // [UBER-SANDBOX 2026-08-02] Un accept/deny qui échoue en silence = commande qui
                // expire côté Uber sans trace (prouvé : 403 user_not_allowed avant l'activation
                // Order Manager). On loggue pour le monitoring, sans changer le contrat (bool).
                Log::warning('[Uber] POST non-2xx: HTTP '.$res->status().' '.mb_substr($res->body(), 0, 300), ['url' => $url]);
            }
            return $res->successful();
        } catch (\Throwable $e) {
            Log::warning('[Uber] POST exception: ' . $e->getMessage());
            return false;
        }
    }

    private function authedPut(string $url, array $body, bool $retried = false): bool
    {
        $token = $this->accessToken();
        if (! $token) {
            return false;
        }
        try {
            $res = Http::withToken($token)->acceptJson()->put($url, $body);
            if ($res->status() === 401 && ! $retried) {
                Cache::forget(self::TOKEN_CACHE_KEY);
                Log::warning('[Uber] 401 — token invalidé, refresh + retry.');
                return $this->authedPut($url, $body, true);
            }
            if (! $res->successful()) {
                Log::warning('[Uber] PUT non-2xx: HTTP '.$res->status().' '.mb_substr($res->body(), 0, 300), ['url' => $url]);
            }
            return $res->successful();
        } catch (\Throwable $e) {
            Log::warning('[Uber] PUT exception: ' . $e->getMessage());
            return false;
        }
    }
}
