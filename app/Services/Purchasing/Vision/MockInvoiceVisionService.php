<?php

namespace App\Services\Purchasing\Vision;

use Illuminate\Support\Facades\Log;

/**
 * [ARCH_STOCK_INTELLIGENT_BOM_2026-07-23 / P3b] Impl MOCK — DÉFAUT en test/local
 * SANS clé OpenAI. Rend un fixture déterministe → prouve TOUT le flux
 * end-to-end (extraction → classification → validation → stock) sans réseau.
 *
 * FAIL-SAFE (mandat plan) : ne CRASH JAMAIS. Chaîne de résolution du fixture :
 *   1. si `$photoPath` pointe un .json lisible → le parse (permet un fixture par cas de test) ;
 *   2. sinon le fixture configuré `services.openai.mock_fixture` ;
 *   3. sinon le fixture par défaut `tests/fixtures/invoices/metro-sample.json` ;
 *   4. sinon (rien de lisible / JSON invalide) → [] (jamais d'exception).
 *
 * Le format du fixture : `{ "lines": [ {raw_label, qty, unit, unit_price, tva_rate}, ... ] }`
 * (ou directement un tableau de lignes). Chaque ligne est normalisée au contrat.
 */
class MockInvoiceVisionService implements InvoiceVisionContract
{
    public function extractLines(string $photoPath): array
    {
        $raw = $this->readFirstReadable([
            $this->jsonCandidate($photoPath),
            (string) config('services.openai.mock_fixture', ''),
            base_path('tests/fixtures/invoices/metro-sample.json'),
        ]);

        if ($raw === null) {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        // Accepte { "lines": [...] } OU directement [...].
        $lines = $decoded['lines'] ?? $decoded;
        if (! is_array($lines)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($line): ?array => $this->normalizeLine($line),
            $lines
        )));
    }

    /** @return string|null Chemin .json lisible si $photoPath en est un, sinon null. */
    private function jsonCandidate(string $photoPath): ?string
    {
        return str_ends_with(strtolower($photoPath), '.json') ? $photoPath : null;
    }

    /**
     * Retourne le contenu du premier chemin lisible de la liste. Fail-safe :
     * toute erreur de lecture est avalée (log debug) et on passe au suivant.
     *
     * @param  array<int, string|null>  $paths
     */
    private function readFirstReadable(array $paths): ?string
    {
        foreach ($paths as $path) {
            if (! is_string($path) || $path === '' || ! is_file($path) || ! is_readable($path)) {
                continue;
            }

            try {
                $contents = file_get_contents($path);
                if ($contents !== false) {
                    return $contents;
                }
            } catch (\Throwable $e) {
                Log::debug('[MockInvoiceVision] fixture illisible, on continue', [
                    'path' => $path,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return null;
    }

    /**
     * Normalise une ligne brute au contrat. Tolérant aux clés manquantes
     * (fail-safe) : une ligne sans libellé est écartée.
     *
     * @param  mixed  $line
     * @return array{raw_label:string, qty:float, unit:string, unit_price:float|null, tva_rate:float|null}|null
     */
    private function normalizeLine($line): ?array
    {
        if (! is_array($line)) {
            return null;
        }

        $label = trim((string) ($line['raw_label'] ?? ''));
        if ($label === '') {
            return null;
        }

        return [
            'raw_label' => $label,
            'qty' => (float) ($line['qty'] ?? 0),
            'unit' => (string) ($line['unit'] ?? 'piece'),
            'unit_price' => isset($line['unit_price']) ? (float) $line['unit_price'] : null,
            'tva_rate' => isset($line['tva_rate']) ? (float) $line['tva_rate'] : null,
        ];
    }
}
