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
// -- Global A11y defaults (QW-1 disabled opacity, QW-2 focus-visible) ---------
// Cascade specificity 0 via `:where()` — scoped Vue styles always win.
import '../css/kiosk/global-a11y.css';

// -- [V2-5 Phase 2] Seasonal theme CSS files -----------------------------------
// Themes apply ONLY when the attribute `:root[data-kiosk-theme="<slug>"]` is
// posed by `kioskThemeManager.initialize(branchId)`. Importing them here is
// safe across the whole app (admin + kiosk) because no rule matches without
// the attribute. The standard.css "no-op" entry simply blanks the decorative
// vars to avoid any leak from a previously applied theme.
import '../css/kiosk/themes/_base.css';
import '../css/kiosk/themes/standard.css';
import '../css/kiosk/themes/halloween.css';
import '../css/kiosk/themes/christmas.css';

// -- [V2-5 Phase 2] Theme boot initialization ---------------------------------
// `bootstrap-kiosk.js` is imported globally from `app.js` (line 17), so this
// runs on every page load (admin and kiosk). That is intentional and harmless:
//   * Without `data-kiosk-theme` the CSS rules above match nothing.
//   * `kioskThemeManager.initialize()` short-circuits when `branchId` is null
//     (no fetch, no DOM mutation), so admin pages don't trigger an unwanted
//     network call. The kiosk surface is responsible for exposing the branch
//     ID via `window.kioskBranchId` (or a Vuex kioskStore) before this script
//     runs — when neither source is set we fall back to 'standard'.
import kioskThemeManager from './services/kioskThemeManager';

// Resolve branchId at apply-time, not at script-eval time, so any later
// hydration (Vuex restore, kiosk machine session lookup) is visible.
function resolveKioskBranchId() {
    if (typeof window === 'undefined') return null;
    if (window.kioskBranchId !== undefined && window.kioskBranchId !== null) {
        return window.kioskBranchId;
    }
    // Best-effort introspection of the kiosk store if Vuex is already up.
    try {
        const store = window.__kioskStore || window.kioskStore;
        const branchId = store?.state?.kioskMachine?.branch_id
            ?? store?.state?.kioskCart?.kioskMachine?.branch_id;
        if (branchId !== undefined && branchId !== null) return branchId;
    } catch (_) { /* silent */ }
    return null;
}

if (typeof window !== 'undefined') {
    const runInit = () => {
        try {
            kioskThemeManager.initialize(resolveKioskBranchId());
        } catch (_) {
            // Theme init is non-critical: never block the rest of the boot.
        }
    };
    if (typeof document !== 'undefined' && document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', runInit, { once: true });
    } else {
        // Document already parsed (e.g. when bundled below </body>): run now.
        runInit();
    }
}

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
