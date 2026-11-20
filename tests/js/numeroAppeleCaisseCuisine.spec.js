import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';

// [E2E stores · revue adverse round 2 · 2026-10-01] Un seul numéro pour une commande, partout.
// L'écran client et le comptoir appellent « N°A0055 ». La cuisine (bandeau des commandes programmées)
// citait la SÉRIE « #0110267568 » (B2-R2-08) et la caisse annonçait « Nouvelle commande #7567 », l'id
// en BASE, un troisième numéro que rien d'autre n'affiche (B2-R2-09). Ce test appelle le VRAI code.

vi.mock('axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }));
vi.mock('../../resources/js/services/appService', () => ({ default: { requestHandler: vi.fn(() => '') } }));

import KdsScheduledBanner from '../../resources/js/components/admin/kitchenDisplaySystem/KdsScheduledBanner.vue';

describe('Bandeau cuisine des commandes programmées', () => {
    it('affiche le numéro appelé « N°A0055 » quand il existe', () => {
        const w = mount(KdsScheduledBanner, { props: { entries: [{ id: 7568, order_serial_no: '0110267568', queue_number: 'A0055', scheduled_at: '2026-10-01 18:20:00', order_type: 10 }] } });
        expect(w.text()).toContain('N°A0055');
        expect(w.text()).not.toContain('#0110267568');
    });

    it('retombe sur la série sans numéro de file', () => {
        const w = mount(KdsScheduledBanner, { props: { entries: [{ id: 7568, order_serial_no: '0110267568', scheduled_at: '2026-10-01 18:20:00', order_type: 10 }] } });
        expect(w.text()).toContain('#0110267568');
    });
});

describe('Toast « nouvelle commande » de la caisse', () => {
    it('cite le numéro appelé, sinon la série — jamais l\'id en base', async () => {
        // Méthode réelle du composant, extraite du fichier source (le composant entier est trop lourd
        // à monter ici : on évalue SA définition, pas une réimplémentation).
        const fs = await import('fs');
        const path = await import('path');
        const src = fs.readFileSync(path.resolve(process.cwd(), 'resources/js/components/admin/pos/PosComponent.vue'), 'utf8');
        const m = src.match(/_libelleCommande\(o, id\) \{([\s\S]*?)\n        \},/);
        expect(m, '_libelleCommande doit exister dans PosComponent.vue').toBeTruthy();
        const libelle = new Function('o', 'id', m[1]);
        expect(libelle({ id: 7567, order_serial_no: '0110267567', queue_number: 'A0054' }, 7567)).toBe('N°A0054');
        expect(libelle({ id: 7567, order_serial_no: '0110267567' }, 7567)).toBe('#0110267567');
        expect(libelle({ id: 7567 }, 7567)).toBe('#7567');
        // Le sondage transmet bien CE libellé au toast, et le gabarit ne force plus « # ».
        expect(src).toMatch(/fresh \+= 1; lastId = libelles\[id\] \|\| id;/);
        const fr = JSON.parse(fs.readFileSync(path.resolve(process.cwd(), 'resources/js/languages/fr.json'), 'utf8'));
        const trouve = JSON.stringify(fr).match(/"new_pos_order_with_id":"([^"]*)"/);
        expect(trouve && trouve[1]).toBe('Nouvelle commande {id}');
    });
});
