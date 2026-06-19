/**
 * Sentinel — [LOCK XSS 2026-06-19] POS wizard stored-XSS escape.
 *
 * Locks the escapeHtml() heal added to public/js/pos-wizard.js (frozen-zone,
 * owner-countersigned per plans/LOCK_POS_WIZARD_XSS_ESCAPE_BRANCH_2026-06-19.md).
 *
 * Threat: item/extra/variation/option/sauce/viande/pain/boisson `.name` (and step
 * label/subtitle, instruction text, image thumbs) come from the items API — set via
 * the `items_edit` permission — and were concatenated RAW into the `h`/`html`/`newHtml`
 * string assigned to `wizardEl.innerHTML`. A name like
 *   `<img src=x onerror="window.__xss=1">`
 * therefore executed in the POS operator's authenticated session (stored XSS).
 *
 * The wizard is a ~6000-line vanilla IIFE that wires itself to XHR + a POS modal DOM
 * and cannot be instantiated headlessly, so — consistent with the project's other
 * pos-wizard sentinels (posWizardComposerAware.spec.js, kioskWizardStepRegistry.spec.js)
 * — this test proves the fix two ways:
 *   1. BEHAVIOURAL: extract the real escapeHtml() body from source and run it, asserting
 *      a malicious name renders as inert text (`&lt;img ...`), with NO live `<img>` node
 *      and `window.__xss` left undefined when the escaped string is parsed by the DOM.
 *   2. WIRING: source-assert that every user/API-controlled interpolation cited in the
 *      LOCK now flows through escapeHtml(), and that the print/ticket text path
 *      (buildTicketInstruction → textarea.value) is deliberately NOT escaped.
 */
import { describe, it, expect } from 'vitest';
import { readFileSync } from 'fs';
import { resolve } from 'path';

const SOURCE = readFileSync(
    resolve(process.cwd(), 'public/js/pos-wizard.js'),
    'utf8',
);

// Extract the real escapeHtml() implementation from source and make it callable.
function loadEscapeHtml() {
    const m = SOURCE.match(/function escapeHtml\(s\)\s*\{[\s\S]*?\n {4}\}/);
    if (!m) throw new Error('escapeHtml() function not found in pos-wizard.js');
    // eslint-disable-next-line no-new-func
    return new Function(`${m[0]}; return escapeHtml;`)();
}

describe('pos-wizard escapeHtml() — behavioural (LOCK XSS 2026-06-19)', () => {
    const escapeHtml = loadEscapeHtml();

    it('escapes a stored-XSS img payload to inert text (no live node, no execution)', () => {
        const payload = '<img src=x onerror="window.__xss=1">';
        const escaped = escapeHtml(payload);

        // The dangerous metacharacters are neutralised.
        expect(escaped).toBe('&lt;img src=x onerror=&quot;window.__xss=1&quot;&gt;');
        expect(escaped).toContain('&lt;img');
        expect(escaped).not.toContain('<img');

        // When the wizard assigns the built HTML to innerHTML, the escaped text is
        // parsed as a text node — prove no live <img> element and no script ran.
        delete window.__xss;
        const host = document.createElement('div');
        host.innerHTML = '<span class="option-name">' + escaped + '</span>';
        expect(host.querySelector('img')).toBeNull();
        // Force any onerror to fire if an <img> had been created (it wasn't).
        expect(window.__xss).toBeUndefined();
        // The operator still sees the literal name text.
        expect(host.textContent).toBe(payload);
    });

    it('escapes &, <, >, ", \' and passes through null/undefined safely', () => {
        expect(escapeHtml('Tom & Jerry')).toBe('Tom &amp; Jerry');
        expect(escapeHtml('a<b>c')).toBe('a&lt;b&gt;c');
        expect(escapeHtml('say "hi"')).toBe('say &quot;hi&quot;');
        expect(escapeHtml("it's")).toBe('it&#39;s');
        expect(escapeHtml(null)).toBe('');
        expect(escapeHtml(undefined)).toBe('');
        // Order matters: & must be escaped first so it does not double-encode entities.
        expect(escapeHtml('<')).toBe('&lt;');
        expect(escapeHtml('&lt;')).toBe('&amp;lt;');
    });

    it('prevents attribute breakout in a src="..." sink (quote is encoded)', () => {
        const evilUrl = 'x" onerror="window.__xss=1';
        const escaped = escapeHtml(evilUrl);
        expect(escaped).not.toContain('"');
        expect(escaped).toContain('&quot;');
        delete window.__xss;
        const host = document.createElement('div');
        host.innerHTML = '<img src="' + escaped + '" alt="" />';
        const img = host.querySelector('img');
        // The src is a single (broken) URL; no onerror attribute was injected.
        expect(img.hasAttribute('onerror')).toBe(false);
        expect(window.__xss).toBeUndefined();
    });
});

describe('pos-wizard escapeHtml() — sink wiring (LOCK XSS 2026-06-19)', () => {
    it('defines the escapeHtml helper next to fmtPrice', () => {
        expect(SOURCE).toMatch(/function escapeHtml\(s\)\s*\{/);
        expect(SOURCE).toMatch(/\.replace\(\/&\/g, '&amp;'\)\.replace\(\/<\/g, '&lt;'\)/);
    });

    it('escapes the item header name + thumb (renderSinglePage + legacy header)', () => {
        expect(SOURCE).toMatch(/<h2>' \+ escapeHtml\(lastItemData\.name\)/);
        expect(SOURCE).toMatch(/<img src="' \+ escapeHtml\(lastItemData\.thumb\)/);
        expect(SOURCE).toMatch(/wizard-ticket-title">' \+ \(\(lastItemData && lastItemData\.name\) \? escapeHtml\(lastItemData\.name\)/);
    });

    it('escapes every option-name / viande-name span (no raw .name into those spans)', () => {
        // No `option-name`/`viande-name` span may interpolate a bare `.name`.
        expect(SOURCE).not.toMatch(/option-name">' \+ [a-zA-Z_][\w.]*\.name\b(?!\))/);
        expect(SOURCE).not.toMatch(/viande-name">' \+ [a-zA-Z_][\w.]*\.name\b(?!\))/);
        // Spot-check representative escaped sinks.
        expect(SOURCE).toMatch(/option-name">' \+ escapeHtml\(sauce\.name\)/);
        expect(SOURCE).toMatch(/option-name">' \+ escapeHtml\(item\.name\)/);
        expect(SOURCE).toMatch(/viande-name">' \+ escapeHtml\(variation\.name\)/);
    });

    it('escapes thumb + emoji inside the centralized renderOptionIcon helper', () => {
        expect(SOURCE).toMatch(/<img src="' \+ escapeHtml\(thumb\) \+ '" alt="" class="option-img/);
        expect(SOURCE).toMatch(/force-emoji">' \+ escapeHtml\(emoji \|\| ''\)/);
    });

    it('escapes step label/subtitle, instruction textarea, and the ticket preview', () => {
        expect(SOURCE).toMatch(/<h3>' \+ escapeHtml\(step\.label\)/);
        expect(SOURCE).toMatch(/<p>' \+ escapeHtml\(step\.subtitle \|\| ''\)/);
        expect(SOURCE).toMatch(/<textarea class="wizard-comment-field"[\s\S]*?>' \+ escapeHtml\(instructionText \|\| ''\)/);
        expect(SOURCE).toMatch(/ticket-content">' \+ escapeHtml\(ticket \|\| 'Aucune sélection'\)/);
    });

    it('escapes the data-name attribute and its innerHTML re-render round-trip', () => {
        expect(SOURCE).toMatch(/data-name="' \+ escapeHtml\(g\.name\)/);
        expect(SOURCE).toMatch(/btn\.innerHTML = emoji \+ ' ✓ ' \+ escapeHtml\(name\)/);
        expect(SOURCE).toMatch(/btn\.innerHTML = emoji \+ ' ✕ Sans ' \+ escapeHtml\(name\)/);
        expect(SOURCE).toMatch(/'✓ ' \+ escapeHtml\(displayName\)/);
    });

    it('escapes the recap-row array joins (names rendered as HTML)', () => {
        expect(SOURCE).toMatch(/escapeHtml\(viandeNames\.join\(', '\)\)/);
        expect(SOURCE).toMatch(/escapeHtml\(sauceNames\.join\(', '\)\)/);
        expect(SOURCE).toMatch(/escapeHtml\(suppNames\.join\(', '\)\)/);
        expect(SOURCE).toMatch(/escapeHtml\(sfNames\.join\(', '\)\)/);
    });

    it('does NOT escape the print/ticket text path (textarea.value stays literal)', () => {
        // buildTicketInstruction() builds plain text consumed by textarea.value (line ~4082)
        // and printed receipts; escaping it would corrupt the receipt. Assert the text
        // builders push raw names (no escapeHtml inside the push() calls).
        expect(SOURCE).not.toMatch(/\.push\([^)]*escapeHtml/);
        // And the native textarea value setter receives the raw instruction.
        expect(SOURCE).toMatch(/nativeTextSetter\.call\(textarea, fullInstruction\)/);
    });
});
