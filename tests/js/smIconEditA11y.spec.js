/**
 * [abuse-e2e A11Y-EDIT-01 2026-06-16] The shared icon-only row-edit buttons
 * (SmIconModalEditComponent / SmIconSidebarModalEditComponent) used across every admin table
 * had NO accessible name: the only text child is a `.db-tooltip` span which is
 * `visibility:hidden` by default → excluded from the accessible name per WAI-ARIA, leaving a
 * screen reader to announce just "button". The sibling Delete/View buttons were already fixed
 * with :aria-label; this closes the incomplete a11y pass.
 *
 * Runtime mount asserts the button now carries a non-empty aria-label (+ title), with the FR
 * fallback when the i18n key is unresolved — so it works even before a translation lands.
 */
import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import SmIconModalEditComponent from '../../resources/js/components/admin/components/buttons/SmIconModalEditComponent.vue';
import SmIconSidebarModalEditComponent from '../../resources/js/components/admin/components/buttons/SmIconSidebarModalEditComponent.vue';

function mountWith(component, tStub) {
    return mount(component, { global: { mocks: { $t: tStub } } });
}

describe('A11Y-EDIT-01 — icon-only edit buttons expose an accessible name', () => {
    for (const [name, comp] of [
        ['SmIconModalEditComponent', SmIconModalEditComponent],
        ['SmIconSidebarModalEditComponent', SmIconSidebarModalEditComponent],
    ]) {
        it(`${name}: resolved translation becomes the aria-label`, () => {
            const w = mountWith(comp, () => 'Modifier');
            const btn = w.get('button');
            expect(btn.attributes('aria-label')).toBe('Modifier');
            expect(btn.attributes('title')).toBe('Modifier');
            expect(btn.attributes('aria-label')).not.toBe('');
        });

        it(`${name}: falls back to FR "Modifier" when the i18n key is unresolved`, () => {
            const w = mountWith(comp, (k) => k); // returns "button.edit" (key echoed)
            const btn = w.get('button');
            expect(btn.attributes('aria-label')).toBe('Modifier');
            // never the raw key, never empty
            expect(btn.attributes('aria-label')).not.toBe('button.edit');
        });
    }
});
