<?php

namespace Tests\Feature\Sentinels;

use Tests\TestCase;

/**
 * @FK-ID FK-KDS-D5-01
 * @source CV1-MEGA-C-KDS-AUDIT-2026-05-07 — D5 synthèse + sentinel additionnel.
 * @fix-mission CV1-M07-KDS-RELEASE / SYNC-001 (KDS reçoit ItemAvailabilityChanged).
 * @reason
 *   Lock down — au moindre déplacement / refactor, ce sentinel doit casser :
 *     1. KitchenDisplaySystemComponent.vue DOIT souscrire à l'événement broadcast
 *        'ItemAvailabilityChanged' (sinon le KDS ne réagit pas aux toggles 86 du
 *        Composer / Wizard et affiche des items non disponibles).
 *     2. Le handler DOIT invoquer un refresh debouncé (_debouncedRefresh) — un
 *        appel direct sans debounce provoquerait un storm de requêtes /sync sur
 *        plusieurs broadcasts simultanés (OrderCreated + ItemAvailabilityChanged + ...).
 *     3. La fenêtre de debounce DOIT rester à 300ms — valeur calibrée pour
 *        absorber un burst Echo sans induire de latence visible utilisateur.
 *
 * Test purement statique (lecture du fichier source Vue) — aucun setUp DB
 * nécessaire. Pas de RefreshDatabase, pas de factory, pas de seeder.
 */
class KdsItemAvailabilityEchoSentinelTest extends TestCase
{
    private function kdsComponentSource(): string
    {
        $path = resource_path('js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue');
        $this->assertFileExists($path, 'KitchenDisplaySystemComponent.vue est introuvable — refactor non documenté ?');

        $src = file_get_contents($path);
        $this->assertNotFalse($src, 'Lecture du composant KDS impossible.');
        $this->assertNotEmpty($src, 'Composant KDS vide — drift critique.');

        return $src;
    }

    public function test_kds_component_subscribes_to_item_availability_changed_broadcast(): void
    {
        $src = $this->kdsComponentSource();

        $this->assertStringContainsString(
            "broadcastAs: 'ItemAvailabilityChanged'",
            $src,
            "Le KDS DOIT souscrire à l'événement broadcast 'ItemAvailabilityChanged' "
            . '(SYNC-001 — propagation des toggles 86 Composer/Wizard vers la cuisine). '
            . "Si cette ligne disparaît, les chefs voient des items indisponibles dans la file."
        );
    }

    public function test_kds_component_handles_item_availability_via_debounced_refresh(): void
    {
        $src = $this->kdsComponentSource();

        // [CV1-KDS-INFLIGHT-OOS-MARKER-001 + AGENT-CONTRACT C1 2026-05-08] Le handler
        // ItemAvailabilityChanged accepte maintenant 2 patterns équivalents:
        //   1. callback inline directe `() => this._debouncedRefresh()` (ancien)
        //   2. délégation `(parsed) => this._onItemAvailabilityChanged(parsed)` (nouveau,
        //      pour CV1-KDS-INFLIGHT-OOS-MARKER qui set le flag Vuex avant de debouncer)
        // Garantie maintenue : un de ces 2 patterns DOIT exister, ET la méthode
        // _onItemAvailabilityChanged DOIT appeler _debouncedRefresh() in fine.
        $this->assertMatchesRegularExpression(
            "/broadcastAs:\s*'ItemAvailabilityChanged'\s*,\s*handler:\s*\([^)]*\)\s*=>\s*\{\s*this\.(_debouncedRefresh|_onItemAvailabilityChanged)\s*\(/",
            $src,
            "Le handler pour 'ItemAvailabilityChanged' DOIT déléguer à _debouncedRefresh "
            . 'OU à _onItemAvailabilityChanged (qui doit lui-même appeler _debouncedRefresh).'
        );

        // Garantie 2 : _onItemAvailabilityChanged DOIT in fine appeler _debouncedRefresh
        // (sinon storm de requêtes /sync lors de bursts Echo simultanés).
        $this->assertMatchesRegularExpression(
            "/_onItemAvailabilityChanged[\s\S]{0,8000}?_debouncedRefresh\(\)/",
            $src,
            '_onItemAvailabilityChanged DOIT appeler this._debouncedRefresh() avant '
            . 'de retourner (anti-storm Echo bursts).'
        );
    }

    public function test_kds_debounced_refresh_window_is_300ms(): void
    {
        $src = $this->kdsComponentSource();

        // La méthode _debouncedRefresh() est définie dans le composant.
        $this->assertMatchesRegularExpression(
            '/_debouncedRefresh\s*\(\s*\)\s*\{/',
            $src,
            "La méthode _debouncedRefresh() DOIT rester définie dans le composant."
        );

        // Le setTimeout interne doit utiliser 300ms (calibration UX/perf).
        $this->assertMatchesRegularExpression(
            '/_debouncedRefresh\s*\(\s*\)\s*\{[\s\S]{0,400}?\}\s*,\s*300\s*\)/',
            $src,
            'La fenêtre de debounce DOIT rester à 300ms — valeur calibrée pour absorber '
            . "un burst Echo sans induire de latence visible utilisateur. Tout changement "
            . "doit passer par un gate UX explicite."
        );
    }

    public function test_kds_component_subscribes_to_all_four_critical_broadcasts(): void
    {
        $src = $this->kdsComponentSource();

        $required = [
            'OrderCreated'             => "Nouvelles commandes POS/Kiosk doivent apparaître en temps réel.",
            'OrderStatusChanged'       => "Transitions de statut (paid → preparing → ready) doivent rafraîchir les colonnes.",
            'OrderPaidAtCounter'       => "Comptoir : ack paiement doit déclencher refresh KDS (passage en file active).",
            'ItemAvailabilityChanged'  => "Toggles 86 Composer/Wizard doivent être propagés à la cuisine (SYNC-001).",
        ];

        foreach ($required as $event => $why) {
            $this->assertStringContainsString(
                "broadcastAs: '{$event}'",
                $src,
                "Souscription broadcast manquante pour '{$event}'. Raison : {$why}"
            );
        }
    }
}
