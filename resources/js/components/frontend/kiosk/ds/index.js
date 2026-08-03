/**
 * FoodKing Kiosk — Design System (Phase 0 + Phase 4 + Phase 5 + V1 Bold)
 * -----------------------------------------------------------------------------
 * Barrel export des atoms ds/. Consommer exclusivement ces composants depuis
 * les écrans kiosk (Phase 2+). Tous les atoms consomment `--kiosk-*` (V1)
 * ou `--kiosk-bold-*` (V1.5+ Bold Appétissant) uniquement.
 *
 * Contrat :
 *  - Aucun atome ne contient de logique métier (pricing, branch_id, SSOT).
 *  - Les atomes sont stateless sauf KsModal (focus trap + body scroll lock).
 *  - data-testid à poser par les écrans consommateurs, pas dans les atomes.
 *
 * Phase 4 ajoute :
 *  - KsA11ySettings      : drawer paramètres a11y (lang/AAA/PMR/audio)
 *  - KsVirtualKeyboard   : clavier virtuel layouts FR/EN/AR
 *
 * Phase 5 ajoute :
 *  - KsConsentModal      : dialogue RGPD opt-in loyalty + analytics
 *
 * CV1-KIOSK-VISUAL-REDESIGN-001 V1 Bold Appétissant ajoute :
 *  - KsThemeToggle       : sélecteur thème light/dark/auto (V1.4)
 *  - KsHero              : bandeau d'identité (strip/cinematic/banner) (V1.11)
 *  - + variants additifs sur tous les atoms existants (KsButton hero/ghost-bold/pop,
 *    KsCard hero/option-bold/summary, KsChip composition, KsBadge promo/included/quota,
 *    KsModal tone="warm-blur", KsStepper variant="minimal-bar", KsPriceLine size="hero")
 */

import KsButton from './KsButton.vue';
import KsCard from './KsCard.vue';
import KsBadge from './KsBadge.vue';
import KsChip from './KsChip.vue';
import KsModal from './KsModal.vue';
import KsStepper from './KsStepper.vue';
import KsPriceLine from './KsPriceLine.vue';
import KsA11ySettings from './KsA11ySettings.vue';
import KsVirtualKeyboard from './KsVirtualKeyboard.vue';
import KsConsentModal from './KsConsentModal.vue';
import KsFilterChip from './KsFilterChip.vue';
import KsAllergenBadge from './KsAllergenBadge.vue';
/* CV1-KIOSK-VISUAL-REDESIGN-001 V1 Bold */
import KsThemeToggle from './KsThemeToggle.vue';
import KsHero from './KsHero.vue';

export {
    KsButton,
    KsCard,
    KsBadge,
    KsChip,
    KsModal,
    KsStepper,
    KsPriceLine,
    KsA11ySettings,
    KsVirtualKeyboard,
    KsConsentModal,
    KsFilterChip,
    KsAllergenBadge,
    KsThemeToggle,
    KsHero,
};

/**
 * Installation globale (optionnelle) — à activer uniquement pour l'app kiosk
 * si on décide de rendre les atoms auto-import. Par défaut on importe
 * nommément dans les écrans pour garder le tree-shaking.
 */
export const KioskDesignSystem = {
    install(app) {
        app.component('KsButton', KsButton);
        app.component('KsCard', KsCard);
        app.component('KsBadge', KsBadge);
        app.component('KsChip', KsChip);
        app.component('KsModal', KsModal);
        app.component('KsStepper', KsStepper);
        app.component('KsPriceLine', KsPriceLine);
        app.component('KsA11ySettings', KsA11ySettings);
        app.component('KsVirtualKeyboard', KsVirtualKeyboard);
        app.component('KsConsentModal', KsConsentModal);
        app.component('KsFilterChip', KsFilterChip);
        app.component('KsAllergenBadge', KsAllergenBadge);
        app.component('KsThemeToggle', KsThemeToggle);
        app.component('KsHero', KsHero);
    },
};

export default KioskDesignSystem;
