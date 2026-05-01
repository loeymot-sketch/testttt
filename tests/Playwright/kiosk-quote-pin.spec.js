const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

function readRepoFile(relativePath) {
    return fs.readFileSync(path.join(process.cwd(), relativePath), 'utf8');
}

function sliceBetween(source, start, end) {
    const startIndex = source.indexOf(start);
    const endIndex = source.indexOf(end, startIndex + start.length);
    expect(startIndex).toBeGreaterThanOrEqual(0);
    expect(endIndex).toBeGreaterThan(startIndex);
    return source.slice(startIndex, endIndex);
}

test.describe('Kiosk quote pricing pin contract', () => {
    test('cart exit pins a server quote before routing away from cart', async () => {
        const storeSource = readRepoFile('resources/js/store/modules/kioskCart.js');
        const cartSource = readRepoFile('resources/js/components/frontend/kiosk/KioskCartComponent.vue');

        const quotePayloadBuilder = sliceBetween(
            storeSource,
            'export function buildKioskQuotePayload',
            'export function buildKioskOrderPayload',
        );
        expect(quotePayloadBuilder).toContain('KioskMachine');
        expect(quotePayloadBuilder).not.toMatch(/\bsubtotal\s*:/);
        expect(quotePayloadBuilder).not.toMatch(/\bdiscount\s*:/);
        expect(quotePayloadBuilder).not.toMatch(/\bdelivery_charge\s*:/);
        expect(quotePayloadBuilder).not.toMatch(/\btotal\s*:/);
        expect(quotePayloadBuilder).not.toContain('branch_id:');

        expect(storeSource).toContain('async quoteOrder({ commit, state }');
        expect(storeSource).toContain("axios.post('frontend/order/quote', payload)");
        expect(storeSource).toContain("commit('SET_ORDER_QUOTE', quote)");
        expect(storeSource).toContain('payload.quote_token = quote.quote_token');
        expect(storeSource).toContain('payload.quote_signature = quote.signature');
        expect(storeSource).toContain('delete offlinePayload.quote_token');
        expect(storeSource).toContain('delete offlinePayload.quote_signature');
        expect(storeSource).toContain('delete offlinePayload.total');

        const proceedMethod = sliceBetween(
            cartSource,
            'async proceedToUpsell()',
            '// formatPrice() is provided by kioskPriceMixin',
        );
        expect(proceedMethod).toContain('await this.quoteOrder({ orderType: this.orderType })');
        expect(proceedMethod.indexOf('await this.quoteOrder')).toBeLessThan(proceedMethod.indexOf("this.$router.push"));
        expect(cartSource).toContain("'quoteOrder'");
        expect(cartSource).toContain(':disabled="quoteLoading"');
        expect(cartSource).toContain('data-testid="kiosk-cart-quote-error"');
    });
});
