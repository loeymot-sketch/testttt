import { describe, it, expect } from 'vitest';
import appService from '../../resources/js/services/appService';

/**
 * [GOAL_ADMIN_NAV_BREADTH_CONVERGENCE_2026-08-13] appService.requestHandler
 * builds GET query strings for ~54 admin list/report store modules
 * (Currency, Tax, Sales Report, Items Report, etc.) by string-concatenating
 * raw filter values with ZERO URL-encoding: `response += request + "=" +
 * requests[request]`.
 *
 * Real, reproduced defect: filtering the Items Report by an item whose name
 * contains a literal "+" (e.g. "Menu (Frites + Boisson)", a real catalog
 * item, 11233 units sold all-time per a direct DB query) silently returned
 * ZERO rows in the live UI. Root cause traced via a network probe: the
 * unencoded "+" survives into the query string, and PHP's
 * application/x-www-form-urlencoded parsing (parse_str, used by Laravel's
 * request parsing) decodes an unencoded "+" as a SPACE — "Frites + Boisson"
 * becomes "Frites   Boisson" server-side, which then fails to LIKE-match
 * the real "Frites + Boisson" value in the items table. Any other
 * URL-significant character (&, #, %) in a filter value would corrupt the
 * query string the same way.
 */
describe('appService.requestHandler — URL-encodes filter values', () => {
    it('percent-encodes a literal "+" so it is not decoded as a space server-side', () => {
        const qs = appService.requestHandler({ name: 'Menu (Frites + Boisson)' });
        expect(qs).toContain('name=Menu%20(Frites%20%2B%20Boisson)');
        expect(qs).not.toMatch(/[^%]\+[^%]/); // no raw "+" outside of an encoded triplet
    });

    it('percent-encodes "&" so it does not split into an extra query param', () => {
        const qs = appService.requestHandler({ name: 'Salt & Pepper' });
        expect(qs).toBe('?name=Salt%20%26%20Pepper');
    });

    it('still joins multiple params with "&" between them (only VALUES are encoded, not separators)', () => {
        const qs = appService.requestHandler({ a: '1', b: '2' });
        expect(qs).toBe('?a=1&b=2');
    });

    it('skips empty-string and null values (behavior preserved, unrelated to this fix)', () => {
        const qs = appService.requestHandler({ a: '', b: null, c: 'x' });
        expect(qs).not.toContain('a=');
        expect(qs).not.toContain('b=');
        expect(qs).toContain('c=x');
    });

    it('returns empty string when every value is empty/null (behavior preserved)', () => {
        expect(appService.requestHandler({ a: '', b: null })).toBe('');
    });
});
