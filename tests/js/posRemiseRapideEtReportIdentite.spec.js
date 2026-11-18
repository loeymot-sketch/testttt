import { describe, it, expect, vi } from 'vitest';

vi.mock('axios', () => ({ default: { get: vi.fn(() => Promise.resolve({ data: {} })), post: vi.fn(() => Promise.resolve({ data: {} })) } }));
vi.mock('../../resources/js/services/alertService', () => ({ default: { success: vi.fn(), error: vi.fn(), warning: vi.fn(), info: vi.fn() } }));

import PosComponent from '../../resources/js/components/admin/pos/PosComponent.vue';
import discountTypeEnum from '../../resources/js/enums/modules/discountTypeEnum';
import alertService from '../../resources/js/services/alertService';

// [2026-11-18 · propriétaire] « ça reste toujours complexe de faire une remise ou de passer d'emporter à livraison,
// ça efface ce que j'ai écrit ». Les méthodes sont exercées sur un contexte fabriqué à la main (comme les autres
// bancs de la caisse) : la règle est dans les méthodes, pas dans le gabarit.
const M = PosComponent.methods;
const C = PosComponent.computed;

function caisse(over = {}) {
    const ctx = {
        discountType: discountTypeEnum.PERCENTAGE,
        discount: null,
        discountReason: '',
        discountPanelManual: true,
        subtotal: 20,
        posDiscount: 0,
        peutRemiseAuDela10: false,
        setting: { site_digit_after_decimal_point: 2 },
        checkoutProps: { form: { discount: 0, discount_reason: null, pos_customer_name: '', pos_customer_phone: '', delivery_charge: 0, delivery_distance_km: null, dining_table_id: null, address_id: null } },
        deliveryInline: { name: '', phone: '', confirmed: false, quote: null },
        selectedAddress: {},
        $refs: {},
        $t: (k) => k,
        $store: { dispatch: vi.fn(() => Promise.resolve()) },
        clearDeliveryGeocodeError: vi.fn(),
        ...over,
    };
    for (const k of ['applyDiscount', 'appliquerRemiseRapide', 'choisirMotifRemise', 'retirerRemise', 'reporterIdentiteVersLivraison', 'reporterIdentiteVersComptoir', 'takeAwayOrder', 'deliveryOrder', 'dineInOrder']) {
        ctx[k] = M[k].bind(ctx);
    }
    Object.defineProperty(ctx, 'motifsRemise', { get: () => C.motifsRemise.call(ctx) });
    Object.defineProperty(ctx, 'remisesRapides', { get: () => C.remisesRapides.call(ctx) });
    Object.defineProperty(ctx, 'remiseRapideActive', { get: () => C.remiseRapideActive.call(ctx) });
    Object.defineProperty(ctx, 'discountAmountValue', { get: () => C.discountAmountValue.call(ctx) });
    return ctx;
}

describe('remise en un appui', () => {
    it('un appui sur « 10 % » applique la remise avec un motif présélectionné', () => {
        const c = caisse();
        const r = c.remisesRapides.find((x) => x.cle === 'p10');
        c.appliquerRemiseRapide(r);

        expect(c.checkoutProps.form.discount).toBe('2.00'); // 10 % de 20 €
        expect(c.checkoutProps.form.discount_reason).toBe('Geste commercial');
        expect(c.$store.dispatch).toHaveBeenCalledWith('posCart/discount', '2.00');
    });

    it('une remise en euros : « 2 € »', () => {
        const c = caisse();
        c.appliquerRemiseRapide(c.remisesRapides.find((x) => x.cle === 'f2'));
        expect(c.checkoutProps.form.discount).toBe('2.00');
        expect(c.discountType).toBe(discountTypeEnum.FIXED);
    });

    it('le motif déjà saisi par le caissier n\'est pas remplacé', () => {
        const c = caisse({ discountReason: 'Attente trop longue' });
        c.appliquerRemiseRapide(c.remisesRapides.find((x) => x.cle === 'p5'));
        expect(c.checkoutProps.form.discount_reason).toBe('Attente trop longue');
    });

    it('au-delà de 10 % : puces absentes sans le droit, présentes avec', () => {
        expect(caisse().remisesRapides.map((x) => x.cle)).toEqual(['p5', 'p10', 'f1', 'f2', 'f5']);
        expect(caisse({ peutRemiseAuDela10: true }).remisesRapides.map((x) => x.cle)).toEqual(['p5', 'p10', 'p15', 'p20', 'f1', 'f2', 'f5']);
    });

    it('une remise en euros supérieure au sous-total est refusée, rien n\'est appliqué', () => {
        const c = caisse({ subtotal: 3 });
        c.appliquerRemiseRapide(c.remisesRapides.find((x) => x.cle === 'f5'));
        expect(alertService.error).toHaveBeenCalled();
        expect(c.$store.dispatch).not.toHaveBeenCalled();
    });

    it('changer le motif d\'une remise posée met à jour le motif envoyé', () => {
        const c = caisse();
        c.appliquerRemiseRapide(c.remisesRapides.find((x) => x.cle === 'p10'));
        c.choisirMotifRemise('Client fidèle');
        expect(c.checkoutProps.form.discount_reason).toBe('Client fidèle');
    });

    it('« Retirer la remise » remet tout à zéro et replie le bloc', () => {
        const c = caisse();
        c.appliquerRemiseRapide(c.remisesRapides.find((x) => x.cle === 'p10'));
        c.retirerRemise();
        expect(Number(c.checkoutProps.form.discount)).toBe(0);
        expect(c.checkoutProps.form.discount_reason).toBeNull();
        expect(c.discountReason).toBe('');
        expect(c.discountPanelManual).toBe(false);
    });

    it('second appui sur la remise active : elle est retirée', () => {
        const c = caisse();
        const r = c.remisesRapides.find((x) => x.cle === 'p10');
        c.appliquerRemiseRapide(r);
        c.posDiscount = 2; // le panier porte maintenant la remise
        c.appliquerRemiseRapide(r);
        expect(Number(c.checkoutProps.form.discount)).toBe(0);
    });
});

describe('emporter ⇄ livraison : ce qui est écrit suit', () => {
    it('emporter → livraison : nom et téléphone repris', () => {
        const c = caisse();
        c.checkoutProps.form.pos_customer_name = 'Karim B.';
        c.checkoutProps.form.pos_customer_phone = '06 12 34 56 78';
        c.deliveryOrder();
        expect(c.deliveryInline.name).toBe('Karim B.');
        expect(c.deliveryInline.phone).toBe('06 12 34 56 78');
    });

    it('livraison → emporter : nom et téléphone repris, l\'adresse saisie est gardée pour le retour', () => {
        const c = caisse({ deliveryInline: { name: 'Sofia M.', phone: '0798765432', confirmed: true, address: '12 rue de la Gare', quote: { allowed: true, fee: 2.5, distance_km: 1.8 } } });
        c.checkoutProps.form.delivery_charge = 2.5;
        c.takeAwayOrder();
        expect(c.checkoutProps.form.pos_customer_name).toBe('Sofia M.');
        expect(c.checkoutProps.form.pos_customer_phone).toBe('0798765432');
        expect(c.checkoutProps.form.delivery_charge).toBe(0); // à emporter : pas de frais
        expect(c.deliveryInline.address).toBe('12 rue de la Gare');

        c.deliveryOrder(); // retour en livraison : l'adresse confirmée retrouve son prix
        expect(c.checkoutProps.form.delivery_charge).toBe(2.5);
        expect(c.checkoutProps.form.delivery_distance_km).toBe(1.8);
    });

    it('ce qui est déjà écrit de l\'autre côté n\'est jamais écrasé', () => {
        const c = caisse({ deliveryInline: { name: 'Sofia M.', phone: '', confirmed: false, quote: null } });
        c.checkoutProps.form.pos_customer_name = 'Karim B.';
        c.deliveryOrder();
        expect(c.deliveryInline.name).toBe('Sofia M.');
        c.takeAwayOrder();
        expect(c.checkoutProps.form.pos_customer_name).toBe('Karim B.');
    });

    it('adresse refusée (hors rayon) : aucun frais ressuscité au retour', () => {
        const c = caisse({ deliveryInline: { name: '', phone: '', confirmed: false, quote: { allowed: false, fee: 0 } } });
        c.deliveryOrder();
        expect(c.checkoutProps.form.delivery_charge).toBe(0);
    });

    it('reprise d\'une commande en attente : aucun report (le nom d\'un autre client n\'entre pas dans la commande reprise)', () => {
        const c = caisse({ _sansReportIdentite: true });
        c.checkoutProps.form.pos_customer_name = 'Client en cours';
        c.deliveryOrder();
        expect(c.deliveryInline.name).toBe('');
    });
});
