import { describe, it, expect } from 'vitest';
import { createI18n } from 'vue-i18n';
import { mount } from '@vue/test-utils';
import KioskStepPain from '../../resources/js/components/frontend/kiosk/steps/KioskStepPainComponent.vue';
import KioskStepSupplements from '../../resources/js/components/frontend/kiosk/steps/KioskStepSupplementsComponent.vue';
import frMessages from '../../resources/js/languages/fr.json';

/**
 * [OWNER 2026-10-21] BORNE.
 *  1. L'étape « pain / galette » montre le PAIN ou la GALETTE seuls — pas le sandwich complet que l'image héritée
 *     de config/menu_images.php (« bases ») affichait. Une photo posée depuis l'admin reste prioritaire.
 *  2. Les suppléments « Recommandés » (drapeau `is_recommended` posé par le serveur) passent en tête de liste avec un badge.
 */

const i18n = createI18n({ legacy: false, locale: 'fr', fallbackLocale: 'fr', messages: { fr: frMessages } });
const SANDWICH_COMPLET = '/images/menu/sandwich-classique.webp?v=1';
const GALETTE_COMPLETE = '/images/menu/galette.webp?v=1';

const itemAvecPain = (thumbPain = SANDWICH_COMPLET, thumbGalette = GALETTE_COMPLETE) => ({
  id: 22, name: 'Cayenne',
  itemAttributes: [{ id: 6, name: 'Type de Pain' }],
  variations: {
    6: [
      { id: 450, name: 'Pain', item_attribute_id: 6, status: 5, thumb: thumbPain },
      { id: 451, name: 'Galette', item_attribute_id: 6, status: 5, thumb: thumbGalette },
    ],
  },
});

const montePain = (item) => mount(KioskStepPain, {
  props: { step: {}, item, selections: {} },
  global: { plugins: [i18n] },
});

describe('Borne — étape pain/galette', () => {
  it('remplace le sandwich complet par le pain seul et la galette seule', () => {
    const w = montePain(itemAvecPain());
    const srcs = w.findAll('.kiosk-pain-thumb').map((i) => i.attributes('src'));
    expect(srcs).toEqual(['/images/menu/pain-seul.svg', '/images/menu/galette-seule.svg']);
  });

  it('garde une photo posée depuis l\'admin (hors images/menu)', () => {
    const w = montePain(itemAvecPain('/storage/12/mon-pain.jpg', '/storage/13/ma-galette.jpg'));
    const srcs = w.findAll('.kiosk-pain-thumb').map((i) => i.attributes('src'));
    expect(srcs).toEqual(['/storage/12/mon-pain.jpg', '/storage/13/ma-galette.jpg']);
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
