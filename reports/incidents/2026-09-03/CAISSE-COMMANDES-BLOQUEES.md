# Incident caisse — « les commandes ne passent pas » (2026-09-03)

Signalé en urgence par le propriétaire, capture à l'appui : au moment d'encaisser, la caisse
refusait avec « Composition : le choix #450 n'appartient pas au profil publié. » Ticket
construit, montant affiché (9,80 €), monnaie calculée (0,20 € sur 10 €) — paiement impossible.

**La caisse n'était pas morte** : les paniers SANS composition passaient (commande #919 à 19:39,
33,40 €, trois lignes sans extras). Ce sont certains paniers qui ne pouvaient pas être encaissés.

---

## Deux causes distinctes, superposées

### 1. Un groupe d'extras que le profil ne décrivait pas — correctif de code

Le wizard facture la 2ᵉ sauce 0,50 € via un extra « Sauce supplémentaire » qui appartient bien
à l'article (`LOCK_CAISSE_SAUCE_SEAL`, 2026-07-16), porteur du groupe `sauce`. Le profil publié
des Bol Frites ne décrit d'étape `extra_group` que pour `supplement_bol`. Aucune ne couvrait
donc le groupe `sauce`.

`PricingService::assertComposerSelectionsBelongToPublishedProfile()` n'autorisait que les choix
listés par les étapes projetées : sur le **même article**, « Suppléments » passait et la sauce
payante bloquait la vente. La différence tenait à l'existence d'une étape pour l'un et pas pour
l'autre.

**Correctif** (`6e2f038cd`, zone gelée sous `LOCK_INCIDENT_CAISSE_SAUCE_2026-09-03.md`) : le
profil contraint ce qu'il DÉCRIT ; ce qu'il ne décrit pas retombe sur la frontière du catalogue
— l'option doit appartenir à l'article, être active, visible sur la surface. Restent refusés,
inchangés : un choix appartenant à un AUTRE article (l'injection, le risque réel), un extra
désactivé ou masqué, et un choix retiré d'une étape décrite par le profil.

### 2. Une republication qui a appauvri deux profils — correctif de données

Cette nuit à 01:17, 36 profils ont été republiés. Deux d'entre eux **ont perdu des étapes** :

| Article | Profil retenu | Étapes | Profil précédent | Étapes |
| --- | --- | --- | --- | --- |
| Bol Frites | #64 (v18) | **2** | #8 (v17) | 4 |
| Bol Riz | #68 (v10) | **2** | #12 (v9) | 4 |

Disparues : « Choix de la viande » (7 choix) et « Boisson (optionnel) ». L'étape sauce pointait
en outre vers un autre attribut, si bien que les choix proposés par une caisse ouverte avant la
republication n'existaient plus dans le profil actif.

**Correctif** : `#64` et `#68` dépubliés (`is_published = 0`). Les profils complets `#8` et `#12`
reprennent la main, avec leurs quatre étapes.

---

## Vérifications, sur la production

- Sauvegarde du jour saine avant intervention : 1 981 603 o, archive gzip valide.
- **Chaîne fiscale CHAIN OK avant et après** les deux correctifs.
- Correctif de code confirmé **chargé en mémoire** (réflexion : méthode de repli présente,
  signature à 4 paramètres), pas seulement présent sur le disque.
- Encaissement rejoué sur les données réelles : Bol Frites + sauce du profil + sauce payante
  → **accepté**, sous-total 8,40 €.
- Balayage de tout le catalogue à profil publié : **31 articles testés, 0 bloqué par la
  composition**. Les 12 refus restants sont légitimes — 1 rupture déclarée (Bol Riz,
  `stock_rupture` depuis 00:00) et 11 articles désactivés du catalogue.
- `/login`, `/admin/pos`, `/api/health/live` : 200.

## Retour arrière

- Code : `git revert 6e2f038cd` (aucune migration).
- Données : `UPDATE item_wizard_profiles SET is_published = 1 WHERE id IN (64, 68);`

## À trancher à froid

La republication de cette nuit reste à refaire correctement pour Bol Frites et Bol Riz : leurs
profils v18/v10 étaient appauvris. Tant qu'ils sont dépubliés, les versions de juin font foi et
la caisse fonctionne — mais la prochaine republication rejouera le problème si la cause côté
composeur n'est pas comprise.

Deuxième point : une republication ne prévient pas les caisses ouvertes. Une caisse chargée
avant 01:17 proposait des choix qui n'existaient plus, et le calcul les refusait à juste titre.
Il manque une invalidation — ou, à défaut, un message qui dise à l'opérateur de recharger.
