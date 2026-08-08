import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * Le 8 août 2026, le propriétaire signale : « le wizard ne fonctionne pas, la
 * borne ajoute direct au panier ». Reproduit en coupant le seul appel qui dit
 * si un produit se compose (`frontendItem/details`) : le `catch` d'openProduct
 * ajoutait l'article DIRECTEMENT au panier. Un Suprême partait à 7,00 € sans
 * pain, sans sauce et sans viande, sans un mot au client — qui pouvait valider.
 * La cuisine recevait une commande impossible à préparer, et la composition
 * scellée à la commande était incomplète.
 *
 * Le premier échec est le plus souvent un 401 « jeton expiré » : la borne se
 * reconnecte seule dans la foulée, donc une deuxième tentative suffit presque
 * toujours. Si elle échoue aussi, on le DIT et on n'ajoute RIEN.
 *
 * Cette sentinelle lit la source : tant qu'aucun ajout au panier ne peut être
 * atteint depuis un chemin d'erreur, le défaut ne peut pas revenir par
 * inadvertance.
 */
const SOURCE = resolve(
  __dirname,
  '../../resources/js/components/frontend/kiosk/KioskCategoriesComponent.vue',
);

function methodeOpenProduct() {
  const src = readFileSync(SOURCE, 'utf-8');
  const debut = src.indexOf('async openProduct(product) {');
  expect(debut, 'openProduct introuvable — la sentinelle ne teste plus rien').toBeGreaterThan(-1);
  const fin = src.indexOf('\n    },', debut);
  expect(fin, 'fin de openProduct introuvable').toBeGreaterThan(debut);
  return src.slice(debut, fin);
}

describe('borne — un échec de chargement ne remplit jamais le panier en aveugle', () => {
  it('aucun ajout au panier dans un bloc catch d’openProduct', () => {
    const m = methodeOpenProduct();
    // On isole chaque `catch (...) { … }` et on vérifie qu'aucun n'ajoute.
    const catches = [...m.matchAll(/catch\s*\([^)]*\)\s*\{/g)].map((c) => {
      let i = m.indexOf('{', c.index + c[0].length - 1);
      let prof = 0;
      for (let j = i; j < m.length; j += 1) {
        if (m[j] === '{') prof += 1;
        else if (m[j] === '}') {
          prof -= 1;
          if (prof === 0) return m.slice(i, j + 1);
        }
      }
      return m.slice(i);
    });
    expect(catches.length, 'aucun catch trouvé — structure changée').toBeGreaterThan(0);
    catches.forEach((bloc) => {
      expect(
        bloc.includes('this.addItem('),
        'un chemin d’erreur ajoute au panier : le produit partirait sans ses choix obligatoires',
      ).toBe(false);
    });
  });

  it('un échec est signalé au client, il n’est pas avalé en silence', () => {
    const m = methodeOpenProduct();
    expect(m).toContain('this.itemError =');
  });

  it('une deuxième tentative est faite avant d’abandonner (le 401 se répare seul)', () => {
    const m = methodeOpenProduct();
    const appels = (m.match(/await charger\(\)/g) || []).length;
    expect(appels, 'il faut deux tentatives : la reconnexion borne suit le premier 401').toBe(2);
  });

  it('le message existe en français et nomme le produit concerné', () => {
    const fr = JSON.parse(
      readFileSync(resolve(__dirname, '../../resources/js/languages/fr.json'), 'utf-8'),
    );
    const texte = fr?.kiosk?.catalog?.item_options_error;
    expect(texte, 'clé kiosk.catalog.item_options_error absente').toBeTruthy();
    expect(texte).toContain('{name}');
    // Le client doit être rassuré : rien n'a été ajouté à son insu.
    expect(texte.toLowerCase()).toMatch(/rien n'a été ajouté|nothing was added/);
  });

  it('le message est rendu dans la zone produits, pas dans la branche « catalogue vide »', () => {
    const src = readFileSync(SOURCE, 'utf-8');
    const bloc = src.indexOf('data-testid="kiosk-item-error"');
    const vide = src.indexOf('class="kiosk-catalogue-empty"');
    const grille = src.indexOf('class="kiosk-product-grid"');
    expect(bloc, 'bloc message absent').toBeGreaterThan(-1);
    // Piège vécu : posé dans la branche « catalogue vide », il ne s'affichait
    // jamais puisqu'il y a des produits — test vert, client sans information.
    expect(bloc > vide && bloc < grille, 'le message doit précéder la grille produits').toBe(true);
  });
});
