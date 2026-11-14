/**
 * [QA 2026-09-28 · P1-43] Les libellés d'exemple MENTAIENT.
 *
 * Avant : `'PM'` et `'pm'` étaient codés EN DUR quelle que soit l'heure — à
 * 07:34 l'option s'affichait littéralement « 12 Hour (7:34 PM) », qui est la
 * chaîne citée par l'audit. De plus `getHours() > 12 ? % 12 : getHours()`
 * rendait `0` à minuit (au lieu de 12) et l'exemple 24 h n'était pas
 * zéro-paddé (« 24 Hour (7:4) » à 07:04).
 *
 * Ces libellés ne sont que des EXEMPLES d'aide au choix : les `id` — les
 * formats réellement enregistrés — sont inchangés, donc aucune valeur déjà
 * choisie par un commerçant n'est affectée.
 *
 * ⛔ Les options 12 h ne sont volontairement PAS retirées : une installation
 * peut déjà avoir enregistré `h:i A`, et supprimer l'option laisserait son
 * select sans valeur correspondante. Sous le verrou FR 24 h (ADR-007) le bon
 * geste ici est de ne plus la SEMER par défaut (voir `SiteTableSeeder`) et de
 * ne plus mentir sur son libellé. Retirer l'option est une décision produit.
 */
const date = new Date();

const deuxChiffres = (n) => (n < 10 ? '0' : '') + n;

const heures24 = date.getHours();
const minutes = deuxChiffres(date.getMinutes());

// 0 h → 12 AM, 12 h → 12 PM, 13 h → 1 PM.
const heures12 = heures24 % 12 === 0 ? 12 : heures24 % 12;
const meridien = heures24 < 12 ? 'AM' : 'PM';

const timeFormatEnum = Object.freeze([
    {
        id: 'h:i A',
        name: '12 Hour' + ' (' + heures12 + ':' + minutes + ' ' + meridien + ')'
    },
    {
        id: 'h:i a',
        name: '12 Hour' + ' (' + heures12 + ':' + minutes + ' ' + meridien.toLowerCase() + ')'
    },
    {
        id: 'H:i',
        name: '24 Hour' + ' (' + deuxChiffres(heures24) + ':' + minutes + ')'
    }

]);

export default timeFormatEnum;
