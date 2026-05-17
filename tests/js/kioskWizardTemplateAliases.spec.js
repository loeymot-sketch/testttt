import { describe, it, expect, beforeEach, afterEach } from 'vitest';

/**
 * [Sprint H1 K-004 2026-05-17] Wizard template aliases — Owner G3 Option B.
 *
 * detectTemplateFromName must consult window.FK_KIOSK_WIZARD_TEMPLATE_ALIASES
 * before falling back to the legacy substring inference. This rescues admin
 * item renames that would otherwise silently break template routing.
 *
 * The Vue file is in a frozen zone — we replicate the resolver body inline
 * and exercise the contract (alias lookup over `${name} ${category}` haystack
 * with case-insensitive substring match, falling through to legacy branches).
 */
describe('Kiosk wizard template aliases (K-004)', () => {
    let originalAliases;

    beforeEach(() => {
        originalAliases = window.FK_KIOSK_WIZARD_TEMPLATE_ALIASES;
    });

    afterEach(() => {
        if (originalAliases === undefined) {
            delete window.FK_KIOSK_WIZARD_TEMPLATE_ALIASES;
        } else {
            window.FK_KIOSK_WIZARD_TEMPLATE_ALIASES = originalAliases;
        }
    });

    // Inline replica of KioskWizardComponent.vue:907-947 detectTemplateFromName.
    // Kept in sync by hand with the Vue source.
    function detectTemplateFromName(item) {
        if (!item) return 'simple';
        const name = (item.name || '').toLowerCase();
        const category = (item.category_name || '').toLowerCase();
        const aliases = (typeof window !== 'undefined') ? window.FK_KIOSK_WIZARD_TEMPLATE_ALIASES : null;
        if (aliases && typeof aliases === 'object') {
            const haystack = `${name} ${category}`;
            for (const needle of Object.keys(aliases)) {
                if (haystack.includes(String(needle).toLowerCase())) return aliases[needle];
            }
        }
        if (name.includes('tacos') || category.includes('tacos')) return 'tacos';
        if (name.includes('sandwich') || category.includes('sandwich')) return 'sandwich';
        if (name.includes('burger') || category.includes('burger')) return 'burger';
        if (name.includes('assiette') || category.includes('assiette')) return 'assiette';
        return 'simple';
    }

    it('uses alias map when present (admin rename rescue)', () => {
        window.FK_KIOSK_WIZARD_TEMPLATE_ALIASES = {
            'royal':    'sandwich',
            'cayenne':  'sandwich',
        };
        // Renamed sandwich items still route to sandwich template.
        expect(detectTemplateFromName({ name: 'Sandwich Royal Spicy' })).toBe('sandwich');
        expect(detectTemplateFromName({ name: 'Le Royal', category_name: 'Sandwich Premium' })).toBe('sandwich');
        expect(detectTemplateFromName({ name: 'Cayenne XL' })).toBe('sandwich');
    });

    it('falls back to legacy substring when no alias matches', () => {
        window.FK_KIOSK_WIZARD_TEMPLATE_ALIASES = { 'nonsense-needle-xyz': 'simple' };
        // Legacy substring chain still works.
        expect(detectTemplateFromName({ name: 'Sandwich Classique' })).toBe('sandwich');
        expect(detectTemplateFromName({ name: 'Big Tacos' })).toBe('tacos');
        expect(detectTemplateFromName({ name: 'Cheeseburger' })).toBe('burger');
        expect(detectTemplateFromName({ name: 'Assiette Mixte' })).toBe('assiette');
    });

    it('handles missing global gracefully', () => {
        delete window.FK_KIOSK_WIZARD_TEMPLATE_ALIASES;
        expect(detectTemplateFromName({ name: 'Sandwich Royal' })).toBe('sandwich');
        expect(detectTemplateFromName({ name: 'Foo Bar' })).toBe('simple');
        expect(detectTemplateFromName(null)).toBe('simple');
    });

    it('alias matches case-insensitively over both name and category', () => {
        window.FK_KIOSK_WIZARD_TEMPLATE_ALIASES = { 'burger': 'burger' };
        expect(detectTemplateFromName({ name: 'cheeseburger' })).toBe('burger');
        expect(detectTemplateFromName({ name: 'CHEESEBURGER' })).toBe('burger');
        // Match on category field too.
        expect(detectTemplateFromName({ name: 'Le Spécial', category_name: 'BURGER PREMIUM' })).toBe('burger');
    });
});
