<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Services\Storefront\StorefrontService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * [APP HYBRIDE W2a · 2026-11-03] Vitrine distante — document PUBLIC lu par l'application des stores et le site
 * (au lancement, au retour au premier plan, toutes les ≤ 5 min). Lecture seule, aucune donnée client.
 *
 * ETag = empreinte du corps exact : il change quand on publie, quand un bandeau commence ou se termine, et quand
 * la livraison est suspendue ou remise. Une relecture sans changement coûte un 304 sans corps.
 */
class StorefrontController extends Controller
{
    public function __construct(private readonly StorefrontService $storefront)
    {
    }

    public function show(Request $request): JsonResponse|Response
    {
        $payload = $this->storefront->publicPayload($this->storefront->branchForPublic());
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $etag = '"sf-'.$payload['version'].'-'.substr(sha1($body), 0, 20).'"';
        $headers = ['ETag' => $etag, 'Cache-Control' => 'public, max-age=0, must-revalidate'];

        if ($this->matches((string) $request->header('If-None-Match', ''), $etag)) {
            return response('', 304, $headers);
        }

        return response()->json($payload, 200, $headers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function matches(string $header, string $etag): bool
    {
        if ($header === '') {
            return false;
        }
        foreach (explode(',', $header) as $candidate) {
            $candidate = trim($candidate);
            if (str_starts_with($candidate, 'W/')) {
                $candidate = substr($candidate, 2);
            }
            if ($candidate === $etag || $candidate === '*') {
                return true;
            }
        }

        return false;
    }
}
