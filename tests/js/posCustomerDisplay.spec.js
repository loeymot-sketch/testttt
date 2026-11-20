/**
 * [AFFICHEUR-CLIENT 2026-09-30] L'afficheur SAGA (2×20, CD5220) doit montrer le TOTAL du
 * panier à chaque ajout. Le serveur OVH (Linux) ne peut pas atteindre l'USB du PC caisse :
 * c'est le Chrome de la caisse qui écrit sur le port série (Web Serial).
 *
 * Ces tests figent (1) les octets — identiques au rendu serveur déjà validé
 * (CustomerDisplayServiceTest.php) — et (2) le gestionnaire de port : reconnexion sans
 * geste, écritures sérialisées, jamais d'exception vers la caisse.
 */
import { describe, it, expect, beforeEach } from 'vitest';
import {
    fitLeft,
    fitRight,
    formatDisplayMoney,
    encodeCp858,
    totalFrame,
    welcomeFrame,
    createCustomerDisplay,
} from '../../resources/js/helpers/posCustomerDisplay.js';

const ascii = (bytes) => String.fromCharCode(...bytes);

describe('octets de l’afficheur client (CD5220, CP858)', () => {
    it('cadre les lignes sur 20 colonnes', () => {
        expect(fitLeft('TOTAL')).toBe('TOTAL               ');
        expect(fitRight('24,20 EUR')).toBe('           24,20 EUR');
        expect(fitLeft('CECI EST UN MESSAGE TROP LONG')).toBe('CECI EST UN MESSAGE ');
        expect(fitLeft('a\x1Bb\x0Dc')).toBe('abc'.padEnd(20));
    });

    it('formate le montant comme le serveur (virgule, espace des milliers, EUR)', () => {
        expect(formatDisplayMoney(24.2)).toBe('24,20 EUR');
        expect(formatDisplayMoney(1234.5)).toBe('1 234,50 EUR');
        expect(formatDisplayMoney(0.005)).toBe('0,01 EUR');
        expect(formatDisplayMoney('abc')).toBe('0,00 EUR');
    });

    it('le cadre TOTAL = init, page CP858, effacement, puis 40 colonnes : libellé en haut, total aligné à droite en bas', () => {
        // Aucune commande propre à un seul jeu (pas de ESC Q A du CD5220, pas de US $ d'EPSON) :
        // en mode « écrasement » par défaut, la 21ᵉ colonne passe à la ligne du bas dans les DEUX
        // émulations que la SAGA PD220 peut avoir. ESC t mal compris est effacé par le CLR qui suit.
        const s = ascii(totalFrame(24.2));
        expect(s).toBe('\x1B\x40\x1B\x74\x13\x0C' + 'TOTAL'.padEnd(20) + '           24,20 EUR');
    });

    it('le cadre d’accueil affiche le nom du restaurant et le message', () => {
        const s = ascii(welcomeFrame('Le Cayenne', 'Soyez le bienvenu !'));
        expect(s.endsWith('Le Cayenne'.padEnd(20) + 'Soyez le bienvenu !'.padEnd(20))).toBe(true);
    });

    it('transcode les accents français et l’euro en CP858, jamais en UTF-8 multi-octets', () => {
        expect(Array.from(encodeCp858('é'))).toEqual([0x82]);
        expect(Array.from(encodeCp858('à'))).toEqual([0x85]);
        expect(Array.from(encodeCp858('ç'))).toEqual([0x87]);
        expect(Array.from(encodeCp858('€'))).toEqual([0xd5]);
        expect(Array.from(encodeCp858('漢'))).toEqual([0x3f]);
        const accueil = welcomeFrame('Crêperie', 'Été');
        expect(accueil.every((x) => x <= 0xff)).toBe(true);
        expect(accueil.length).toBe(6 + 40);
    });
});

/** Faux port Web Serial : enregistre ce qui est écrit. */
function fakePort(info = { usbVendorId: 0x067b, usbProductId: 0x2303 }) {
    const port = {
        opened: null,
        written: [],
        closed: false,
        getInfo: () => info,
        async open(opts) { port.opened = opts; port.writable = makeWritable(port); },
        async close() { port.closed = true; },
        writable: null,
    };
    return port;
}
function makeWritable(port) {
    return {
        getWriter() {
            return {
                async write(chunk) { port.written.push(Array.from(chunk)); },
                releaseLock() {},
            };
        },
    };
}
function fakeSerial(granted = [], picked = null) {
    const listeners = {};
    return {
        async getPorts() { return granted; },
        async requestPort() { if (!picked) { const e = new Error('No port selected'); e.name = 'NotFoundError'; throw e; } return picked; },
        addEventListener(type, fn) { (listeners[type] ||= []).push(fn); },
        emit(type, ev) { (listeners[type] || []).forEach((fn) => fn(ev)); },
    };
}
function memoryStorage() {
    const m = {};
    return { getItem: (k) => (k in m ? m[k] : null), setItem: (k, v) => { m[k] = String(v); } };
}

describe('gestionnaire du port série de l’afficheur', () => {
    let storage;
    beforeEach(() => { storage = memoryStorage(); });

    it('signale « non supporté » sans Web Serial et n’échoue jamais', async () => {
        const d = createCustomerDisplay({ serial: undefined, storage });
        expect(d.isSupported()).toBe(false);
        expect(await d.autoConnect()).toBe(false);
        expect(await d.showTotal(12)).toBe(false);
        expect(d.getState().status).toBe('unsupported');
    });

    it('ne se reconnecte pas tout seul tant que la caisse n’a jamais été appairée', async () => {
        const d = createCustomerDisplay({ serial: fakeSerial([]), storage });
        expect(await d.autoConnect()).toBe(false);
        expect(d.getState().status).toBe('disconnected');
    });

    it('appaire au clic, ouvre en 9600 8N1, mémorise le port et envoie l’accueil', async () => {
        const port = fakePort();
        const d = createCustomerDisplay({ serial: fakeSerial([], port), storage, welcome: ['Le Cayenne', 'Soyez le bienvenu !'] });
        expect(await d.connect()).toBe(true);
        expect(port.opened).toMatchObject({ baudRate: 9600, dataBits: 8, stopBits: 1, parity: 'none' });
        expect(d.getState().status).toBe('connected');
        expect(ascii(port.written[0])).toContain('Le Cayenne');
        expect(JSON.parse(storage.getItem('pos_customer_display_port'))).toEqual({ usbVendorId: 0x067b, usbProductId: 0x2303 });
    });

    it('au rechargement, retrouve seul le port déjà autorisé (aucun geste) et réaffiche le total', async () => {
        const autre = fakePort({ usbVendorId: 0x1a86, usbProductId: 0x7523 });
        const saga = fakePort();
        storage.setItem('pos_customer_display_port', JSON.stringify({ usbVendorId: 0x067b, usbProductId: 0x2303 }));
        const d = createCustomerDisplay({ serial: fakeSerial([autre, saga]), storage });
        expect(await d.autoConnect()).toBe(true);
        expect(autre.opened).toBe(null);
        await d.showTotal(18.5);
        expect(ascii(saga.written[saga.written.length - 1])).toContain('           18,50 EUR');
    });

    it('chaque ajout remplace l’écran par le NOUVEAU total, dans l’ordre', async () => {
        const port = fakePort();
        const d = createCustomerDisplay({ serial: fakeSerial([], port), storage });
        await d.connect();
        await Promise.all([d.showTotal(8), d.showTotal(16.5), d.showTotal(24.2)]);
        const derniers = port.written.slice(-3).map(ascii);
        expect(derniers[0]).toContain('8,00 EUR');
        expect(derniers[1]).toContain('16,50 EUR');
        expect(derniers[2]).toContain('24,20 EUR');
    });

    it('un refus d’appairage ou un port occupé devient un état lisible, jamais une exception', async () => {
        const d1 = createCustomerDisplay({ serial: fakeSerial([], null), storage });
        expect(await d1.connect()).toBe(false);
        expect(d1.getState().status).toBe('disconnected');

        const occupe = fakePort();
        occupe.open = async () => { const e = new Error('Failed to open serial port.'); e.name = 'NetworkError'; throw e; };
        const d2 = createCustomerDisplay({ serial: fakeSerial([], occupe), storage });
        expect(await d2.connect()).toBe(false);
        expect(d2.getState().status).toBe('error');
        expect(d2.getState().error).toMatch(/occupé|utilisé/i);
    });

    it('débrancher l’afficheur repasse en « déconnecté »', async () => {
        const port = fakePort();
        const serial = fakeSerial([], port);
        const d = createCustomerDisplay({ serial, storage });
        await d.connect();
        serial.emit('disconnect', { target: port });
        expect(d.getState().status).toBe('disconnected');
        expect(await d.showTotal(5)).toBe(false);
    });
});
