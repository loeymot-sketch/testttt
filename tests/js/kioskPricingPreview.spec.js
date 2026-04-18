import { describe, it, expect, vi, beforeEach } from 'vitest';
import {
    buildKioskPricingPreviewPayload,
    normalizeKioskPricingPreviewItem,
    createKioskPricingPreview,
} from '../../resources/js/helpers/kioskPricingPreview.js';

describe('kioskPricingPreview — payload normalization (SSOT)', () => {
    it('rejette les items sans item_id valide', () => {
        expect(normalizeKioskPricingPreviewItem({})).toBeNull();
        expect(normalizeKioskPricingPreviewItem({ item_id: 0 })).toBeNull();
        expect(normalizeKioskPricingPreviewItem({ item_id: -3 })).toBeNull();
        expect(normalizeKioskPricingPreviewItem({ item_id: 'abc' })).toBeNull();
    });

    it('quantity default = 1, clampée ≥ 1', () => {
        const a = normalizeKioskPricingPreviewItem({ item_id: 42 });
        expect(a.quantity).toBe(1);
        const b = normalizeKioskPricingPreviewItem({ item_id: 42, quantity: -5 });
        expect(b.quantity).toBe(1);
        const c = normalizeKioskPricingPreviewItem({ item_id: 42, quantity: 3 });
        expect(c.quantity).toBe(3);
    });

    it('projette variations/extras en { id: int } uniquement (strip prix)', () => {
        const n = normalizeKioskPricingPreviewItem({
            item_id: 42,
            item_variations: [{ id: 10, price: 99 }, { id: 11 }, { id: 'bad' }, null],
            item_extras: [{ id: 20, convert_price: 0.5 }, { id: 'x' }],
        });
        expect(n.item_variations).toEqual([{ id: 10 }, { id: 11 }]);
        expect(n.item_extras).toEqual([{ id: 20 }]);
    });

    it('instruction cappée à 255 chars', () => {
        const long = 'a'.repeat(400);
        const n = normalizeKioskPricingPreviewItem({ item_id: 42, instruction: long });
        expect(n.instruction).toHaveLength(255);
    });

    it('buildPayload filtre les items invalides et trim les coupons', () => {
        const payload = buildKioskPricingPreviewPayload({
            items: [{ item_id: 1, quantity: 2 }, { }, { item_id: 'xx' }],
            coupon_code: '   SAVE10  ',
            kiosk_promo_code: '',
        });
        expect(payload.items).toHaveLength(1);
        expect(payload.items[0].item_id).toBe(1);
        expect(payload.coupon_code).toBe('SAVE10');
        expect(payload).not.toHaveProperty('kiosk_promo_code');
    });

    it('n’accepte JAMAIS de clé `branch_id` ni `price` ni `total`', () => {
        const n = normalizeKioskPricingPreviewItem({
            item_id: 7,
            branch_id: 99,
            price: 12.5,
            total: 100,
            convert_price: 9.9,
        });
        expect(n).not.toHaveProperty('branch_id');
        expect(n).not.toHaveProperty('price');
        expect(n).not.toHaveProperty('total');
        expect(n).not.toHaveProperty('convert_price');
    });
});

describe('createKioskPricingPreview — debounce + SSOT', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    function makeAxiosMock(responseOrThrow) {
        const post = vi.fn(async (url, payload) => {
            if (typeof responseOrThrow === 'function') return responseOrThrow(url, payload);
            if (responseOrThrow instanceof Error) throw responseOrThrow;
            return responseOrThrow;
        });
        return { post };
    }

    it('debounce 400 ms par défaut avant de fire l’appel', async () => {
        const axiosMock = makeAxiosMock({
            data: { status: true, data: { grand_total: 12.5, lines: [], discount: 0 } },
        });
        const preview = createKioskPricingPreview({ axios: axiosMock });

        preview.request({ items: [{ item_id: 1, quantity: 1 }] });
        expect(axiosMock.post).not.toHaveBeenCalled();
        vi.advanceTimersByTime(399);
        expect(axiosMock.post).not.toHaveBeenCalled();
        vi.advanceTimersByTime(2);
        await Promise.resolve();
        await Promise.resolve();
        expect(axiosMock.post).toHaveBeenCalledTimes(1);
    });

    it('retombe sur null si items vide', async () => {
        const axiosMock = makeAxiosMock({ data: { status: true, data: {} } });
        const preview = createKioskPricingPreview({ axios: axiosMock });
        const p = preview.request({ items: [] });
        vi.advanceTimersByTime(500);
        await expect(p).resolves.toBeNull();
        expect(axiosMock.post).not.toHaveBeenCalled();
    });

    it('une nouvelle request annule la précédente (debounce reset)', async () => {
        const axiosMock = makeAxiosMock({
            data: { status: true, data: { grand_total: 10 } },
        });
        const preview = createKioskPricingPreview({ axios: axiosMock });

        preview.request({ items: [{ item_id: 1 }] });
        vi.advanceTimersByTime(200);
        preview.request({ items: [{ item_id: 2 }] });
        vi.advanceTimersByTime(200);
        expect(axiosMock.post).not.toHaveBeenCalled();
        vi.advanceTimersByTime(500);
        await Promise.resolve();
        await Promise.resolve();
        expect(axiosMock.post).toHaveBeenCalledTimes(1);
        expect(axiosMock.post.mock.calls[0][1].items[0].item_id).toBe(2);
    });

    it('mappe la réponse 200 {status,data:{grand_total,...}} → {total, lines, discount, raw}', async () => {
        const axiosMock = makeAxiosMock({
            data: {
                status: true,
                data: {
                    grand_total: 12.3,
                    discount: 1.2,
                    lines: [{ item_id: 7, subtotal: 11.1 }],
                    tax_total: 2.1,
                },
            },
        });
        const preview = createKioskPricingPreview({ axios: axiosMock });
        const p = preview.request({ items: [{ item_id: 7, quantity: 1 }] });
        vi.advanceTimersByTime(500);
        const res = await p;
        expect(res.total).toBe(12.3);
        expect(res.discount).toBe(1.2);
        expect(res.lines).toHaveLength(1);
        expect(res.raw.tax_total).toBe(2.1);
    });

    it('retourne null sur erreur réseau (pas de throw, fallback client possible)', async () => {
        const axiosMock = makeAxiosMock(new Error('network'));
        const preview = createKioskPricingPreview({ axios: axiosMock, onError: () => {}, maxAttempts: 1 });
        const p = preview.request({ items: [{ item_id: 1 }] });
        vi.advanceTimersByTime(500);
        await expect(p).resolves.toBeNull();
    });

    it('retry après timeout court puis finit par réussir', async () => {
        vi.useRealTimers();
        let attempts = 0;
        const axiosMock = {
            post: vi.fn((url, payload, config) => {
                attempts += 1;
                if (attempts < 3) {
                    return new Promise((resolve, reject) => {
                        config.signal.addEventListener('abort', () => {
                            reject(Object.assign(new Error('aborted'), { name: 'AbortError' }));
                        });
                    });
                }
                return Promise.resolve({
                    data: { status: true, data: { grand_total: 12.3, lines: [], discount: 0 } },
                });
            }),
        };
        const preview = createKioskPricingPreview({
            axios: axiosMock,
            debounceMs: 0,
            timeoutMs: 250,
            maxAttempts: 3,
            backoffBaseMs: 50,
        });

        const p = preview.request({ items: [{ item_id: 1, quantity: 1 }] });
        const res = await p;

        expect(attempts).toBe(3);
        expect(res.total).toBe(12.3);
        vi.useFakeTimers();
    }, 10000);

    it('destroy() annule les appels en attente', async () => {
        const axiosMock = makeAxiosMock({ data: { status: true, data: {} } });
        const preview = createKioskPricingPreview({ axios: axiosMock });
        preview.request({ items: [{ item_id: 1 }] });
        preview.destroy();
        vi.advanceTimersByTime(500);
        expect(axiosMock.post).not.toHaveBeenCalled();
    });
});
