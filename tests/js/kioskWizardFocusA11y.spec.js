import { describe, it, expect } from 'vitest';
import {
    focusFirstInteractive,
    captureScrollPosition,
    restoreScrollPosition,
    announceStep,
} from '../../resources/js/helpers/kioskWizardFocusA11y';

function makeRoot(html) {
    const root = document.createElement('div');
    root.innerHTML = html;
    document.body.appendChild(root);
    return root;
}

describe('kioskWizardFocusA11y.focusFirstInteractive', () => {
    it('focuses the first enabled button and returns true', () => {
        const root = makeRoot(`
            <button disabled data-testid="a">A</button>
            <button data-testid="b">B</button>
            <button data-testid="c">C</button>
        `);
        const res = focusFirstInteractive(root);
        expect(res).toBe(true);
        expect(document.activeElement.getAttribute('data-testid')).toBe('b');
    });

    it('falls back to the provided fallback element when no focusable is found', () => {
        const root = makeRoot('<span>no focusable here</span>');
        const fallback = document.createElement('h2');
        fallback.setAttribute('tabindex', '-1');
        document.body.appendChild(fallback);

        const res = focusFirstInteractive(root, { fallback });
        expect(res).toBe(true);
        expect(document.activeElement).toBe(fallback);
    });

    it('returns false when root is null or has no focusable and no fallback', () => {
        expect(focusFirstInteractive(null)).toBe(false);
        const root = makeRoot('<p>text only</p>');
        expect(focusFirstInteractive(root)).toBe(false);
    });

    it('respects role=radio/checkbox but skips aria-disabled', () => {
        const root = makeRoot(`
            <div role="radio" aria-disabled="true" tabindex="0" data-testid="rd1">off</div>
            <div role="radio" tabindex="0" data-testid="rd2">on</div>
        `);
        focusFirstInteractive(root);
        expect(document.activeElement.getAttribute('data-testid')).toBe('rd2');
    });
});

describe('kioskWizardFocusA11y.captureScrollPosition / restoreScrollPosition', () => {
    it('captures the current scrollTop and restores it later', () => {
        const root = { scrollTop: 120 };
        const offset = captureScrollPosition(root);
        expect(offset).toBe(120);

        root.scrollTop = 0;
        restoreScrollPosition(root, offset);
        expect(root.scrollTop).toBe(120);
    });

    it('returns 0 for nullish / unreadable roots', () => {
        expect(captureScrollPosition(null)).toBe(0);
        expect(captureScrollPosition({ scrollTop: NaN })).toBe(0);
    });

    it('coerces non-finite offsets to 0 when restoring', () => {
        const root = { scrollTop: 7 };
        restoreScrollPosition(root, 'xyz');
        expect(root.scrollTop).toBe(0);
    });
});

describe('kioskWizardFocusA11y.announceStep', () => {
    it('writes the message and enforces aria-live=polite', () => {
        const announcer = document.createElement('div');
        document.body.appendChild(announcer);
        const ok = announceStep(announcer, 'Choose your bread');
        expect(ok).toBe(true);
        expect(announcer.getAttribute('aria-live')).toBe('polite');
        expect(announcer.textContent).toBe('Choose your bread');
    });

    it('clears the announcer when message is empty', () => {
        const announcer = document.createElement('div');
        announcer.textContent = 'previous';
        announceStep(announcer, '');
        expect(announcer.textContent).toBe('');
    });

    it('returns false when the announcer is missing', () => {
        expect(announceStep(null, 'x')).toBe(false);
    });
});
