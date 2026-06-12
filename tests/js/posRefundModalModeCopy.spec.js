/**
 * [HEAL dispute-r3 B-R1-06 2026-06-12] — copy miroir NF525 mensongère pre-Z.
 * -----------------------------------------------------------------------------
 * R1/R2 adversarial : le warning du PosRefundModal promettait TOUJOURS
 * « génère une commande miroir NF525 » alors que la voie pre-Z (commande dans
 * le Z encore ouvert) ne produit AUCUN miroir (PosOrderController::refundPreZ
 * — RETURNED + cashBack, mode='pre_z', mirror=null — vérifié DB : aucun order
 * parent_order_id IN (4531, 4334)).
 *
 * Invariants verrouillés :
 *  1. Le modal sonde GET /admin/pos-order/{id}/refund-mode à l'ouverture
 *     (le prédicat « sealed? » reste serveur — SealedOrderGuard SSOT).
 *  2. Copy conditionnelle au mode : pre_z → warning_pre_z (PAS de promesse de
 *     miroir), counter_entry → warning_post_z (miroir NF525), inconnu →
 *     warning générique honnête (les 2 cas décrits).
 *  3. Toast succès conditionnel au mode renvoyé par le POST.
 *  4. Clefs FR honnêtes + parité EN.
 */
import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { readFileSync } from 'fs';
import { resolve } from 'path';
import PosRefundModal from '../../resources/js/components/admin/pos/PosRefundModal.vue';

const fr = JSON.parse(readFileSync(resolve(process.cwd(), 'resources/js/languages/fr.json'), 'utf-8'));
const en = JSON.parse(readFileSync(resolve(process.cwd(), 'resources/js/languages/en.json'), 'utf-8'));

const mountWith = (orderProps = { id: 42, total: 19.5 }) => mount(PosRefundModal, {
    props: { order: orderProps },
    global: {
        mocks: {
            $t: (key, params) => key + (params ? ':' + JSON.stringify(params) : ''),
        },
    },
});

describe('[B-R1-06] warning conditionnel au mode de refund', () => {
    it('mode inconnu (probe pas encore résolue / échouée) → warning générique', () => {
        const wrapper = mountWith();
        const warn = wrapper.find('[data-testid="pos-refund-modal-warning"]');
        expect(warn.exists()).toBe(true);
        expect(warn.text()).toContain('pos.refund.warning');
        expect(warn.text()).not.toContain('warning_pre_z');
        expect(warn.text()).not.toContain('warning_post_z');
    });

    it('mode pre_z → warning_pre_z (aucune promesse de miroir)', async () => {
        const wrapper = mountWith();
        wrapper.vm.refundMode = 'pre_z';
        await wrapper.vm.$nextTick();
        const warn = wrapper.find('[data-testid="pos-refund-modal-warning"]');
        expect(warn.text()).toContain('pos.refund.warning_pre_z');
        expect(warn.attributes('data-refund-mode')).toBe('pre_z');
    });

    it('mode counter_entry → warning_post_z (miroir NF525 réel)', async () => {
        const wrapper = mountWith();
        wrapper.vm.refundMode = 'counter_entry';
        await wrapper.vm.$nextTick();
        const warn = wrapper.find('[data-testid="pos-refund-modal-warning"]');
        expect(warn.text()).toContain('pos.refund.warning_post_z');
        expect(warn.attributes('data-refund-mode')).toBe('counter_entry');
    });

    it('fetchRefundMode applique le mode renvoyé par le probe (et ignore les valeurs inattendues)', async () => {
        // NB: le watcher `order` (immediate) déclenche déjà fetchRefundMode au
        // mount — mock PERSISTANT (pas Once) pour couvrir les deux appels.
        const axiosModule = (await import('axios')).default;
        const spy = vi.spyOn(axiosModule, 'get').mockResolvedValue({ data: { success: true, mode: 'pre_z' } });
        try {
            const wrapper = mountWith({ id: 77, total: 5 });
            await wrapper.vm.fetchRefundMode(77);
            expect(wrapper.vm.refundMode).toBe('pre_z');
            expect(spy).toHaveBeenCalledWith('admin/pos-order/77/refund-mode');
        } finally {
            spy.mockRestore();
        }

        const spy2 = vi.spyOn(axiosModule, 'get').mockResolvedValue({ data: { success: true, mode: 'garbage' } });
        try {
            const wrapper = mountWith({ id: 78, total: 5 });
            await wrapper.vm.fetchRefundMode(78);
            expect(wrapper.vm.refundMode).toBe(null);
        } finally {
            spy2.mockRestore();
        }
    });

    it('probe en échec → mode null, warning générique (jamais de crash)', async () => {
        const axiosModule = (await import('axios')).default;
        const spy = vi.spyOn(axiosModule, 'get').mockRejectedValueOnce(new Error('403'));
        try {
            const wrapper = mountWith({ id: 79, total: 5 });
            await wrapper.vm.fetchRefundMode(79);
            expect(wrapper.vm.refundMode).toBe(null);
        } finally {
            spy.mockRestore();
        }
    });

    it('successMessageKey conditionne le toast succès au mode du POST', () => {
        const wrapper = mountWith();
        expect(wrapper.vm.successMessageKey('pre_z')).toBe('pos.refund.success_pre_z');
        expect(wrapper.vm.successMessageKey('counter_entry')).toBe('pos.refund.success_post_z');
        expect(wrapper.vm.successMessageKey(undefined)).toBe('pos.refund.success');
    });
});

describe('[B-R1-06] copy FR honnête + parité EN', () => {
    it('warning_pre_z NE promet PAS de miroir et décrit la voie réelle', () => {
        const copy = fr.pos.refund.warning_pre_z;
        expect(typeof copy).toBe('string');
        expect(copy).toMatch(/aucun ticket miroir/i);
        expect(copy).toMatch(/journée en cours/i);
        expect(copy).toMatch(/irréversible/i);
        expect(copy).not.toMatch(/génère une commande miroir/i);
    });

    it('warning_post_z décrit le miroir NF525 (voie sealed réelle)', () => {
        const copy = fr.pos.refund.warning_post_z;
        expect(copy).toMatch(/miroir NF525/i);
        expect(copy).toMatch(/irréversible/i);
    });

    it('warning générique (fallback) décrit les DEUX voies sans mentir', () => {
        const copy = fr.pos.refund.warning;
        expect(copy).toMatch(/irréversible/i);
        expect(copy).toMatch(/journée en cours/i);
        expect(copy).toMatch(/miroir/i);
        // L'ancienne copy inconditionnelle « Cette action génère une commande
        // miroir NF525 » ne doit plus être le fallback.
        expect(copy.startsWith('Cette action génère une commande miroir')).toBe(false);
    });

    it('toasts succès par mode présents', () => {
        expect(fr.pos.refund.success_pre_z).toMatch(/remboursé|remboursement/i);
        expect(fr.pos.refund.success_post_z).toMatch(/miroir/i);
    });

    it.each(['warning_pre_z', 'warning_post_z', 'success_pre_z', 'success_post_z'])(
        'en.json pos.refund.%s existe (parité)',
        (key) => {
            expect(typeof en.pos.refund[key]).toBe('string');
        }
    );
});
