import { describe, it, expect, vi } from 'vitest';

// [UBER-CAISSE 2026-08-02] Verrouille la visibilité des commandes Uber Eats côté caisse.
// Avant : source_surface='uber_eats' n'était reconnu nulle part → le tracker classait la
// commande 'pos' (icône 🛒) et l'historique la badgeait « Livraison » anonyme. Prouvé en
// sandbox (commandes #283/#285/#288). Ce spec fige :
//   1. tracker sourceOf/sourceIcon → 'uber' / 🛵 (+ onglet dédié) ;
//   2. historique originBadge → « Uber Eats » AVANT le fallback DELIVERY ;
//   3. historique filtre 'uber' → search.source_surface='uber_eats'.

vi.mock('../../resources/js/services/appService', () => ({
    default: {
        permissionChecker: vi.fn(() => false),
        orderStatusClass: vi.fn(() => ''),
        handleSlide: vi.fn(),
    },
}));

import PosOrdersTrackerComponent from '../../resources/js/components/admin/pos/PosOrdersTrackerComponent.vue';
import HistoriqueListComponent from '../../resources/js/components/admin/orderHistory/HistoriqueListComponent.vue';

const $t = (k) => k;

describe('Tracker caisse — commandes Uber Eats', () => {
    const { sourceOf, sourceIcon } = PosOrdersTrackerComponent.methods;

    it('classe source_surface=uber_eats (et alias) comme source uber', () => {
        expect(sourceOf.call({}, { source_surface: 'uber_eats' })).toBe('uber');
        expect(sourceOf.call({}, { source_surface: 'UBER_EATS' })).toBe('uber');
        expect(sourceOf.call({}, { source_surface: 'uber' })).toBe('uber');
        expect(sourceOf.call({}, { source_surface: 'ubereats' })).toBe('uber');
    });

    it("ne classe plus une commande Uber comme 'pos' malgré order_type DELIVERY", () => {
        expect(sourceOf.call({}, { source_surface: 'uber_eats', order_type: 5 })).toBe('uber');
    });

    it('garde les classements existants intacts (non-régression)', () => {
        expect(sourceOf.call({}, { source_surface: 'kiosk' })).toBe('kiosk');
        expect(sourceOf.call({}, { source_surface: 'web' })).toBe('online');
        expect(sourceOf.call({}, { source_surface: 'pos' })).toBe('pos');
        expect(sourceOf.call({}, {})).toBe('pos');
    });

    it('affiche le scooter 🛵 pour une commande Uber', () => {
        const self = { sourceOf };
        expect(sourceIcon.call(self, { source_surface: 'uber_eats' })).toBe('🛵');
    });

    it("expose un onglet de filtre Uber dans sourceTabs", () => {
        const tabs = PosOrdersTrackerComponent.computed.sourceTabs.call({ $t });
        const uber = tabs.find((t) => t.id === 'uber');
        expect(uber).toBeTruthy();
        expect(uber.icon).toBe('🛵');
    });
});

describe('Historique — commandes Uber Eats', () => {
    const { originBadge, applyOriginFilter } = HistoriqueListComponent.methods;

    it('badge « Uber Eats » prioritaire sur le fallback DELIVERY (order_type=5)', () => {
        const b = originBadge.call({ $t }, { source_surface: 'uber_eats', order_type: 5 });
        expect(b.label).toBe('Uber Eats');
        expect(b.cls).toBe('origin-uber');
    });

    it('une livraison NON-Uber reste badgée Livraison (non-régression)', () => {
        const b = originBadge.call({ $t }, { source_surface: null, order_type: 5 });
        expect(b.cls).toBe('origin-delivery');
    });

    it("le filtre origine propose Uber Eats", () => {
        const opts = HistoriqueListComponent.computed.originOptions.call({ $t });
        expect(opts.some((o) => o.id === 'uber')).toBe(true);
    });

    it("filtre 'uber' → search.source_surface='uber_eats' côté backend", () => {
        const self = { props: { origin: 'uber', search: { source_surface: null, order_type: null } } };
        applyOriginFilter.call(self);
        expect(self.props.search.source_surface).toBe('uber_eats');
        expect(self.props.search.order_type).toBeNull();
    });
});
