import { describe, it, expect } from 'vitest';
import { readFileSync } from 'fs';
import { resolve } from 'path';
import KioskCategoriesComponent from '../../resources/js/components/frontend/kiosk/KioskCategoriesComponent.vue';

/**
 * [OWNER 2026-10-28] Borne : photos produit PLEINE CARTE (bord à bord, plus de cadre gris à 94 %) ; la taille d'un produit
 * à tailles (Tacos M / L / XL) se voit en zoomant le produit dans le cadre, jamais en le rapetissant.
 */
const SFC = readFileSync(resolve(__dirname, '../../resources/js/components/frontend/kiosk/KioskCategoriesComponent.vue'), 'utf8');
const src = (p) => KioskCategoriesComponent.methods.productImageSrc(p);

describe('Borne — source de la photo produit', () => {
  it('prend l\'aperçu de 600 px d\'une photo téléversée (la vignette de 168×180 serait floue sur 760 px)', () => {
    expect(src({
      thumb: '/storage/69/conversions/g-cayenne-thumb.png',
      preview: '/storage/69/conversions/g-cayenne-preview.png',
    })).toBe('/storage/69/conversions/g-cayenne-preview.png');
  });

  it('garde la vignette quand le « preview » est l\'image générique du serveur', () => {
    expect(src({
      thumb: 'http://x/images/menu/thumbs/sandwich-classique.webp?v=1',
      preview: 'http://x/images/item/cover.png',
    })).toBe('http://x/images/menu/thumbs/sandwich-classique.webp?v=1');
  });

  it('retombe sur image quand il n\'y a pas de vignette', () => {
    expect(src({ image: '/a.png' })).toBe('/a.png');
  });
});

describe('Borne — photos pleine carte (CSS)', () => {
  it('la photo remplit sa boîte en cover, sans filtre ni cadre', () => {
    const bloc = SFC.slice(SFC.indexOf('PHOTOS PLEINE CARTE'));
    expect(bloc).toMatch(/\.kiosk-product-image \{[^}]*width: 100%;[^}]*height: 100%;[^}]*object-fit: cover;/s);
    expect(bloc).toMatch(/\.kiosk-product-card \{ padding: 0 0 18px; \}/);
  });

  it('les tailles se voient en zoomant : M plein cadre, puis L < XL < XXL, jamais en dessous de 1', () => {
    const lire = (c) => parseFloat(SFC.match(new RegExp(`kiosk-product-image--size-${c}\\s*\\{[^}]*scale\\(([0-9.]+)\\)`))[1]);
    const [m, l, xl, xxl] = ['m', 'l', 'xl', 'xxl'].map(lire);
    expect(m).toBeGreaterThanOrEqual(1);
    expect(l).toBeGreaterThan(m);
    expect(xl).toBeGreaterThan(l);
    expect(xxl).toBeGreaterThan(xl);
  });

  it('les détourés des listes denses (boissons) restent entiers : contain', () => {
    expect(SFC).toMatch(/\.kiosk-product-grid--minimal \.kiosk-product-image \{ object-fit: contain; \}/);
  });
});
