import { afterEach, describe, expect, it, vi } from 'vitest';
import { announce, trapFocus } from '../../resources/js/helpers/posA11y.js';

describe('posA11y helpers', () => {
    afterEach(() => {
        document.body.innerHTML = '';
        vi.useRealTimers();
    });

    it('trapFocus traps Tab on last element back to first', () => {
        const root = document.createElement('div');
        const first = document.createElement('button');
        const last = document.createElement('button');
        root.appendChild(first);
        root.appendChild(last);
        document.body.appendChild(root);

        const cleanup = trapFocus(root);
        last.focus();
        expect(document.activeElement).toBe(last);

        const ev = new KeyboardEvent('keydown', { key: 'Tab', bubbles: true });
        Object.defineProperty(ev, 'shiftKey', { value: false });
        last.dispatchEvent(ev);

        expect(document.activeElement).toBe(first);
        cleanup();
    });

    it('trapFocus traps Shift+Tab on first element back to last', () => {
        const root = document.createElement('div');
        const first = document.createElement('button');
        const last = document.createElement('button');
        root.appendChild(first);
        root.appendChild(last);
        document.body.appendChild(root);

        const cleanup = trapFocus(root);
        first.focus();
        expect(document.activeElement).toBe(first);

        const ev = new KeyboardEvent('keydown', { key: 'Tab', bubbles: true, shiftKey: true });
        first.dispatchEvent(ev);

        expect(document.activeElement).toBe(last);
        cleanup();
    });

    it('trapFocus returns no-op when rootEl is null', () => {
        const cleanup = trapFocus(null);
        expect(typeof cleanup).toBe('function');
        expect(() => cleanup()).not.toThrow();
    });

    it('announce sets textContent on #pos-a11y-live element', () => {
        const live = document.createElement('div');
        live.id = 'pos-a11y-live';
        document.body.appendChild(live);

        vi.useFakeTimers();
        announce('cart ready');
        vi.advanceTimersByTime(50);

        expect(live.textContent).toBe('cart ready');
    });

    it('announce sets aria-live priority correctly', () => {
        const live = document.createElement('div');
        live.id = 'pos-a11y-live';
        document.body.appendChild(live);

        vi.useFakeTimers();
        announce('error', 'assertive');
        vi.advanceTimersByTime(50);

        expect(live.getAttribute('aria-live')).toBe('assertive');
    });
});
