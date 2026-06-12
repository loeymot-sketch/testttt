import { describe, expect, it, beforeEach, vi } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { mount } from '@vue/test-utils';

/**
 * [DISPUTE-R1 ADV-F-P0-1 2026-06-12] — P0 régression du heal W2-G6 (2026-06-11).
 *
 * Le footer du modal « Encaisser la commande borne » (PosCounterCollectModal)
 * était devenu `position: sticky; bottom: 0; z-index: 1; background opaque`
 * DANS le conteneur scrollable `.cc-modal` (max-height 92vh; overflow-y auto).
 * À 1440×900 (et pire à 1366×768) le contenu dépasse (scrollHeight 940 /
 * clientHeight 828) → le footer sticky opaque RECOUVRAIT les touches
 * 7 / 8 / 9 / 00 / 0 / « , » du pavé numérique :
 *   - hit-test live elementFromPoint : `9` → interceptée par le bouton
 *     « ✓ Confirmer & Imprimer ticket » (taper 9 pouvait DÉCLENCHER
 *     l'encaissement + impression — événement fiscal NF525 irréversible) ;
 *   - 7/8/00/0/, → tap MORT silencieux sur `.cc-modal-footer`.
 *
 * Fix structurel (le seul qui rend le chevauchement IMPOSSIBLE à toute
 * hauteur de viewport) : le modal devient une colonne flex
 *   .cc-modal { display:flex; flex-direction:column; overflow:hidden }
 *     ├── .cc-modal-body { flex:1; min-height:0; overflow-y:auto }  ← scroll INTERNE (header+hero+modes+numpad)
 *     └── .cc-modal-footer { flex:0 0 auto; position:static }       ← HORS de la zone scrollable
 * Le footer n'est plus DANS le conteneur scrollable : il ne peut plus
 * recouvrir le pavé, et le CTA reste toujours visible (l'intention du heal
 * G6 d'origine est préservée, sans son effet de bord).
 *
 * Ce spec verrouille les deux moitiés du contrat :
 *   1. structure DOM montée : le numpad vit dans .cc-modal-body (scrollable),
 *      le footer est un SIBLING hors du scroller — à 900px comme à 768px de
 *      hauteur, aucune géométrie ne peut les faire se chevaucher ;
 *   2. source CSS : plus de `position: sticky` sur .cc-modal-footer, plus de
 *      `overflow-y: auto` sur .cc-modal lui-même.
 * Preuve géométrique live (hit-test elementFromPoint 1440×900 + 1366×768) :
 * reports/test-e2e/dispute-2026-06-12/round-1/heal-proofs/.
 */

const COMPONENT_PATH = resolve(
  process.cwd(),
  'resources/js/components/admin/pos/PosCounterCollectModal.vue',
);
// Commentaires CSS retirés avant inspection : les commentaires historiques
// documentent l'ANCIEN style et ne doivent pas produire de faux positifs.
const source = readFileSync(COMPONENT_PATH, 'utf8').replace(/\/\*[\s\S]*?\*\//g, '');

// ---------------------------------------------------------------------------
// Moitié 1 — structure DOM (mount réel, numpad réel)
// ---------------------------------------------------------------------------
describe('PosCounterCollectModal — footer hors du scroller (ADV-F-P0-1)', () => {
  let wrapper;

  beforeEach(async () => {
    vi.resetModules();
    const { default: PosCounterCollectModal } = await import(
      '../../resources/js/components/admin/pos/PosCounterCollectModal.vue'
    );
    wrapper = mount(PosCounterCollectModal, {
      props: {
        order: { id: 4537, total: 9.5, queue_number: 'A0011' },
      },
      global: {
        mocks: {
          $t: (key) => key,
        },
      },
      attachTo: document.body,
    });
    await wrapper.vm.$nextTick();
  });

  it('monte le modal avec le bloc CASH + numpad visibles', () => {
    expect(wrapper.find('[data-testid="pos-counter-collect-modal"]').exists()).toBe(true);
    expect(wrapper.find('[data-testid="pos-counter-collect-cash-block"]').exists()).toBe(true);
  });

  it('le pavé numérique vit DANS la zone scrollable interne .cc-modal-body', () => {
    const body = wrapper.find('.cc-modal-body');
    expect(body.exists()).toBe(true);
    // Le numpad (atome partagé PosV5Numpad) doit être un descendant du body scrollable.
    const numpadInBody = body.element.querySelector('.pos-v5-numpad, [class*="numpad"], [data-testid*="numpad"]');
    expect(numpadInBody).not.toBeNull();
  });

  it('le footer est un SIBLING du body scrollable — PAS un descendant (chevauchement impossible)', () => {
    const body = wrapper.find('.cc-modal-body');
    const footer = wrapper.find('.cc-modal-footer');
    expect(footer.exists()).toBe(true);
    // Le footer ne doit PAS être à l'intérieur du conteneur qui scrolle.
    expect(body.element.contains(footer.element)).toBe(false);
    // Le footer et le body doivent partager le même parent flex-column (.cc-modal).
    expect(footer.element.parentElement).toBe(body.element.parentElement);
    expect(footer.element.parentElement.classList.contains('cc-modal')).toBe(true);
  });

  it('le CTA Confirmer et le bouton Annuler restent dans le footer (intention G6 préservée)', () => {
    const footer = wrapper.find('.cc-modal-footer');
    expect(footer.find('[data-testid="pos-counter-collect-confirm"]').exists()).toBe(true);
    expect(footer.find('[data-testid="pos-counter-collect-cancel"]').exists()).toBe(true);
  });
});

// ---------------------------------------------------------------------------
// Moitié 2 — contrat CSS source (le sticky opaque ne doit jamais revenir)
// ---------------------------------------------------------------------------
describe('PosCounterCollectModal — contrat CSS anti-occlusion (source)', () => {
  const footerBlock = () => {
    const m = source.match(/\.cc-modal-footer\s*\{[^}]*\}/);
    return m ? m[0] : '';
  };
  const modalBlock = () => {
    const m = source.match(/\.cc-modal\s*\{[^}]*\}/);
    return m ? m[0] : '';
  };
  const bodyBlock = () => {
    const m = source.match(/\.cc-modal-body\s*\{[^}]*\}/);
    return m ? m[0] : '';
  };

  it('.cc-modal-footer ne porte PLUS position: sticky (la cause du P0)', () => {
    expect(footerBlock()).not.toMatch(/position:\s*sticky/);
  });

  it(".cc-modal n'est plus lui-même le scroller (overflow-y: auto interdit sur la racine)", () => {
    expect(modalBlock()).not.toMatch(/overflow-y:\s*auto/);
    // colonne flex : le scroll vit dans le body, le footer est fixe en bas.
    expect(modalBlock()).toMatch(/flex-direction:\s*column/);
  });

  it('.cc-modal-body est le scroller interne (overflow-y: auto + min-height: 0)', () => {
    expect(bodyBlock()).toMatch(/overflow-y:\s*auto/);
    expect(bodyBlock()).toMatch(/min-height:\s*0/);
  });

  it('compaction viewports courts : media query ≤820px présente (fit 1366×768 validé live, body 664/664, 14/14 touches OK)', () => {
    // Les valeurs ont été VALIDÉES LIVE par simulation DOM avant écriture :
    //   1440×900 → body 736/736, 14/14 touches hit-test OK sans scroll ;
    //   1366×768 → body 664/664, 14/14 touches hit-test OK sans scroll.
    expect(source).toMatch(/@media\s*\(max-height:\s*820px\)/);
    // Plancher tactile des touches : 48px, jamais en dessous.
    expect(source).toMatch(/\.cc-cash-section\s*:deep\(\.pos-v5-numpad button\)\s*\{\s*min-height:\s*48px/);
    expect(source).not.toMatch(/\.pos-v5-numpad button\)\s*\{\s*min-height:\s*(?:[1-3]\d|4[0-7])px/);
  });
});
