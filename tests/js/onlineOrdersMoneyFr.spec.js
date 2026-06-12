import fs from 'fs';
import path from 'path';
import { describe, it, expect } from 'vitest';
import { formatPrice } from '../../resources/js/helpers/formatPrice';

/**
 * [W-REM T-R2.0 Q-2 2026-06-12] Online-orders ARGENT + statut.
 *
 * Finding D-B2 (micro-audit loyalty-validation 2026-06-12):
 *   1. /admin/online-orders colonne MONTANT rendait `order.total_amount_price`
 *      brut ("7.00" — flatAmountFormat US, sans € ni virgule) alors que la
 *      même colonne POS a été healée WT-D-R1-F4 vers le formatter FR partagé
 *      `formatPrice()` ("7,00 €").
 *   2. Badge STATUT "Accepter" (verbe — fr.json label.accept partagé
 *      bouton/statut). Heal: clé STATUT distincte `label.accepted` =
 *      "Acceptée" pour les maps d'affichage; `label.accept` reste le verbe
 *      bouton/action (dropdown de transition "Accepter").
 *
 * Sentinel style: source-level assertions (same pattern as
 * labelKeyParityFrontend.spec.js) + unit contract on the shared formatter.
 */

const REPO_ROOT = path.resolve(__dirname, '../..');
const LIST_PATH = path.join(
    REPO_ROOT,
    'resources/js/components/admin/onlineOrders/OnlineOrderListComponent.vue'
);
const SHOW_PATH = path.join(
    REPO_ROOT,
    'resources/js/components/admin/onlineOrders/OnlineOrderShowComponent.vue'
);
const FR_PATH = path.join(REPO_ROOT, 'resources/js/languages/fr.json');

const listSrc = fs.readFileSync(LIST_PATH, 'utf-8');
const showSrc = fs.readFileSync(SHOW_PATH, 'utf-8');
const fr = JSON.parse(fs.readFileSync(FR_PATH, 'utf-8'));

describe('online-orders FR money rendering (Q-2)', () => {
    it('formatPrice turns the flatAmountFormat string "7.00" into "7,00 €"', () => {
        const out = formatPrice('7.00');
        expect(out).toContain('7,00');
        expect(out).toContain('€');
        expect(out).not.toContain('7.00');
    });

    it('OnlineOrderListComponent renders the amount via formatPrice, never raw', () => {
        // The raw interpolation was the bug: {{ order.total_amount_price }}
        expect(listSrc).not.toMatch(/\{\{\s*order\.total_amount_price\s*\}\}/);
        expect(listSrc).toMatch(/formatPrice\(\s*order\.total_amount_price\s*\)/);
    });

    it('OnlineOrderListComponent wires the shared adminPriceMixin', () => {
        expect(listSrc).toContain("from \"../../../helpers/formatPrice\"");
        expect(listSrc).toContain('adminPriceMixin');
    });
});

describe('online-orders status badge "Acceptée" (Q-2 — status key ≠ button key)', () => {
    it('fr.json has a dedicated STATUS key label.accepted = "Acceptée"', () => {
        expect(fr.label.accepted).toBe('Acceptée');
    });

    it('fr.json keeps the action verb label.accept = "Accepter" for buttons', () => {
        expect(fr.label.accept).toBe('Accepter');
    });

    it('OnlineOrderListComponent status display map + filter use label.accepted', () => {
        // Display map: [orderStatusEnum.ACCEPT]: this.$t("label.accepted")
        expect(listSrc).toMatch(
            /\[orderStatusEnum\.ACCEPT\]:\s*this\.\$t\(["']label\.accepted["']\)/
        );
        // Filter dropdown lists status NAMES, not actions → "Acceptée" too.
        expect(listSrc).toMatch(
            /orderStatusEnum\.ACCEPT,\s*name:\s*\$t\(['"]label\.accepted['"]\)/
        );
    });

    it('OnlineOrderShowComponent display map uses label.accepted (badge), action dropdown keeps the verb', () => {
        expect(showSrc).toMatch(
            /\[orderStatusEnum\.ACCEPT\]:\s*this\.\$t\(["']label\.accepted["']\)/
        );
        // The transition dropdown (orderStatusObject) is an ACTION — verb stays.
        expect(showSrc).toMatch(
            /name:\s*this\.\$t\(["']label\.accept["']\),\s*value:\s*orderStatusEnum\.ACCEPT/
        );
    });
});
