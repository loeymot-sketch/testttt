import { describe, expect, it, vi, afterEach } from 'vitest';

import { createBarcodeDetector, createFKeyShortcuts } from '../../resources/js/helpers/posBarcode';

describe('posBarcode', () => {
    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('fires callback on rapid keystrokes (>=6 chars) followed by Enter', () => {
        const onBarcode = vi.fn();
        const stop = createBarcodeDetector(onBarcode);
        try {
            const t = { value: 0 };
            vi.spyOn(performance, 'now').mockImplementation(() => {
                t.value += 10;
                return t.value;
            });
            for (const ch of '123456') {
                window.dispatchEvent(new KeyboardEvent('keydown', { key: ch, bubbles: true }));
            }
            window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
            expect(onBarcode).toHaveBeenCalledWith('123456');
        } finally {
            stop();
        }
    });

    it('does not fire when gaps between keys exceed the scanner threshold', () => {
        const onBarcode = vi.fn();
        const stop = createBarcodeDetector(onBarcode);
        try {
            let t = 0;
            vi.spyOn(performance, 'now').mockImplementation(() => {
                t += 60;
                return t;
            });
            for (const ch of '123456') {
                window.dispatchEvent(new KeyboardEvent('keydown', { key: ch, bubbles: true }));
            }
            window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
            expect(onBarcode).not.toHaveBeenCalled();
        } finally {
            stop();
        }
    });

    it('ignores Enter when fewer than 6 characters were scanned', () => {
        const onBarcode = vi.fn();
        const stop = createBarcodeDetector(onBarcode);
        try {
            const t = { value: 0 };
            vi.spyOn(performance, 'now').mockImplementation(() => {
                t.value += 10;
                return t.value;
            });
            for (const ch of '12345') {
                window.dispatchEvent(new KeyboardEvent('keydown', { key: ch, bubbles: true }));
            }
            window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
            expect(onBarcode).not.toHaveBeenCalled();
        } finally {
            stop();
        }
    });

    it('maps F1 to shortcut index 1 when target is not a text field', () => {
        const onShortcut = vi.fn();
        const stop = createFKeyShortcuts(onShortcut);
        try {
            window.dispatchEvent(new KeyboardEvent('keydown', { key: 'F1', bubbles: true }));
            expect(onShortcut).toHaveBeenCalledWith(1);
        } finally {
            stop();
        }
    });

    it('does not intercept F1 when event originates from a focused input', () => {
        const onShortcut = vi.fn();
        const stop = createFKeyShortcuts(onShortcut);
        const input = document.createElement('input');
        document.body.appendChild(input);
        try {
            input.focus();
            input.dispatchEvent(new KeyboardEvent('keydown', { key: 'F1', bubbles: true }));
            expect(onShortcut).not.toHaveBeenCalled();
        } finally {
            stop();
            input.remove();
        }
    });

    it('ignores F13 and higher', () => {
        const onShortcut = vi.fn();
        const stop = createFKeyShortcuts(onShortcut);
        try {
            window.dispatchEvent(new KeyboardEvent('keydown', { key: 'F13', bubbles: true }));
            expect(onShortcut).not.toHaveBeenCalled();
        } finally {
            stop();
        }
    });

    /**
     * [V14 C-α / FINDING C-5 P2 sentinel]
     * The shouldIntercept option lets the caller (PosComponent) disable F-keys
     * when a drawer/modal (e.g. parked orders) is open. Otherwise F1-F12 would
     * still switch categories in the background, confusing the operator.
     */
    it('respects shouldIntercept=false to disable F-keys (drawer guard)', () => {
        const onShortcut = vi.fn();
        let drawerOpen = true;
        const stop = createFKeyShortcuts(onShortcut, {
            shouldIntercept: () => !drawerOpen,
        });
        try {
            // Drawer is open → F1 must NOT trigger
            window.dispatchEvent(new KeyboardEvent('keydown', { key: 'F1', bubbles: true }));
            expect(onShortcut).not.toHaveBeenCalled();

            // Drawer closes → F1 triggers normally again
            drawerOpen = false;
            window.dispatchEvent(new KeyboardEvent('keydown', { key: 'F1', bubbles: true }));
            expect(onShortcut).toHaveBeenCalledWith(1);
        } finally {
            stop();
        }
    });

    /**
     * [V14 C-α / FINDING C-5 P2 sentinel]
     * Backward compat: createFKeyShortcuts without options must keep its
     * previous behavior (always intercept).
     */
    it('keeps backward-compat behavior when shouldIntercept option is absent', () => {
        const onShortcut = vi.fn();
        const stop = createFKeyShortcuts(onShortcut);
        try {
            window.dispatchEvent(new KeyboardEvent('keydown', { key: 'F2', bubbles: true }));
            expect(onShortcut).toHaveBeenCalledWith(2);
        } finally {
            stop();
        }
    });
});
