/**
 * FoodKing Kiosk — Bootstrap Design System
 * -----------------------------------------------------------------------------
 * Entry point DS kiosk. Charge dans l'ordre :
 *  1. tokens.css          (base AA — obligatoire)
 *  2. tokens-aaa.css      (mode contraste renforcé — actif via data-kiosk-contrast="aaa")
 *  3. tokens-pmr.css      (mode PMR — actif via data-kiosk-pmr="true")
 *  4. tokens-bold.css     (CV1-KIOSK-VISUAL-REDESIGN-001 V1.1 — palette warm + dark)
 *  5. typography-bold.css (V1.2 — Fraunces display + scale)
 *  6. barrel exports des atoms ds/
 *
 * ✅ Phase 2 (depuis 2026-04-XX) : ce fichier EST câblé à resources/js/app.js
 *   ligne ~17 (`import KioskDesignSystem from './bootstrap-kiosk';`).
 *   Donc tous les imports CSS ci-dessous sont actifs sur tous les bundles
 *   qui chargent app.js (admin + kiosk + POS + KDS). Cela reste safe :
 *   - Les tokens --kiosk-* sont des variables passives sans side-effect
 *     tant qu'aucun composant ne les consomme.
 *   - Le composable useKioskTheme (V1.3) doit être appelé explicitement
 *     par un composant kiosk (ex. KioskAppComponent en V2) pour activer
 *     la propagation data-kiosk-theme.
 *   - Fraunces font woff2 est chargée kiosk-only via master.blade.php @if.
 *
 * V1 Bold — Discipline ADDITIVE :
 *   tokens-bold.css n'override AUCUN --kiosk-* existant. Les écrans actuels
 *   ne sont pas impactés. Les NEW variants atoms (KsButton hero, KsCard hero…)
 *   consomment --kiosk-bold-* et héritent du switch light/dark via
 *   useKioskTheme (V1.3) qui propage data-kiosk-theme sur <html>.
 *
 *   bootstrapKioskThemeEarly() est appelé AVANT le mount Vue pour éviter le
 *   FOUC (flash of unstyled content) au chargement.
 *
 * Usage attendu (Phase 2+) :
 *   import '@/bootstrap-kiosk'; // côté Vue
 *   // ou `import { KsButton, KsCard } from '@/components/frontend/kiosk/ds'`
 *   //    pour tree-shaking fin.
 */

// -- CSS tokens (ordre important : base → overrides → couche bold ----------------
import '../css/kiosk/tokens.css';
import '../css/kiosk/tokens-aaa.css';
import '../css/kiosk/tokens-pmr.css';
/* CV1-KIOSK-VISUAL-REDESIGN-001 V1.1 + V1.2 — Bold Appétissant foundations */
import '../css/kiosk/tokens-bold.css';
import '../css/kiosk/typography-bold.css';

// -- Bootstrap thème synchrone (anti-FOUC) ------------------------------------
import { bootstrapKioskThemeEarly } from './composables/useKioskTheme';
bootstrapKioskThemeEarly();

// -- Wave Y D1 (2026-05-21) — Single-flight CustomEvent debouncer ------------
// Kills the duplicate "Session rafraîchie automatiquement" toast triggered
// when N concurrent kiosk requests race on a 401 burst. See
// `./helpers/kioskAuthInterceptor.js` for the full rationale (why we
// debounce the EVENT layer and not the axios layer — both lower layers
// already coalesce). Idempotent install — bootstrap-kiosk may be imported
// from multiple entries / HMR cycles.
import { installKioskAuthInterceptor } from './helpers/kioskAuthInterceptor';
installKioskAuthInterceptor();

// -- FoodKing brand V2 (2026-05-10) — Visual theme par défaut LIGHT ---------
// Owner instruction 2026-05-21 : "je veux plus l'icône de changer de thème,
// laisse en mode Light c'est tout". Dark mode est désactivé entièrement.
// On force localStorage='light' au boot, MÊME si l'utilisateur avait stocké
// 'dark' avant le retrait du toggle (sinon idle/main pages restent dark).
try {
    if (typeof window !== 'undefined' && window.localStorage) {
        window.localStorage.setItem('foodking:kiosk-theme', 'light');
    }
    // Propage `data-kiosk-theme="light"` sur <html> immédiatement, avant
    // que useKioskTheme ne lise localStorage. Évite le FOUC sur le 1er paint
    // si Vue avait pre-rendered avec `data-kiosk-theme="dark"`.
    if (typeof document !== 'undefined' && document.documentElement) {
        document.documentElement.setAttribute('data-kiosk-theme', 'light');
    }
} catch (_) { /* SSR / private mode — ignore */ }

// -- Atoms barrel (re-export) --------------------------------------------------
export {
    KsButton,
    KsCard,
    KsBadge,
    KsChip,
    KsModal,
    KsStepper,
    KsPriceLine,
    KsThemeToggle,
    KsHero,
    KioskDesignSystem,
} from './components/frontend/kiosk/ds';

export { default } from './components/frontend/kiosk/ds';
