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
            $ttl = (int) ($res->json('expires_in') ?? 2592000);
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

    private function url(string $key, array $params): string
    {
        $path = (string) config('uber.endpoints.' . $key);
        foreach ($params as $k => $v) {
            $path = str_replace('{' . $k . '}', $v, $path);
        }
        return rtrim((string) config('uber.api_base'), '/') . $path;
    }

    private function authedGet(string $url): ?array
    {
        $token = $this->accessToken();
        if (! $token) {
            return null;
        }
        try {
            $res = Http::withToken($token)->acceptJson()->get($url);
            return $res->successful() ? (array) $res->json() : null;
        } catch (\Throwable $e) {
            Log::warning('[Uber] GET exception: ' . $e->getMessage());
            return null;
        }
    }

    private function authedPost(string $url, array $body): bool
    {
        $token = $this->accessToken();
        if (! $token) {
            return false;
        }
        try {
            return Http::withToken($token)->acceptJson()->post($url, $body)->successful();
        } catch (\Throwable $e) {
            Log::warning('[Uber] POST exception: ' . $e->getMessage());
            return false;
        }
    }
}
