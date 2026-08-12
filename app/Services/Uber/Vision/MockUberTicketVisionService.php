<?php

namespace App\Services\Uber\Vision;

use Illuminate\Support\Facades\Log;

/**
 * [UBER-PHOTO 2026-08-10 · owner] Doublure LOCALE du lecteur de ticket — pilote par DÉFAUT tant
 * qu'aucune clé de vision n'est fournie.
 *
 * Elle n'est pas un bouchon vide : elle rend une lecture déterministe, ce qui permet de prouver
 * TOUT le reste du parcours — correspondance catalogue, symbolisation cuisine, création de la
 * commande, écran cuisine, impression — sans clé et sans réseau. Le jour où l'owner fournit la
 * clé, seule la première étape change ; le reste est déjà éprouvé.
 *
 * Résolution du contenu rendu, dans l'ordre :
 *   1. une des photos est en réalité un fichier .json → il est lu (un scénario par test) ;
 *   2. le fichier configuré dans `services.openai.mock_fixture` ;
 *   3. le ticket d'exemple `tests/fixtures/uber/ticket-exemple.json` ;
 *   4. rien de lisible → une lecture VIDE (jamais d'exception, jamais de produit inventé).
 *
 * FAIL-SAFE absolu : ce pilote ne lève jamais. Une lecture vide est traitée en amont comme un
 * échec de lecture, et le personnel saisit la commande à la main — c'est un mauvais moment, pas
 * une commande perdue.
 */
class MockUberTicketVisionService implements UberTicketVisionContract
{
    public function driverName(): string
    {
        return 'mock';
    }

    public function readTicket(array $photoPaths): array
    {
        $candidates = [];
        foreach ($photoPaths as $path) {
            $path = (string) $path;
            if (str_ends_with(strtolower($path), '.json')) {
                $candidates[] = $path;
            }
        }
        // Scénario explicitement configuré (tests, démonstration owner).
        $candidates[] = (string) config('services.openai.mock_fixture', '');

        // ⚠️ LE TICKET D'EXEMPLE N'EST UN REPLI QU'EN TEST.
        // Face à une VRAIE photo, rendre l'exemple serait le pire comportement possible : le
        // personnel verrait une commande plausible — un client, des produits, un total — et
        // pourrait l'envoyer en cuisine. Une commande INVENTÉE partirait en préparation sans que
        // personne ne puisse s'en apercevoir. Hors test, l'absence de lecteur se DIT : lecture
        // vide → capture « illisible » → l'écran invite à saisir la commande depuis la caisse.
        if (app()->environment('testing')) {
            $candidates[] = base_path('tests/fixtures/uber/ticket-exemple.json');
        }

        $raw = $this->readFirstReadable($candidates);
        if ($raw === null) {
            return OpenAiUberTicketVisionService::emptyTicket();
        }

        // La normalisation est CELLE du pilote réel : les deux rendent rigoureusement la même
        // structure, sinon un test en doublure ne prouverait rien du chemin de production.
        return OpenAiUberTicketVisionService::normalize(json_decode($raw, true));
    }

    /** @param array<int, string|null> $paths */
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
                Log::debug('[MockUberTicketVision] exemple illisible, on continue', [
                    'path' => $path, 'error' => $e->getMessage(),
                ]);
            }
        }

        return null;
    }
}
