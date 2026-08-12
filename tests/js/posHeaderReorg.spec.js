import { describe, it, expect } from 'vitest';
import { readFileSync } from 'fs';
import { resolve } from 'path';

/**
 * [POS-V5 HEADER-REORG 2026-07-21] Sentinelle source de la réorganisation de la
 * barre d'actions caisse (première page /admin/pos-v4).
 *
 * PosComponent.vue est un composant ~6k lignes avec de nombreuses dépendances
 * (store, echo, modals) : le monter en JSDOM est lourd et fragile. On vérifie
 * donc la structure directement sur la source (pattern déjà utilisé dans le
 * repo, cf. articleListLegacyRedirect.spec.js) :
 *   1. les 2 nouveaux accès router-link (Encaissement + Archives) + leurs testids ;
 *   2. les 2 clusters lisibles (Commandes / Caisse) ;
 *   3. la préservation de TOUS les data-testid + @click existants ;
 *   4. la redirection du lien historique du panneau cash vers l'historique unifié ;
 *   5. les 2 stubs de nom de route dans le mini-routeur pos-app.js (sans lesquels
 *      les router-link throw MATCHER_NOT_FOUND au montage de /admin/pos-v4).
 */

const posComponent = readFileSync(
    resolve(process.cwd(), 'resources/js/components/admin/pos/PosComponent.vue'),
    'utf-8',
);
const posApp = readFileSync(
    resolve(process.cwd(), 'resources/js/pos-app.js'),
    'utf-8',
);

describe('POS header reorg — nouveaux accès Encaissement + Archives', () => {
    it('ajoute un router-link Encaissement vers admin.encaissement avec le bon testid', () => {
        expect(posComponent).toMatch(/data-testid="pos-encaissement-open"/);
        // Bloc bouton Encaissement : as=router-link + :to name admin.encaissement.
        expect(posComponent).toMatch(
            /:to="\{ name: 'admin\.encaissement' \}"[\s\S]{0,200}data-testid="pos-encaissement-open"/,
        );
        // Libellé i18n existant réutilisé (aucune clé lang nouvelle).
        expect(posComponent).toMatch(/\$t\('menu\.encaissement'\)/);
    });

    it('ajoute un router-link Archives vers admin.historique.list avec le bon testid', () => {
        expect(posComponent).toMatch(/data-testid="pos-archives-open"/);
        expect(posComponent).toMatch(
            /:to="\{ name: 'admin\.historique\.list' \}"[\s\S]{0,200}data-testid="pos-archives-open"/,
        );
        expect(posComponent).toMatch(/\$t\('menu\.historique'\)/);
    });

    it('les 2 nouveaux liens sont bien des router-link (as="router-link")', () => {
        // Tranche du <PosV5Button ...> (ouverture) autour de chaque nouveau testid.
        const blockAround = (testid) => {
            const idx = posComponent.indexOf(`data-testid="${testid}"`);
            expect(idx).toBeGreaterThan(-1);
            const open = posComponent.lastIndexOf('<PosV5Button', idx);
            const close = posComponent.indexOf('</PosV5Button>', idx);
            return posComponent.slice(open, close);
        };
        const encBlock = blockAround('pos-encaissement-open');
        const arcBlock = blockAround('pos-archives-open');
        expect(encBlock).toContain('as="router-link"');
        expect(arcBlock).toContain('as="router-link"');
        // A11y : aria-label présent sur chaque nouveau lien.
        expect(encBlock).toContain(":aria-label=\"$t('menu.encaissement')\"");
        expect(arcBlock).toContain(":aria-label=\"$t('menu.historique')\"");
    });
});

describe('POS header reorg — 2 clusters lisibles', () => {
    it('rend un cluster « Commandes » et un cluster « Caisse »', () => {
        expect(posComponent).toMatch(/data-testid="pos-op-cluster-orders"/);
        expect(posComponent).toMatch(/data-testid="pos-op-cluster-caisse"/);
        // Chaque cluster = role=group + libellé + rangée de boutons.
        expect(posComponent).toMatch(/class="pos-op-cluster"[\s\S]{0,120}role="group"/);
        expect(posComponent).toMatch(/pos-op-cluster__label/);
        expect(posComponent).toMatch(/pos-op-cluster__row/);
        expect(posComponent).toMatch(/pos-op-cluster__divider/);
    });

    it('libellés de cluster via clés i18n existantes (label.orders / label.caisse)', () => {
        expect(posComponent).toMatch(/\$t\('label\.orders'\)/);
        expect(posComponent).toMatch(/\$t\('label\.caisse'\)/);
    });

    it('les styles scoped des clusters sont présents', () => {
        expect(posComponent).toMatch(/\.pos-op-cluster\s*\{/);
        expect(posComponent).toMatch(/\.pos-op-cluster__divider\s*\{/);
    });
});

describe('POS header reorg — préservation des boutons existants', () => {
    const preservedTestids = [
        'kiosk-cash-open',
        'pos-tracker-open',
        'pos-no-sale',
        'pos-cash-session-open',
        'pos-availability-panel-open',
        // [FIDÉLITÉ COMPTOIR 2026-08-12] Ce repère a été RENOMMÉ, et c'est voulu : le bouton n'ouvre
        // plus la remise mais l'IDENTIFICATION du client (retrouver, inscrire, cumuler, puis utiliser).
        // Il n'est d'ailleurs plus jamais grisé — l'ancien restait inaccessible sans commande en cours,
        // ce que le propriétaire avait signalé. Le repère surveillé suit donc le bouton.
        // La fenêtre de remise, elle, existe toujours et garde son propre repère
        // (`pos-loyalty-redeem-apply`, éprouvé par tests/js/posLoyaltyRedeemModal.spec.js).
        'pos-loyalty-identify-cta-open',
        'kiosk-cash-panel-history',
    ];

    it.each(preservedTestids)('conserve le data-testid "%s"', (testid) => {
        expect(posComponent).toContain(`data-testid="${testid}"`);
    });

    const preservedHandlers = [
        'showKioskCashPanel = true',
        'triggerNoSaleOpenDrawer',
        'openCashSessionDialog',
        'showAvailabilityPanel = true',
        'openLoyaltyMainModal',
    ];

    it.each(preservedHandlers)('conserve le @click "%s"', (handler) => {
        expect(posComponent).toContain(handler);
    });

    it('conserve les liens router existants (tracker + écran client)', () => {
        expect(posComponent).toMatch(/:to="\{ name: 'admin\.pos-orders\.tracker' \}"/);
        expect(posComponent).toMatch(/:to="\{ name: 'admin\.order-status-screen' \}"/);
    });
});

describe('POS header reorg — lien historique du panneau cash unifié', () => {
    it('le lien du panneau borne pointe désormais vers admin.historique.list', () => {
        const histLink = posComponent.match(
            /:to="\{ name: '([^']+)' \}"[\s\S]{0,160}?data-testid="kiosk-cash-panel-history"/,
        );
        expect(histLink).not.toBeNull();
        expect(histLink[1]).toBe('admin.historique.list');
        // L'ancienne cible pos-orders.list ne doit plus être attachée à ce lien.
        expect(posComponent).not.toMatch(
            /:to="\{ name: 'admin\.pos-orders\.list' \}"[\s\S]{0,160}?data-testid="kiosk-cash-panel-history"/,
        );
    });
});

describe('POS header reorg — stubs mini-routeur pos-app.js', () => {
    it('stub admin.encaissement présent (sinon MATCHER_NOT_FOUND au montage)', () => {
        expect(posApp).toMatch(/name:\s*'admin\.encaissement'/);
        expect(posApp).toMatch(
            /path:\s*'\/admin\/encaissement'[\s\S]{0,160}name:\s*'admin\.encaissement'[\s\S]{0,160}beforeEnter/,
        );
    });

    it('stub admin.historique.list présent', () => {
        expect(posApp).toMatch(/name:\s*'admin\.historique\.list'/);
        expect(posApp).toMatch(
            /path:\s*'\/admin\/historique'[\s\S]{0,160}name:\s*'admin\.historique\.list'[\s\S]{0,160}beforeEnter/,
        );
    });

    it('les stubs suivent le pattern hard-nav existant (window.location.assign)', () => {
        const encStub = posApp.match(
            /name:\s*'admin\.encaissement'[\s\S]{0,160}?\},/,
        );
        const histStub = posApp.match(
            /name:\s*'admin\.historique\.list'[\s\S]{0,160}?\},/,
        );
        expect(encStub[0]).toContain('window.location.assign(to.fullPath)');
        expect(histStub[0]).toContain('window.location.assign(to.fullPath)');
    });
});
