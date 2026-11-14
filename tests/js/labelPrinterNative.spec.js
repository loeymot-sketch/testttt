/**
 * [ETIQUETTES 2026-10-16] L'adaptateur du pont Android : la page parle à `createLabelPrinter` comme à Web Serial, les
 * octets passent par une fausse imprimante Brother branchée sur le pont (le MÊME encodeur et le MÊME décodeur d'état).
 */
import { describe, it, expect } from 'vitest';
import { createNativeSerial, nativeBridge, SPP_UUID } from '../../resources/js/helpers/labelPrinterNative.js';
import { createLabelPrinter } from '../../resources/js/helpers/labelPrinter.js';
import { STATUS_REQUEST } from '../../resources/js/helpers/brotherQl.js';

const b64 = (u8) => Buffer.from(u8).toString('base64');
const unb64 = (s) => new Uint8Array(Buffer.from(s, 'base64'));

/** Pont simulé : ce que ferait PrinterBridge.java, avec une Brother qui répond « prête, rouleau 62 mm ». */
function fakeBridge({ devices = [{ kind: 'bt', address: '80:6F:B0:CC:7A:4D', name: 'QL-820NWB8204' }], failOpen = false, saved = null } = {}) {
    const win = {};
    const log = { opened: [], written: [], closed: 0 };
    const send = (ev) => setTimeout(() => win.__labelNativeEvent(JSON.stringify(ev)), 0);
    const statusReply = () => {
        const f = new Uint8Array(32);
        f[0] = 0x80; f[1] = 0x20; f[2] = 0x42; f[3] = 0x34; f[4] = 0x38; f[10] = 0x30; f[11] = 0x0b;
        f[18] = 0x00; f[19] = 0x0a; f[24] = 0x00; // réponse d'état « prête » (62 mm continu)
        return f;
    };
    const bridge = {
        saved: () => (saved ? JSON.stringify(saved) : 'null'),
        chooseDevice: (callId) => send(devices.length ? { type: 'result', callId, ok: true, device: devices[0] } : { type: 'result', callId, ok: false, name: 'NotFoundError', error: 'annulé' }),
        open: (callId, kind, target, port) => {
            log.opened.push([kind, target, port]);
            send(failOpen ? { type: 'result', callId, ok: false, name: 'NetworkError', error: 'Imprimante injoignable' } : { type: 'result', callId, ok: true });
        },
        write: (callId, data) => {
            const bytes = unb64(data);
            log.written.push(bytes);
            send({ type: 'result', callId, ok: true });
            if (bytes.length === STATUS_REQUEST.length && bytes.every((v, i) => v === STATUS_REQUEST[i])) send({ type: 'data', b64: b64(statusReply()) });
        },
        close: () => { log.closed += 1; },
    };
    return { bridge, win, log, drop: () => send({ type: 'closed', reason: 'éteinte' }) };
}

describe('nativeBridge', () => {
    it('reconnaît le pont de l’application, et seulement lui', () => {
        expect(nativeBridge({})).toBe(null);
        expect(nativeBridge({ LabelNative: { open() {}, chooseDevice() {} } })).not.toBe(null);
    });
});

describe('createNativeSerial', () => {
    it('requestPort → open : Bluetooth RFCOMM (SPP), puis la Brother répond à la demande d’état', async () => {
        const { bridge, win, log } = fakeBridge();
        const serial = createNativeSerial(bridge, win);
        const printer = createLabelPrinter({ serial });
        expect(printer.isSupported()).toBe(true);
        expect(await printer.connect()).toBe(true);
        expect(log.opened).toEqual([['bt', '80:6F:B0:CC:7A:4D', 9100]]);
        await new Promise((r) => setTimeout(r, 30));
        expect(printer.getState().status).toBe('connected');
        expect(log.written.some((w) => w.length === STATUS_REQUEST.length)).toBe(true); // demande d'état envoyée à l'imprimante
        expect(printer.getState().printer).toBeTruthy(); // et sa réponse décodée par le MÊME parseStatus
    });

    it('Wi-Fi : adresse IP + port 9100 par TCP', async () => {
        const { bridge, win, log } = fakeBridge({ devices: [{ kind: 'tcp', host: '192.168.1.50', port: 9100, name: 'Wi-Fi' }] });
        const printer = createLabelPrinter({ serial: createNativeSerial(bridge, win) });
        expect(await printer.connect()).toBe(true);
        expect(log.opened).toEqual([['tcp', '192.168.1.50', 9100]]);
    });

    it('fenêtre fermée sans choisir : une annulation, jamais le message « Chrome »', async () => {
        const { bridge, win } = fakeBridge({ devices: [] });
        const printer = createLabelPrinter({ serial: createNativeSerial(bridge, win) });
        expect(await printer.connect()).toBe(false);
        expect(printer.getState().noDevice).toBe(false);
        expect(printer.getState().error).toBe(null);
    });

    it('imprimante injoignable : message clair, rien de planté', async () => {
        const { bridge, win } = fakeBridge({ failOpen: true });
        const printer = createLabelPrinter({ serial: createNativeSerial(bridge, win) });
        expect(await printer.connect()).toBe(false);
        expect(printer.getState().status).toBe('disconnected');
        expect(printer.getState().error).toMatch(/injoignable/);
    });

    it('reconnexion SANS geste : le dernier appareil mémorisé par l’application', async () => {
        const saved = { kind: 'bt', address: 'AA:BB:CC:DD:EE:FF', name: 'QL-820NWB8204' };
        const { bridge, win, log } = fakeBridge({ saved });
        const printer = createLabelPrinter({ serial: createNativeSerial(bridge, win) });
        expect(await printer.autoConnect()).toBe(true);
        expect(log.opened[0]).toEqual(['bt', 'AA:BB:CC:DD:EE:FF', 9100]);
    });

    it('liaison tombée (imprimante éteinte) : détectée, « déconnectée »', async () => {
        const { bridge, win, drop } = fakeBridge();
        const printer = createLabelPrinter({ serial: createNativeSerial(bridge, win) });
        await printer.connect();
        drop();
        await new Promise((r) => setTimeout(r, 20));
        expect(printer.getState().status).toBe('disconnected');
    });

    it('liaison Wi-Fi muette (imprimante éteinte sans fermer la prise) : coupée après 3 états manquants, puis reconnexion', async () => {
        const { bridge, win, log } = fakeBridge({ devices: [{ kind: 'tcp', host: '192.168.1.50', port: 9100 }] });
        const realWrite = bridge.write;
        let mute = false;
        bridge.write = (callId, data) => { if (mute) { win.__labelNativeEvent(JSON.stringify({ type: 'result', callId, ok: true })); return; } realWrite(callId, data); };
        const printer = createLabelPrinter({ serial: createNativeSerial(bridge, win), statusTimeoutMs: 15 });
        await printer.connect();
        await new Promise((r) => setTimeout(r, 40));
        expect(printer.getState().status).toBe('connected');
        mute = true; // l'imprimante ne répond plus, la prise reste ouverte
        printer.startKeepAlive(30);
        await new Promise((r) => setTimeout(r, 300));
        printer.stopKeepAlive();
        expect(printer.getState().status).toBe('disconnected');
        expect(printer.getState().error).toMatch(/silencieuse/);
        expect(log.closed).toBeGreaterThan(0);
    });

    it('les messages précis du pont (Bluetooth éteint, autorisation) ne sont pas remplacés par le texte générique', async () => {
        const { bridge, win } = fakeBridge();
        bridge.open = (callId) => setTimeout(() => win.__labelNativeEvent(JSON.stringify({ type: 'result', callId, ok: false, name: 'NetworkError', error: 'Imprimante injoignable (Bluetooth éteint)' })), 0);
        const printer = createLabelPrinter({ serial: createNativeSerial(bridge, win) });
        await printer.connect();
        expect(printer.getState().error).toBe('Imprimante injoignable (Bluetooth éteint)');
    });

    it('le profil annoncé est le profil série (SPP) pour le Bluetooth, rien pour le Wi-Fi', async () => {
        const { bridge, win } = fakeBridge();
        const serial = createNativeSerial(bridge, win);
        const bt = await serial.requestPort();
        expect(bt.getInfo().bluetoothServiceClassId).toBe(SPP_UUID);
    });
});
