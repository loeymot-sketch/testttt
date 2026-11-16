/**
 * [FACTURE PRO 2026-11-14] Page « Facture pro » de la caisse : aperçu HT/TVA identique au serveur,
 * envoi avec clé d'idempotence, erreurs en français, liste avec réimpression et annulation.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import fs from 'fs';
import path from 'path';

vi.mock('axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }));
vi.mock('../../resources/js/helpers/posLocalPrinter', () => ({
  isCaisseBridgeAvailable: vi.fn(),
  printEscPosViaCaisseBridge: vi.fn(),
}));
import axios from 'axios';
import { isCaisseBridgeAvailable, printEscPosViaCaisseBridge } from '../../resources/js/helpers/posLocalPrinter';
import ProInvoicePage, { computeAmounts } from '../../resources/js/components/admin/pos/ProInvoicePage.vue';
import CaisseSecondaryNav from '../../resources/js/components/admin/pos/CaisseSecondaryNav.vue';

const LIST = {
  data: [
    { id: 2, number: 'FP-2026-0002', service_date: '2026-11-13', client_name: 'Transports Martin SARL', label: '2 repas', quantity: 1, amount_ttc: '18.00', payment_label: 'Titre-restaurant', cancelled: false },
    { id: 1, number: 'FP-2026-0001', service_date: '2026-11-12', client_name: 'Garage Dupont', label: 'repas', quantity: 3, amount_ttc: '30.00', payment_label: 'Espèces', cancelled: true },
  ],
  meta: { total: 2 },
};

const mountPage = () => mount(ProInvoicePage, {
  global: {
    stubs: { BreadcrumbComponent: true, CaisseSecondaryNav: true },
    mocks: { $t: (k) => k },
  },
});

describe('computeAmounts — parité avec ProInvoiceService::computeAmounts', () => {
  it('18,00 € TTC à 10 % → 16,36 HT + 1,64 TVA', () => {
    expect(computeAmounts('18,00', '10')).toEqual({ ttc: 18, ht: 16.36, vat: 1.64 });
  });
  it('100 € à 5,5 % → 94,79 + 5,21 ; 12 € à 20 % → 10 + 2', () => {
    expect(computeAmounts('100', '5.5')).toEqual({ ttc: 100, ht: 94.79, vat: 5.21 });
    expect(computeAmounts('12', '20')).toEqual({ ttc: 12, ht: 10, vat: 2 });
  });
  it('HT + TVA = TTC au centime, pour tous les montants', () => {
    for (const rate of ['5.5', '10', '20']) {
      for (let c = 1; c <= 5000; c += 7) {
        const a = computeAmounts((c / 100).toFixed(2), rate);
        expect(Math.round(a.ht * 100) + Math.round(a.vat * 100)).toBe(c);
      }
    }
  });
  it('montant vide ou nul → zéro, jamais NaN', () => {
    expect(computeAmounts('', '10')).toEqual({ ttc: 0, ht: 0, vat: 0 });
    expect(computeAmounts('abc', '10')).toEqual({ ttc: 0, ht: 0, vat: 0 });
  });
});

describe('ProInvoicePage', () => {
  beforeEach(() => {
    axios.get.mockReset();
    axios.post.mockReset();
    isCaisseBridgeAvailable.mockReset();
    printEscPosViaCaisseBridge.mockReset();
    isCaisseBridgeAvailable.mockResolvedValue(false); // par défaut : poste sans pont d'impression
    document.querySelectorAll('[data-testid="fp-print-frame"]').forEach((n) => n.remove());
    axios.get.mockImplementation((url) => {
      if (url === 'admin/pos/pro-invoices') return Promise.resolve({ data: LIST });
      if (/\/escpos$/.test(url)) return Promise.resolve({ data: { escpos_b64: 'G0A=' } });
      return Promise.resolve({ data: '<!DOCTYPE html><p>Facture</p>' });
    });
  });

  it('affiche l\'aperçu HT/TVA en direct et la liste avec le badge Annulée', async () => {
    const w = mountPage();
    await flushPromises();
    await w.find('[data-testid="fp-amount"]').setValue('18,00');
    expect(w.find('[data-testid="fp-preview-ht"]').text()).toContain('16,36');
    expect(w.find('[data-testid="fp-preview-vat"]').text()).toContain('1,64');
    expect(w.find('[data-testid="fp-preview-ttc"]').text()).toContain('18,00');

    expect(w.find('[data-testid="fp-row-FP-2026-0002"]').text()).toContain('Transports Martin SARL');
    expect(w.find('[data-testid="fp-row-FP-2026-0001"]').text()).toContain('Annulée');
    expect(w.find('[data-testid="fp-cancel-1"]').exists()).toBe(false); // déjà annulée
    expect(w.find('[data-testid="fp-cancel-2"]').exists()).toBe(true);
    expect(w.text()).toContain('hors ticket de caisse');
  });

  it('[2026-11-16] société et désignation sont facultatives ; seul le montant est saisi', async () => {
    axios.post.mockResolvedValue({ data: { data: { id: 4, number: 'FP-2026-0004' } } });
    const w = mountPage();
    await flushPromises();
    expect(w.find('[data-testid="fp-client-name"]').element.closest('label').textContent).toContain('facultatif');
    expect(w.find('[data-testid="fp-label"]').element.closest('label').textContent).toContain('facultatif');
    expect(w.find('[data-testid="fp-client-name"]').element.closest('label').querySelector('em')).toBeNull();
    expect(w.find('[data-testid="fp-label"]').element.closest('label').querySelector('em')).toBeNull();

    await w.find('[data-testid="fp-amount"]').setValue('12,50');
    await w.find('[data-testid="pro-invoice-form"]').trigger('submit');
    await flushPromises();

    expect(axios.post).toHaveBeenCalledTimes(1);
    expect(axios.post.mock.calls[0][1]).toMatchObject({ client_name: '', label: '', amount_ttc: '12,50', payment_method: 'especes' });
  });

  it('[2026-11-16] sans client, la liste affiche un tiret', async () => {
    axios.get.mockImplementation((url) => Promise.resolve({ data: url === 'admin/pos/pro-invoices'
      ? { data: [{ id: 9, number: 'FP-2026-0009', service_date: '2026-11-15', client_name: '', label: 'Repas', quantity: 1, amount_ttc: '9.00', payment_label: 'Espèces', cancelled: false }], meta: { total: 1 } }
      : '' }));
    const w = mountPage();
    await flushPromises();
    expect(w.find('[data-testid="fp-row-FP-2026-0009"]').text()).toContain('—');
  });

  it('[2026-11-16] avec le pont de caisse : le TICKET ESC/POS part à l\'imprimante, pas le document A4', async () => {
    isCaisseBridgeAvailable.mockResolvedValue(true);
    printEscPosViaCaisseBridge.mockResolvedValue({ ok: true, method: 'caisse-bridge' });
    const w = mountPage();
    await flushPromises();
    await w.find('[data-testid="fp-reprint-2"]').trigger('click');
    await flushPromises();

    expect(axios.get).toHaveBeenCalledWith('admin/pos/pro-invoices/2/escpos');
    expect(printEscPosViaCaisseBridge).toHaveBeenCalledWith('G0A=');
    expect(axios.get).not.toHaveBeenCalledWith('admin/pos/pro-invoices/2/document', expect.anything());
    expect(document.querySelector('[data-testid="fp-print-frame"]')).toBeNull();
    expect(w.find('[data-testid="fp-info"]').text()).toContain('imprimante de caisse');
  });

  it('[2026-11-16] pont présent mais imprimante muette : message clair, jamais de A4 sur la thermique', async () => {
    isCaisseBridgeAvailable.mockResolvedValue(true);
    printEscPosViaCaisseBridge.mockResolvedValue(null);
    const w = mountPage();
    await flushPromises();
    await w.find('[data-testid="fp-reprint-2"]').trigger('click');
    await flushPromises();

    expect(w.text()).toContain('L\'imprimante de caisse n\'a pas répondu');
    expect(axios.get).not.toHaveBeenCalledWith('admin/pos/pro-invoices/2/document', expect.anything());
    expect(document.querySelector('[data-testid="fp-print-frame"]')).toBeNull();
  });

  it('[2026-11-16] le bouton A4 imprime le document A4 même quand le pont existe', async () => {
    isCaisseBridgeAvailable.mockResolvedValue(true);
    const w = mountPage();
    await flushPromises();
    await w.find('[data-testid="fp-a4-2"]').trigger('click');
    await flushPromises();

    expect(axios.get).toHaveBeenCalledWith('admin/pos/pro-invoices/2/document', expect.any(Object));
    expect(printEscPosViaCaisseBridge).not.toHaveBeenCalled();
    expect(document.querySelector('[data-testid="fp-print-frame"]')).not.toBeNull();
  });

  it('envoie le formulaire avec une clé d\'idempotence puis imprime (sans pont : document A4)', async () => {
    axios.post.mockResolvedValue({ data: { data: { id: 3, number: 'FP-2026-0003' } } });
    const w = mountPage();
    await flushPromises();
    await w.find('[data-testid="fp-client-name"]').setValue('Transports Martin SARL');
    await w.find('[data-testid="fp-label"]').setValue('2 repas');
    await w.find('[data-testid="fp-amount"]').setValue('18,00');
    await w.find('[data-testid="fp-pay-titre_restaurant"]').trigger('click');
    await w.find('[data-testid="pro-invoice-form"]').trigger('submit');
    await flushPromises();

    expect(axios.post).toHaveBeenCalledTimes(1);
    const [url, body, cfg] = axios.post.mock.calls[0];
    expect(url).toBe('admin/pos/pro-invoices');
    expect(body).toMatchObject({ client_name: 'Transports Martin SARL', label: '2 repas', amount_ttc: '18,00', payment_method: 'titre_restaurant', vat_rate: '10', quantity: 1 });
    expect(body).not.toHaveProperty('amount_ht'); // le serveur calcule
    expect(cfg.headers['X-Idempotency-Key']).toMatch(/^fp-/);
    expect(axios.get).toHaveBeenCalledWith('admin/pos/pro-invoices/3/document', expect.any(Object));
    expect(document.querySelector('[data-testid="fp-print-frame"]')).not.toBeNull();
    expect(w.find('[data-testid="fp-client-name"]').element.value).toBe(''); // formulaire remis à zéro
  });

  it('affiche les erreurs 422 du serveur sous chaque champ', async () => {
    axios.post.mockRejectedValue({ response: { status: 422, data: { errors: { amount_ttc: ['Le montant TTC doit être supérieur à 0.'] } } } });
    const w = mountPage();
    await flushPromises();
    await w.find('[data-testid="fp-pay-carte"]').trigger('click');
    await w.find('[data-testid="pro-invoice-form"]').trigger('submit');
    await flushPromises();
    expect(w.text()).toContain('Le montant TTC doit être supérieur à 0.');
  });

  it('annule avec un motif et recharge la liste', async () => {
    axios.post.mockResolvedValue({ data: { data: { id: 2, cancelled: true } } });
    const w = mountPage();
    await flushPromises();
    await w.find('[data-testid="fp-cancel-2"]').trigger('click');
    await w.find('[data-testid="fp-cancel-reason"]').setValue('Erreur de montant');
    await w.find('.fp-cancel-form').trigger('submit');
    await flushPromises();
    const call = axios.post.mock.calls.find((c) => c[0] === 'admin/pos/pro-invoices/2/cancel');
    expect(call[1]).toEqual({ cancel_reason: 'Erreur de montant' });
    expect(call[2].headers['X-Idempotency-Key']).toMatch(/^fpc-/);
  });
});

describe('Accès depuis la caisse', () => {
  it('la navigation secondaire mène à la facture pro', () => {
    const w = mount(CaisseSecondaryNav, {
      props: { current: 'facture-pro' },
      global: { stubs: { 'router-link': { props: ['to'], template: '<a :data-to="to && to.name"><slot /></a>' } }, mocks: { $t: (k) => k } },
    });
    const link = w.find('[data-testid="csn-facture-pro"]');
    expect(link.attributes('data-to')).toBe('admin.facture-pro');
    expect(link.classes()).toContain('active');
  });

  it('la barre caisse a son bouton, et pos-app.js déclare la route (sinon MATCHER_NOT_FOUND)', () => {
    const root = path.resolve(__dirname, '../..');
    const pos = fs.readFileSync(path.join(root, 'resources/js/components/admin/pos/PosComponent.vue'), 'utf8');
    const posApp = fs.readFileSync(path.join(root, 'resources/js/pos-app.js'), 'utf8');
    const routes = fs.readFileSync(path.join(root, 'resources/js/router/modules/posRoutes.js'), 'utf8');
    const fr = JSON.parse(fs.readFileSync(path.join(root, 'resources/js/languages/fr.json'), 'utf8'));
    expect(pos).toContain('data-testid="pos-facture-pro-open"');
    expect(pos).toContain(":to=\"{ name: 'admin.facture-pro' }\"");
    expect(posApp).toContain("name: 'admin.facture-pro'");
    expect(routes).toContain('name: "admin.facture-pro"');
    expect(fr.menu.facture_pro).toBe('Facture pro');
  });
});
