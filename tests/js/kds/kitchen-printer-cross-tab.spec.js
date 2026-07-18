import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

/**
 * [S3-06 / P2-m 2026-07-18 REGISTRE_FINAL] Dé-dup impression cuisine CROSS-ONGLET.
 *
 * Bug : le set des ids imprimés (`_printed`) était mémoïsé PAR ONGLET (lu une fois
 * puis caché dans le module) et la garde in-flight vivait dans le composant KDS,
 * donc PAR ONGLET aussi. Deux onglets /kds ouverts sur le PC cuisine recevaient la
 * MÊME commande (WS/poll) et voyaient tous deux hasKitchenPrinted=false / rien
 * en in-flight → 2 POST au pont 9101 → 2 tickets physiques.
 * (Distinct du doublon INTRA-onglet 20 s déjà healé.)
 *
 * Fix (kitchenLocalPrinter.js) : claim in-flight PERSISTÉ + partagé en localStorage
 * (un seul onglet obtient le claim → un seul POST), relecture FRAÎCHE du printed set
 * au moment de décider, et listener `storage` qui resynchronise le cache entre onglets.
 *
 * Ce test simule DEUX onglets = deux instances distinctes du module (vi.resetModules
 * + import), partageant le MÊME window.localStorage (comme deux onglets d'un même
 * navigateur). On prouve : la même commande reçue par les 2 onglets → 1 SEUL POST.
 */

const MODULE_PATH = '../../../resources/js/helpers/kitchenLocalPrinter';

// Charge une NOUVELLE instance du module = simule un onglet distinct (son propre
// cache _printed / son propre _tabId), partageant le localStorage global happy-dom.
async function loadTab() {
  vi.resetModules();
  return import(MODULE_PATH);
}

// Reproduit fidèlement le gate d'auto-impression du composant KDS
// (KitchenDisplaySystemComponent.autoPrintNewKitchenTickets) : dé-dup + claim
// cross-onglet + POST au pont + marquage, release dans le finally.
async function autoPrintGate(mod, order) {
  if (mod.hasKitchenPrinted(order.id)) return { posted: false, reason: 'printed' };
  if (!mod.claimKitchenPrint(order.id)) return { posted: false, reason: 'claimed-elsewhere' };
  try {
    const r = await mod.printEscPosViaKitchenBridge(order.escpos_b64);
    if (r && r.ok) mod.markKitchenPrinted(order.id);
    return { posted: true, r };
  } finally {
    mod.releaseKitchenPrint(order.id);
  }
}

function fireStorage(key, newValue) {
  let ev;
  try {
    ev = new StorageEvent('storage', { key, newValue });
  } catch (_) {
    ev = new Event('storage');
    try { ev.key = key; ev.newValue = newValue; } catch (_) { /* env sans props libres */ }
  }
  window.dispatchEvent(ev);
}

describe('KDS — dé-dup impression cuisine CROSS-ONGLET (2 onglets = 1 seul ticket)', () => {
  beforeEach(() => {
    try { window.localStorage.clear(); } catch (_) { /* noop */ }
    globalThis.fetch = vi.fn(async () => ({ ok: true }));
  });
  afterEach(() => { vi.restoreAllMocks(); });

  it('2 onglets reçoivent la MÊME commande simultanément → 1 SEUL POST au pont (pas 2)', async () => {
    const tabA = await loadTab();
    const tabB = await loadTab();
    const order = { id: 7777, escpos_b64: btoa('\x1B@KITCHEN') };

    // Arrivée « simultanée » : les 2 onglets décident d'imprimer avant qu'aucun n'ait fini.
    const [rA, rB] = await Promise.all([
      autoPrintGate(tabA, order),
      autoPrintGate(tabB, order),
    ]);

    const posted = [rA, rB].filter((r) => r.posted);
    expect(globalThis.fetch).toHaveBeenCalledTimes(1); // 1 seul ticket physique
    expect(posted).toHaveLength(1);                     // un seul onglet a imprimé
    expect([rA, rB].filter((r) => !r.posted)[0].reason).toBe('claimed-elsewhere');
  });

  it('2e onglet ne ré-imprime PAS une commande déjà imprimée par le 1er (la relecture fraîche du claim immunise le cache périmé)', async () => {
    const tabA = await loadTab();
    const tabB = await loadTab();

    // Force le cache de B à se charger MAINTENANT (localStorage vide) → il sera PÉRIMÉ
    // après l'impression de A (happy-dom n'émet pas d'event storage automatique).
    expect(tabB.hasKitchenPrinted(1)).toBe(false);

    const order = { id: 6001, escpos_b64: btoa('x') };
    await autoPrintGate(tabA, order);                  // A imprime + marque (localStorage partagé)
    expect(globalThis.fetch).toHaveBeenCalledTimes(1);

    // Cache de B PÉRIMÉ : hasKitchenPrinted local = false — c'est EXACTEMENT le bug d'origine.
    // Mais claimKitchenPrint relit localStorage frais → voit « imprimé » → refuse le 2e POST.
    expect(tabB.hasKitchenPrinted(6001)).toBe(false);  // cache périmé (démontre le bug d'origine)
    const rB = await autoPrintGate(tabB, order);
    expect(rB.posted).toBe(false);
    expect(rB.reason).toBe('claimed-elsewhere');       // rejeté par la relecture fraîche du printed
    expect(globalThis.fetch).toHaveBeenCalledTimes(1); // toujours 1 POST
  });

  it('event `storage` : marquer imprimé dans un onglet resynchronise hasKitchenPrinted dans l\'autre', async () => {
    const tabA = await loadTab();
    const tabB = await loadTab();
    tabB.hasKitchenPrinted(1);                          // initialise le cache (vide) de B
    tabA.markKitchenPrinted(4242);                      // A imprime → écrit localStorage

    fireStorage('kds.printedKitchenIds', window.localStorage.getItem('kds.printedKitchenIds'));

    expect(tabB.hasKitchenPrinted(4242)).toBe(true);   // cache de B resynchronisé cross-onglet
  });
});

describe('KDS — impression cuisine MONO-onglet inchangée (non-régression)', () => {
  beforeEach(() => {
    try { window.localStorage.clear(); } catch (_) { /* noop */ }
    globalThis.fetch = vi.fn(async () => ({ ok: true }));
  });
  afterEach(() => { vi.restoreAllMocks(); });

  it('imprime 1 fois, ne ré-imprime pas au 2e passage (dé-dup identique)', async () => {
    const tab = await loadTab();
    const order = { id: 8888, escpos_b64: btoa('x') };

    const r1 = await autoPrintGate(tab, order);
    const r2 = await autoPrintGate(tab, order);

    expect(r1.posted).toBe(true);
    expect(r2.posted).toBe(false);
    expect(r2.reason).toBe('printed');
    expect(globalThis.fetch).toHaveBeenCalledTimes(1);
    expect(tab.hasKitchenPrinted(8888)).toBe(true);
  });

  it('un ÉCHEC pont ne marque PAS imprimé → réessayable (le retry ressort le ticket)', async () => {
    globalThis.fetch = vi.fn()
      .mockResolvedValueOnce({ ok: false, status: 500 }) // 1re tentative : pont KO
      .mockResolvedValueOnce({ ok: true });               // retry : pont revenu
    const tab = await loadTab();
    const order = { id: 9999, escpos_b64: btoa('x') };

    const r1 = await autoPrintGate(tab, order);
    expect(r1.posted).toBe(true);                        // POST bien parti
    expect(r1.r.ok).toBe(false);                         // mais pont a refusé
    expect(tab.hasKitchenPrinted(9999)).toBe(false);     // NON marqué → réessayable

    // Retry (claim relâché dans le finally) : ressort le ticket, cette fois OK.
    const r2 = await autoPrintGate(tab, order);
    expect(r2.r.ok).toBe(true);
    expect(tab.hasKitchenPrinted(9999)).toBe(true);
    expect(globalThis.fetch).toHaveBeenCalledTimes(2);
  });
});
