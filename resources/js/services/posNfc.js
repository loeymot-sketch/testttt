export const isSupported = () =>
    typeof window !== 'undefined' && 'NDEFReader' in window;

/**
 * Read one NFC tag UID (Web NFC). Handlers must be attached before `scan()`.
 */
export async function scanOnce({ timeoutMs = 30000 } = {}) {
    if (!isSupported()) {
        throw new Error('nfc_unsupported');
    }
    const reader = new window.NDEFReader();
    const ctrl = new AbortController();
    const t = setTimeout(() => ctrl.abort(), timeoutMs);

    try {
        return await new Promise((resolve, reject) => {
            reader.onreading = (event) => {
                resolve(
                    String(event.serialNumber || '')
                        .replace(/:/g, '')
                        .toUpperCase()
                );
            };
            reader.onreadingerror = () => reject(new Error('nfc_read_error'));
            ctrl.signal.addEventListener(
                'abort',
                () => reject(new Error('nfc_timeout')),
                { once: true }
            );
            reader.scan({ signal: ctrl.signal }).catch(reject);
        });
    } finally {
        clearTimeout(t);
    }
}
