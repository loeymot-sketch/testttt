/**
 * [QA 2026-09-28 — RAPPORT_DEV_CAISSE_2026-09-24 P1-43]
 *
 * Deux défauts distincts derrière la même observation « 12 Hour (7:34 PM) ».
 *
 * 1. **Le libellé mentait.** `timeFormatEnum` codait `'PM'` / `'pm'` EN DUR,
 *    quelle que soit l'heure réelle : à 07:34 l'option s'affichait littéralement
 *    « 12 Hour (7:34 PM) » — exactement la chaîne citée par le rapport. Et à
 *    12:xx comme à 00:xx le calcul `getHours() > 12 ? % 12 : getHours()`
 *    rendait `12` et `0` au lieu de `12` et `12`.
 *
 * 2. **Le défaut semé contredisait ADR-007.** `SiteTableSeeder` posait
 *    `site_time_format = 'h:i A'` (12 h en-US) alors que la valeur canonique
 *    documentée est `'H:i'` (24 h FR, verrou ADR-007 rappelé dans
 *    `AppLibrary`). Toute nouvelle installation partait donc en 12 h, pendant
 *    que le ticket imprimé, le KDS et les exports formatent en dur en 24 h
 *    (`OrderReceiptEscPosRenderer`, `KDSOrderDetailsResource`,
 *    `appService.dateHeureFr` avec `hour12: false`) : choisir « 12 Hour »
 *    scindait réellement le produit.
 *
 * ⛔ Les options 12 h ne sont PAS supprimées ici : un commerçant peut déjà avoir
 * enregistré `h:i A`, et retirer l'option laisserait son select sans valeur
 * correspondante. Sous ADR-007 le bon geste est de ne plus la SEMER et de ne
 * plus mentir sur son libellé ; retirer l'option est une décision produit,
 * escaladée au propriétaire.
 */
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { readFileSync } from 'fs';
import { resolve } from 'path';

describe('format horaire — libellé honnête et défaut 24 h', () => {
  let realDate;

  afterEach(() => {
    if (realDate) global.Date = realDate;
    realDate = undefined;
  });

  const chargerEnum = async (isoFixe) => {
    realDate = Date;
    function MockDate(...args) {
      if (args.length) return new realDate(...args);
      return new realDate(isoFixe);
    }
    MockDate.prototype = realDate.prototype;
    MockDate.now = () => new realDate(isoFixe).getTime();
    global.Date = MockDate;
    // L'enum est figé au chargement du module : on le réimporte à chaque heure.
    const mod = await import('../../resources/js/enums/modules/timeFormatEnum.js?t=' + encodeURIComponent(isoFixe));
    return mod.default;
  };

  it('le matin, l’option 12 h ne doit plus afficher « PM »', async () => {
    const options = await chargerEnum('2026-09-28T07:34:00');
    const douzeHeures = options.find((o) => o.id === 'h:i A');

    expect(douzeHeures.name).toContain('AM');
    expect(douzeHeures.name).not.toContain('PM');
  });

  it('l’après-midi, l’option 12 h affiche bien « PM »', async () => {
    const options = await chargerEnum('2026-09-28T19:34:00');
    const douzeHeures = options.find((o) => o.id === 'h:i A');

    expect(douzeHeures.name).toContain('PM');
    expect(douzeHeures.name).toContain('7:34');
  });

  it('minuit s’affiche 12 et non 0 en 12 h', async () => {
    const options = await chargerEnum('2026-09-28T00:15:00');
    const douzeHeures = options.find((o) => o.id === 'h:i A');

    expect(douzeHeures.name).toContain('12:15');
    expect(douzeHeures.name).toContain('AM');
  });

  it('midi s’affiche 12 PM', async () => {
    const options = await chargerEnum('2026-09-28T12:05:00');
    const douzeHeures = options.find((o) => o.id === 'h:i A');

    expect(douzeHeures.name).toContain('12:05');
    expect(douzeHeures.name).toContain('PM');
  });

  it('l’option 24 h reste proposée et zéro-paddée', async () => {
    const options = await chargerEnum('2026-09-28T07:04:00');
    const vingtQuatre = options.find((o) => o.id === 'H:i');

    expect(vingtQuatre).toBeDefined();
    expect(vingtQuatre.name).toContain('07:04');
  });

  it('le défaut semé est 24 h, conformément au verrou FR ADR-007', () => {
    const seeder = readFileSync(
      resolve(__dirname, '../../database/seeders/SiteTableSeeder.php'),
      'utf8'
    );

    expect(seeder).toContain("'site_time_format' => 'H:i'");
    expect(seeder).not.toContain("'site_time_format' => 'h:i A'");
  });
});
