import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * @FK-ID  B2-P6-F01 — Confirm-before-cancel for kiosk-cash counter-collect
 * @source HEAL AGENT 1 mandate 2026-05-26
 *
 * Verrou structural anti-régression : la commande borne payée (counter-
 * collect kiosk-cash) ne doit JAMAIS être annulée sur un click sec.
 *
 * Owner mandate : "ajouter confirmation avant action destructive
 * irréversible". Mirrors the PosOrdersTrackerComponent.cancelDialog
 * pattern (role=dialog + aria-modal + textarea reason ≥3 chars + danger
 * confirm button).
 *
 * Sans ce gate :
 *   - PosComponent.vue:1275 firait POST .../cancel directement
 *   - reason hardcoded client-side ("Commande borne annulee au comptoir")
 *   - aucun motif opérateur véritable dans le journal d'annulation
 *
 * Tests garantissent que :
 *   1. Le bouton "Annuler" ouvre la dialog (pas le POST direct)
 *   2. La dialog impose un motif ≥ 3 caractères avant de fire
 *   3. closeCancelKioskCashDialog ne touche pas axios
 *   4. confirmCancelKioskCashOrder envoie le motif TYPÉ (pas le hardcode)
 *   5. i18n keys requises sont présentes (fr/en/ar)
 *   6. Le composant rend bien un role=dialog + aria-modal=true
 */
describe('B2-P6-F01 — PosComponent kiosk-cash confirm-before-cancel', () => {
    const sourcePath = resolve(
        process.cwd(),
        'resources/js/components/admin/pos/PosComponent.vue',
    );
    const source = readFileSync(sourcePath, 'utf8');

    it('the kiosk-cash Annuler button opens the confirm dialog, not the destructive action', () => {
        // The button at line ~1275 must now route through openCancelKioskCashDialog
        // (which opens a modal) instead of cancelKioskCashOrder (which used to
        // POST directly).
        expect(source).toMatch(
            /class="kiosk-cash-cancel-btn"[\s\S]{0,400}@click="openCancelKioskCashDialog\(order\)"/,
        );
        // And the destructive method name MUST NOT be wired to the Annuler
        // button @click anymore. Search for a regression where someone
        // re-introduces a direct click handler on cancelKioskCashOrder.
        expect(source).not.toMatch(
            /class="kiosk-cash-cancel-btn"[\s\S]{0,400}@click="cancelKioskCashOrder\(/,
        );
    });

    // [GOAL REMARQUES 2026-10-03 · R-060] DÉCISION PROPRIÉTAIRE PLUS RÉCENTE que B2-P6-F01 (2026-05-26) :
    // 29/09 « je veux pas cliquer sur chacune et je mettre la justificatif pour pouvoir annuler
    // directement », 03/10 « l'annulation des commandes annuler par téléphone ». Ce que la sentinelle
    // protégeait — une CONFIRMATION avant l'action destructive — est conservé (dialog + bouton « Oui »,
    // vérifiés par les autres cas). Seule la SAISIE obligatoire du motif disparaît : il est pré-rempli
    // (« Client non venu ») et un motif vide retombe dessus — la trace d'audit n'est jamais vide.
    it('the confirm method never sends an empty motive (pre-filled default, typed motive kept)', () => {
        expect(source).toMatch(/confirmCancelKioskCashOrder/);
        const confirmBlock = source.match(
            /async\s+confirmCancelKioskCashOrder\s*\([\s\S]*?\n\s{8}\},/,
        );
        expect(confirmBlock).not.toBeNull();
        const body = confirmBlock[0];
        // a typed motive ≥ 3 chars is kept, otherwise the default motive is used
        expect(body).toMatch(/tape\.length\s*>=\s*3\s*\?\s*tape\s*:\s*MOTIF_ANNULATION_PAR_DEFAUT/);
        expect(source).toMatch(/const MOTIF_ANNULATION_PAR_DEFAUT = 'Client non venu';/);
        // axios POST goes through this method
        expect(body).toMatch(/axios\.post\([^)]*counter-collect\/\$\{[^}]+\}\/cancel/);
        // payload sends the operator-typed reason variable, NOT the legacy
        // hardcoded string
        expect(body).toMatch(/\{\s*reason\s*\}/);
        expect(body).not.toMatch(/'Commande borne annulee au comptoir'/);
    });

    it('the close method never touches axios — it only resets dialog state', () => {
        const closeBlock = source.match(
            /closeCancelKioskCashDialog\s*\(\s*\)\s*\{[\s\S]*?\n\s{8}\},/,
        );
        expect(closeBlock).not.toBeNull();
        const body = closeBlock[0];
        // No axios call from close — closing the dialog must NEVER fire the
        // destructive POST.
        expect(body).not.toMatch(/axios\./);
        // Resets `open` to false.
        expect(body).toMatch(/open:\s*false/);
        // Bails out if dialog is busy (so the user cannot dismiss mid-flight
        // and orphan the cancel).
        expect(body).toMatch(/cancelKioskCashDialog\.busy/);
    });

    it('the open method seeds a fresh dialog state (pre-filled motive) and focuses the confirm button', () => {
        const openBlock = source.match(
            /openCancelKioskCashDialog\s*\(\s*order\s*\)\s*\{[\s\S]*?\n\s{8}\},/,
        );
        expect(openBlock).not.toBeNull();
        const body = openBlock[0];
        // No axios call from open — opening the dialog must NEVER fire the
        // destructive POST.
        expect(body).not.toMatch(/axios\./);
        // Resets reason + error, opens the modal.
        expect(body).toMatch(/open:\s*true/);
        // [R-060 2026-10-03] motif pré-rempli (rien à taper), focus sur « Oui » (Entrée confirme).
        expect(body).toMatch(/reason:\s*MOTIF_ANNULATION_PAR_DEFAUT/);
        expect(body).toMatch(/error:\s*''/);
        expect(body).toMatch(/cancelKioskCashConfirmBtn/);
    });

    it('the dialog template uses ARIA dialog semantics', () => {
        // role=dialog + aria-modal=true + aria-labelledby
        expect(source).toMatch(
            /v-if="cancelKioskCashDialog\.open"[\s\S]{0,400}role="dialog"[\s\S]{0,400}aria-modal="true"[\s\S]{0,400}aria-labelledby/,
        );
        // ESC and backdrop-click close handlers wired.
        expect(source).toMatch(/@click\.self="closeCancelKioskCashDialog"/);
        expect(source).toMatch(/@keydown\.esc="closeCancelKioskCashDialog"/);
    });

    it('the dialog danger button only triggers confirm, the ghost button only closes', () => {
        // Danger confirm button calls confirmCancelKioskCashOrder
        expect(source).toMatch(
            /pos-kiosk-cash-cancel-btn--danger[\s\S]{0,400}@click="confirmCancelKioskCashOrder"/,
        );
        // Back / ghost button only closes the dialog
        expect(source).toMatch(
            /pos-kiosk-cash-cancel-btn--ghost[\s\S]{0,400}@click="closeCancelKioskCashDialog"/,
        );
    });

    it('i18n keys are present in fr/en/ar', () => {
        const fr = JSON.parse(readFileSync(
            resolve(process.cwd(), 'resources/js/languages/fr.json'),
            'utf8',
        ));
        const en = JSON.parse(readFileSync(
            resolve(process.cwd(), 'resources/js/languages/en.json'),
            'utf8',
        ));
        const ar = JSON.parse(readFileSync(
            resolve(process.cwd(), 'resources/js/languages/ar.json'),
            'utf8',
        ));
        for (const bundle of [fr, en, ar]) {
            expect(bundle.pos).toBeTruthy();
            expect(bundle.pos.cancel_kiosk_cash).toBeTruthy();
            expect(bundle.pos.cancel_kiosk_cash.title).toBeTruthy();
            expect(bundle.pos.cancel_kiosk_cash.warning).toBeTruthy();
            expect(bundle.pos.cancel_kiosk_cash.reason_required).toBeTruthy();
            expect(bundle.pos.cancel_kiosk_cash.confirm_btn).toBeTruthy();
            expect(bundle.pos.cancel_kiosk_cash.back_btn).toBeTruthy();
        }
    });

    it('data() declares cancelKioskCashDialog with the canonical shape', () => {
        expect(source).toMatch(
            /cancelKioskCashDialog:\s*\{\s*open:\s*false,\s*order:\s*null,\s*reason:\s*'',\s*error:\s*'',\s*busy:\s*false,/,
        );
    });
});
