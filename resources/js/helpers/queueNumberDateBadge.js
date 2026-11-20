/**
 * [QA 2026-09-28 — RAPPORT_DEV_CAISSE_2026-09-24 P0-18 / triage A2]
 *
 * `queue_number` (« N°A0043 ») est un compteur QUOTIDIEN par branche
 * (`OrderService::allocateQueueNumber` — remise à zéro à chaque `business_date`,
 * PAR CONCEPTION, pinné par `tests/Feature/QueueNumberConcurrencyTest` et
 * `QueueNumberUniquenessSentinelTest` : l'unicité porte sur le triplet
 * (branch_id, business_date, queue_number), donc la réutilisation d'un jour à
 * l'autre est LÉGALE côté base).
 *
 * Or les files « à encaisser » n'ont volontairement PAS de filtre de journée :
 * les incidents « ENCAISSEMENT-ROBUSTE » ont montré qu'un filtre de date rend
 * des commandes légitimes invisibles et donc non encaissables. Deux commandes
 * de jours différents portant le MÊME numéro court cohabitent donc à l'écran —
 * le rapport l'observe en vrai (`A0041` = 22/09 à 10,80 € ET 24/09 à 11,50 €).
 * Sans date visible, le caissier peut encaisser la mauvaise commande.
 *
 * Ce helper est la SEULE définition de la règle. Il a été extrait de
 * `PosComponent.shortcutDateBadge` (correctif du 2026-09-26) pour que l'écran
 * `/admin/encaissement` la partage au lieu d'en recopier une troisième version.
 *
 * Retourne '' pour une commande du jour (cas normal, aucun bruit visuel),
 * sinon « jj/mm ». Ne jette jamais : une donnée absente ou illisible rend ''.
 *
 * @param {{ created_at?: string|null }|null|undefined} order
 * @returns {string} '' ou 'jj/mm'
 */
export function queueNumberDateBadge(order) {
  const iso = order && order.created_at;
  if (!iso) return '';
  try {
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '';
    const today = new Date();
    const sameDay = d.getFullYear() === today.getFullYear()
      && d.getMonth() === today.getMonth()
      && d.getDate() === today.getDate();
    if (sameDay) return '';
    return d.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit' });
  } catch (_) {
    return '';
  }
}

export default queueNumberDateBadge;
