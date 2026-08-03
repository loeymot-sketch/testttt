import { describe, expect, it, vi, afterEach } from 'vitest';

import * as posNfc from '../../resources/js/services/posNfc';

describe('posNfc', () => {
    afterEach(() => {
        vi.restoreAllMocks();
        delete globalThis.NDEFReader;
    });

    it('isSupported returns false when NDEFReader missing', () => {
        expect(posNfc.isSupported()).toBe(false);
    });

    it('scanOnce rejects nfc_unsupported on browsers without NDEFReader', async () => {
        await expect(posNfc.scanOnce({ timeoutMs: 100 })).rejects.toMatchObject({
            message: 'nfc_unsupported',
        });
    });

    it('scanOnce resolves with normalized UID (no colons, uppercase)', async () => {
        globalThis.NDEFReader = class MockNdefReader {
            constructor() {
                this.onreading = null;
                this.onreadingerror = null;
            }

            scan({ signal: _signal }) {
                return new Promise((resolve) => {
                    queueMicrotask(() => {
                        if (this.onreading) {
                            this.onreading({
                                serialNumber: 'aa:bb:cc:dd',
                            });
                        }
                        resolve();
                    });
                });
            }
        };

        await expect(posNfc.scanOnce({ timeoutMs: 5000 })).resolves.toBe('AABBCCDD');
    });
});
