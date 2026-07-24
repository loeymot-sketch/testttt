<?php

namespace App\Services\Purchasing\Vision;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * [ARCH_STOCK_INTELLIGENT_BOM_2026-07-23 / P3b] Impl OpenAI Vision — sélectionnée
 * par le binding UNIQUEMENT quand `services.openai.enabled` ET une clé sont
 * présentes (PurchasingServiceProvider). Appel HTTP structuré via `Http::`
 * (Guzzle Laravel) — testé par `Http::fake()` (aucun réseau réel en test).
 *
 * FAIL-CLOSED sans clé : extractLines() lève une RuntimeException claire si la
 * clé est absente (le binding l'évite en amont ; c'est une double sécurité si
 * quelqu'un résout cette classe directement). Aucun silence, aucun 0-crash
 * masqué : sans clé on ne fait rien, mais on le DIT.
 *
 * Le prompt demande un JSON strict `{ "lines": [ {libellé,qté,unité,prix,tva} ] }`.
 * La réponse est parsée défensivement vers le contrat ; toute déviation → [].
 *
 * Domaine NEUF, ADDITIF, HORS NF525.
 */
class OpenAiInvoiceVisionService implements InvoiceVisionContract
{
    private const PROMPT = 'Tu es un lecteur de factures fournisseur de restaurant. '
        .'Extrais UNIQUEMENT les lignes d\'articles de cette facture. '
        .'Réponds en JSON strict, objet unique de forme '
        .'{"lines":[{"raw_label":string,"qty":number,"unit":string,"unit_price":number,"tva_rate":number}]}. '
        .'raw_label = libellé exact lu ; qty = quantité ; unit = unité (kg, piece, tranche, cl...) ; '
        .'unit_price = prix unitaire HT ; tva_rate = taux de TVA en %. '
        .'N\'invente rien : omets un champ inconnu plutôt que de le deviner.';

    public function extractLines(string $photoPath): array
    {
        $key = (string) config('services.openai.key', '');

        // FAIL-CLOSED : pas de clé → exception claire (jamais un crash obscur, jamais un silence).
        if ($key === '') {
            throw new RuntimeException(
                'OpenAI Vision indisponible : OPENAI_API_KEY absente. '
                .'Le binding doit retomber sur MockInvoiceVisionService en l\'absence de clé.'
            );
        }

        $image = $this->encodeImage($photoPath);
        if ($image === null) {
            Log::warning('[OpenAiInvoiceVision] photo illisible, extraction abandonnée', ['path' => $photoPath]);

            return [];
        }

        $response = Http::withToken($key)
            ->timeout((int) config('services.openai.timeout', 30))
            ->acceptJson()
            ->post(rtrim((string) config('services.openai.base_url', 'https://api.openai.com/v1'), '/').'/chat/completions', [
                'model' => (string) config('services.openai.model', 'gpt-4o-mini'),
                'temperature' => 0,
                'response_format' => ['type' => 'json_object'],
                'messages' => [[
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => self::PROMPT],
                        ['type' => 'image_url', 'image_url' => ['url' => $image]],
                    ],
                ]],
            ]);

        if (! $response->successful()) {
            Log::warning('[OpenAiInvoiceVision] réponse non-2xx', ['status' => $response->status()]);

            return [];
        }

        return $this->parseResponse((string) $response->json('choices.0.message.content', ''));
    }

    /** Encode la photo en data-URI base64 pour l'API vision. Null si illisible. */
    private function encodeImage(string $photoPath): ?string
    {
        if (! is_file($photoPath) || ! is_readable($photoPath)) {
            return null;
        }

        $bytes = @file_get_contents($photoPath);
        if ($bytes === false) {
            return null;
        }

        $mime = function_exists('mime_content_type')
            ? (mime_content_type($photoPath) ?: 'image/jpeg')
            : 'image/jpeg';

        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }

    /**
     * Parse le JSON renvoyé par le modèle vers le contrat. Défensif : toute
     * déviation (JSON invalide, forme inattendue) → [].
     *
     * @return array<int, array{raw_label:string, qty:float, unit:string, unit_price:float|null, tva_rate:float|null}>
     */
    private function parseResponse(string $content): array
    {
        $decoded = json_decode($content, true);
        $lines = is_array($decoded) ? ($decoded['lines'] ?? $decoded) : null;

        if (! is_array($lines)) {
            return [];
        }

        $out = [];
        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }

            $label = trim((string) ($line['raw_label'] ?? ''));
            if ($label === '') {
                continue;
            }

            $out[] = [
                'raw_label' => $label,
                'qty' => (float) ($line['qty'] ?? 0),
                'unit' => (string) ($line['unit'] ?? 'piece'),
                'unit_price' => isset($line['unit_price']) ? (float) $line['unit_price'] : null,
                'tva_rate' => isset($line['tva_rate']) ? (float) $line['tva_rate'] : null,
            ];
        }

        return $out;
    }
}
