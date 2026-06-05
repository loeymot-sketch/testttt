import { describe, it, expect } from 'vitest';
import appService from '../../resources/js/services/appService.js';

/**
 * S1-DASH-01 — dashboard date filters silently broke: the datepicker passed a
 * raw JS Date whose toString() ("Tue Jun 03 2026 00:00:00 GMT+0200 (...)") the
 * backend Carbon::parse could not read → 422 → the chart kept showing the STALE
 * previous range with no error. requestHandler must serialize Dates to a
 * Carbon-friendly YYYY-MM-DD.
 */
describe('S1-DASH-01 appService.requestHandler date serialization', () => {
    it('serializes a JS Date param to local YYYY-MM-DD (not toString)', () => {
        const d = new Date(2026, 5, 3); // June 3, 2026 (month index 5), local
        const qs = appService.requestHandler({ first_date: d, last_date: d });

        expect(qs).toContain('first_date=2026-06-03');
        expect(qs).toContain('last_date=2026-06-03');
        expect(qs).not.toContain('GMT');
        expect(qs).not.toContain('Jun');
    });

    it('leaves plain string/number params untouched', () => {
        const qs = appService.requestHandler({ q: 'tacos', page: 2 });
        expect(qs).toBe('?q=tacos&page=2');
    });

    it('skips empty/null params (unchanged behavior)', () => {
        // NB: requestHandler has a pre-existing leading-"&" quirk when leading
        // params are empty (out of scope for S1-DASH-01); assert quirk-agnostically.
        const qs = appService.requestHandler({ a: '', b: null, c: 'x' });
        expect(qs).toContain('c=x');
        expect(qs).not.toContain('a=');
        expect(qs).not.toContain('b=');
    });
});
