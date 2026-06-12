import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * [DISPUTE-R1 A-RED-6 2026-06-12] — P2 ticket : colonne « Prix » et
 * « SOUS-TOTAL » rendus en HT SANS marqueur, alors que le show back-office
 * affiche « Sous-total » TTC pour la MÊME commande (4,09 € vs 4,50 €).
 *
 * Fix minimal (AUCUN changement de calcul — l'arithmétique interne est
 * juste, 4,0909×1,1 = 4,50 ✓) : marqueur « HT » explicite sur le ticket
 * client là où les montants sont hors taxe :
 *   - en-tête de colonne « Prix HT » (les lignes affichent
 *     total_without_tax_currency_price) ;
 *   - libellé « SOUS-TOTAL HT » (subtotal_without_tax_currency_price).
 * Le TOTAL reste TTC sans marqueur (c'est le montant payé).
 * (Clés i18n dédiées price_ht/subtotal_ht → backlog H2 ; « HT » est une
 * abréviation fiscale FR, hardcodée comme le fallback existant ligne
 * tax_lines.)
 */

const src = readFileSync(
  resolve(process.cwd(), 'resources/js/components/admin/pos/ReceiptComponent.vue'),
  'utf8',
);

describe('ReceiptComponent — marqueurs HT du ticket client (A-RED-6)', () => {
  it("l'en-tête de colonne prix porte le marqueur HT", () => {
    expect(src).toMatch(/\{\{\s*\$t\('label\.price'\)\s*\}\}\s*HT<\/span>/);
  });

  it('le libellé sous-total porte le marqueur HT', () => {
    expect(src).toMatch(/\{\{\s*\$t\('label\.subtotal'\)\s*\}\}\s*HT\s*:/);
  });

  it('le TOTAL (TTC payé) reste SANS marqueur HT', () => {
    expect(src).not.toMatch(/\{\{\s*\$t\('label\.total'\)\s*\}\}\s*HT/);
  });

  it('aucun montant n’est recalculé (les projections backend restent les mêmes)', () => {
    expect(src).toMatch(/total_without_tax_currency_price/);
    expect(src).toMatch(/subtotal_without_tax_currency_price/);
    expect(src).toMatch(/total_currency_price/);
  });
});
