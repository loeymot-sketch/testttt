import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';

describe('FK-079 POS discount reason binding sentinel', () => {
    it('keeps S09 discountReason bound from UI input to submit payload', () => {
        const source = fs.readFileSync(
            path.join(process.cwd(), 'resources/js/components/admin/pos/PosComponent.vue'),
            'utf8',
        );

        expect(source).toContain('id="pos-discount-reason"');
        expect(source).toContain('v-model="discountReason"');
        expect(source).toContain("discountReason: ''");
        expect(source).toContain('this.checkoutProps.form.discount_reason = reason');
        expect(source).toContain('this.checkoutProps.form.discount_reason = null');
        expect(source).toContain('message.discount_reason_required');
    });
});
