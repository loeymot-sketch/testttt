/**
 * FoodKing Kiosk — Bootstrap Design System (Phase 0)
 * -----------------------------------------------------------------------------
 * Entry point DS kiosk. Charge dans l'ordre :
 *  1. tokens.css      (base AA — obligatoire)
 *  2. tokens-aaa.css  (mode contraste renforcé — actif via data-kiosk-contrast="aaa")
 *  3. tokens-pmr.css  (mode PMR — actif via data-kiosk-pmr="true")
 *  4. barrel exports des atoms ds/
 *
 * ⚠ Phase 0 : ce fichier n'est PAS encore câblé à resources/js/app.js.
 *   Raison : le fichier legacy resources/css/kiosk-wizard.css déclare déjà
 *   `--kiosk-primary`, `--kiosk-touch-min`, etc. avec des valeurs historiques.
 *   L'import global de tokens.css overriderait ces variables et causerait un
 *   restyle visuel des écrans kiosk existants — interdit par le gate Phase 0
 *   ("aucun composant Vue existant encore touché").
 *
 *   Activation prévue : Phase 2, en même temps que le premier restyle Vue.
 *   À ce moment :
 *     - Import dans resources/js/app.js ou dans l'entrypoint kiosk dédié
 *     - OU import unique dans l'entry HTML kiosk si on découple les bundles
 *     - Le fichier resources/css/kiosk-wizard.css sera rationalisé ou
 *       dépérécédé au fur et à mesure que les écrans consomment les atoms.
 *
 * Usage attendu (Phase 2+) :
 *   import '@/bootstrap-kiosk'; // côté Vue
 *   // ou `import { KsButton, KsCard } from '@/components/frontend/kiosk/ds'`
 *   //    pour tree-shaking fin.
 */

// -- CSS tokens (ordre important : base → overrides) ---------------------------
import '../css/kiosk/tokens.css';
import '../css/kiosk/tokens-aaa.css';
import '../css/kiosk/tokens-pmr.css';

// -- Atoms barrel (re-export) --------------------------------------------------
export {
    KsButton,
    KsCard,
    KsBadge,
    KsChip,
    KsModal,
    KsStepper,
    KsPriceLine,
    KioskDesignSystem,
} from './components/frontend/kiosk/ds';

export { default } from './components/frontend/kiosk/ds';
