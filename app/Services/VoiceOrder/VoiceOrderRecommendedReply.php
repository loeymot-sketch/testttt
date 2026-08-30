<?php

namespace App\Services\VoiceOrder;

class VoiceOrderRecommendedReply
{
    public function forDraft(array $draft): string
    {
        $ambiguity = trim((string) (($draft['ambiguities'][0] ?? '')));
        if ($ambiguity !== '') {
            return 'Pouvez-vous reformuler le produit ou le supplément souhaité, s’il vous plaît ?';
        }

        foreach ((array) ($draft['lines'] ?? []) as $line) {
            $slot = trim((string) (($line['missing_slots'][0] ?? '')));
            $name = trim((string) ($line['name'] ?? 'ce produit'));
            if ($slot !== '') {
                return sprintf('Pour %s, quel choix souhaitez-vous pour %s ?', $name, mb_strtolower($slot));
            }
        }

        if (! empty($draft['lines'])) {
            return 'Je récapitule la commande avec vous avant de la valider.';
        }

        return 'Je vous écoute ; je vérifierai chaque produit avec vous.';
    }
}
