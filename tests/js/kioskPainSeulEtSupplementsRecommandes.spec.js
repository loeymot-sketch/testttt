import { describe, it, expect } from 'vitest';
import { createI18n } from 'vue-i18n';
import { mount } from '@vue/test-utils';
import KioskStepPain from '../../resources/js/components/frontend/kiosk/steps/KioskStepPainComponent.vue';
import KioskStepSupplements from '../../resources/js/components/frontend/kiosk/steps/KioskStepSupplementsComponent.vue';
import frMessages from '../../resources/js/languages/fr.json';

/**
 * [OWNER 2026-10-21 → 2026-10-28] BORNE.
 *  1. L'étape « pain / galette » montre la VRAIE photo du sandwich d'un côté et celle de la galette de l'autre (comme le site,
 *     décision du 28/10 : « le client voit ce qu'il choisit »). Sans photo trouvée → pictogramme 🥖 / 🌯 sur pastille teintée.
 *  2. Les suppléments « Recommandés » (drapeau `is_recommended` posé par le serveur) passent en tête de liste avec un badge.
 */

const i18n = createI18n({ legacy: false, locale: 'fr', fallbackLocale: 'fr', messages: { fr: frMessages } });

const itemAvecPain = (extra = {}) => ({
  id: 22, name: 'Cayenne', thumb: '/images/menu/thumbs/sandwich-cayenne.webp',
  itemAttributes: [{ id: 6, name: 'Type de Pain' }],
  variations: {
    6: [
      { id: 450, name: 'Pain', item_attribute_id: 6, status: 5 },
      { id: 451, name: 'Galette', item_attribute_id: 6, status: 5 },
    ],
  },
  ...extra,
});

const CATALOGUE = [
  { id: 24, name: 'Galette Cayenne', thumb: '/storage/17/conversions/gc-thumb.png', preview: '/storage/17/conversions/gc-preview.png' },
  { id: 164, name: 'Galette Classique', thumb: '/images/menu/thumbs/galette.webp', preview: '/images/item/cover.png' },
];

const montePain = (item, catalogue = CATALOGUE) => mount(KioskStepPain, {
  props: { step: {}, item, selections: {} },
  global: {
    plugins: [i18n],
    mocks: { $store: { getters: { 'kioskMenu/allItems': catalogue }, state: { kioskMenu: { items: catalogue } } } },
  },
});

describe('Borne — étape pain/galette', () => {
  it('montre la photo du sandwich du produit et celle de SA galette', () => {
    const w = montePain(itemAvecPain());
    const srcs = w.findAll('img.kiosk-pain-photo').map((n) => n.attributes('src'));
    // Pain = photo du produit ; Galette = Galette Cayenne, en aperçu 600 px (photo téléversée, pas la vignette 168 px floue).
    expect(srcs).toEqual(['/images/menu/thumbs/sandwich-cayenne.webp', '/storage/17/conversions/gc-preview.png']);
    expect(w.findAll('.kiosk-pain-emoji')).toHaveLength(0);
  });

  it('un autre sandwich que le Cayenne prend la Galette Classique ; son « preview » générique n\'est jamais utilisé', () => {
    const w = montePain(itemAvecPain({ name: 'Sandwich Classique', thumb: '/images/menu/thumbs/sandwich-classique.webp' }));
    const srcs = w.findAll('img.kiosk-pain-photo').map((n) => n.attributes('src'));
    expect(srcs).toEqual(['/images/menu/thumbs/sandwich-classique.webp', '/images/menu/thumbs/galette.webp']);
  });

  it('sans galette au catalogue : pictogramme pour la galette, photo pour le pain', () => {
    const w = montePain(itemAvecPain(), []);
    expect(w.findAll('img.kiosk-pain-photo')).toHaveLength(1);
    expect(w.findAll('.kiosk-pain-emoji').map((n) => n.text())).toEqual(['🌯']);
    expect(w.find('.kiosk-pain-emoji').classes()).toContain('is-galette');
  });

  it('sans aucune photo : retombe sur les pictogrammes teintés du site', () => {
    const w = montePain(itemAvecPain({ thumb: null }), []);
    expect(w.findAll('img')).toHaveLength(0);
    const pastilles = w.findAll('.kiosk-pain-emoji');
    expect(pastilles.map((n) => n.text())).toEqual(['🥖', '🌯']);
    expect(pastilles[0].classes()).toContain('is-pain');
    expect(pastilles[1].classes()).toContain('is-galette');
  });

  it('une photo qui ne charge pas est remplacée par le pictogramme (jamais une image cassée)', async () => {
    const w = montePain(itemAvecPain());
    await w.find('img.kiosk-pain-photo').trigger('error');
    expect(w.findAll('img.kiosk-pain-photo')).toHaveLength(1);
    expect(w.findAll('.kiosk-pain-emoji').map((n) => n.text())).toEqual(['🥖']);
  });

  it('chaque carte porte le nom du choix, pas celui d\'un sandwich', () => {
    const w = montePain(itemAvecPain());
    expect(w.findAll('.kiosk-pain-name').map((n) => n.text())).toEqual(['Pain', 'Galette']);
  });
});

const supplement = (id, name, recommended) => ({
  id, name, price: 0.9, convert_price: 0.9, group_label: 'supplement', status: 5, is_recommended: recommended,
});

describe('Borne — suppléments recommandés', () => {
  const monte = (extras) => mount(KioskStepSupplements, {
    props: { step: {}, item: { id: 22, name: 'Cayenne', extras }, selections: { supplements: {} } },
    global: { plugins: [i18n] },
  });

  it('met les recommandés en tête, ordre d\'origine conservé dans chaque groupe', () => {
    const w = monte([
      supplement(1, 'Raclette', false),
      supplement(2, 'Cheddar', true),
      supplement(3, 'Emmental', false),
      supplement(4, 'Boursin', true),
    ]);
    expect(w.findAll('.kiosk-supplement-name').map((n) => n.text())).toEqual(['Cheddar', 'Boursin', 'Raclette', 'Emmental']);
  });

  it('affiche le badge « Recommandé » sur les seuls recommandés', () => {
    const w = monte([supplement(1, 'Raclette', false), supplement(2, 'Cheddar', true)]);
    const badges = w.findAll('[data-testid="kiosk-supplement-recommended"]');
    expect(badges).toHaveLength(1);
    expect(badges[0].text()).toContain('Recommandé');
  });

  it('sans aucune recommandation : aucun badge, ordre inchangé', () => {
    const w = monte([supplement(1, 'Raclette', false), supplement(2, 'Cheddar', false)]);
    expect(w.findAll('[data-testid="kiosk-supplement-recommended"]')).toHaveLength(0);
    expect(w.findAll('.kiosk-supplement-name').map((n) => n.text())).toEqual(['Raclette', 'Cheddar']);
  });
});
