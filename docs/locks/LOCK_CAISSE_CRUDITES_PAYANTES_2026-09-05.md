# LOCK — Caisse : les crudités payantes s'affichent sous « Suppléments »

**Identifiant :** `LOCK_CAISSE_CRUDITES_PAYANTES_2026-09-05.md`
**Date :** 2026-09-05 · **Zone gelée touchée :** `public/js/pos-wizard.js` (STRICT no-touch)
**Gate propriétaire :** ☐ **NON CONTRESIGNÉE — à signer avant fusion en production.**
Demande à l'origine : « j'ai ajouté des suppléments, ça affiche un chiffre 90 alors que ça
n'a rien à voir ; [ça] s'affiche comme un supplément et pas comme une chose ».

---

## 1. Le symptôme

À la caisse, dans le composeur d'un sandwich, la section **« ➕ Suppléments »** propose
`Maïs`, `Olives` et `Poivrons cuits` à **+0,90 €** — alors que ce sont des **crudités**.
Le propriétaire lit « 90 » là où il attend une garniture.

Sur la **borne**, les mêmes trois articles s'affichent correctement **parmi les crudités**,
avec leur badge de prix.

## 2. La cause, mesurée

Les deux surfaces classent les extras par des règles **différentes**.

| Surface | Règle | Fichier |
| --- | --- | --- |
| Borne | `group_label === 'crudite'` | `resources/js/helpers/kioskExtrasPartition.js:118-121` |
| Caisse | `convert_price === 0` **ET** nom dans une liste blanche | `public/js/pos-wizard.js:3126-3128` |

La liste blanche de la caisse (`pos-wizard.js:3506-3513`) contient `salade`, `tomate`,
`oignon`, `crudite`, `legume`, `concombre`, `mais`, `carotte`, `poivron`, `laitue`,
`roquette`, `epinard`, `betterave`, `radis`, `celeri` — **et ne contient ni « cornichon »
ni « olive »**.

Deux conditions, deux façons d'échouer :

| Extra | Groupe | Prix | Pourquoi la caisse le rate |
| --- | --- | --- | --- |
| `Poivrons cuits` | `crudite` | 0,90 € | prix ≠ 0 |
| `Maïs` | `crudite` | 0,90 € | prix ≠ 0 |
| `Olives` | `crudite` | 0,90 € | prix ≠ 0 **et** nom absent de la liste |

**Mesure sur la base de production (2026-09-05)** :

```sql
SELECT COUNT(*) FROM item_extras
WHERE deleted_at IS NULL AND status = 5 AND group_label = 'crudite'
  AND NOT (price = 0 AND LOWER(name) REGEXP 'salade|tomate|oignon|crudite|legume|…');
-- 57 lignes sur 132 crudités actives (43 %), réparties sur 19 produits
```

Ces 57 lignes tombent dans le `else` final de la caisse et sont rendues en suppléments
(`pos-wizard.js:3148-3156`). Le cas frère est confirmé sur une **commande réelle**
(`order_items.id = 2250`) : la composition enregistrée porte « Supplément : Olives (+0,90 €) ».

## 3. Pourquoi ce n'est PAS une décision produit nouvelle

Le comportement attendu est **déjà un mandat écrit du propriétaire**, appliqué sur la borne
et documenté dans le code depuis un mois — `kioskExtrasPartition.js:113-117` :

> `[GOAL-8AXES V3 2026-08-05 owner] Les crudités PAYANTES (Poivrons cuits / Maïs / Olives
> 0,90 €, group_label='crudite') s'affichent « à côté des crudités » (étape Garnitures),
> pas dans Gourmands. Le prix > 0 reste porté par row.price → l'étape affiche le badge
> « +0,90 € » et le total/scellé les facture.`

Ce correctif **implémente ce mandat sur la caisse**, qui ne l'avait jamais reçu. Il ne crée
aucune règle nouvelle et ne change aucun prix.

## 4. Le changement, et son périmètre exact

`public/js/pos-wizard.js`, **une seule expression** (le filtre du bac « crudités ») :

```diff
- return extra.convert_price === 0 && isCruditeName(extra.name);
+ // Le GROUPE fait autorité (SSOT `item_extras.group_label`), comme sur la borne.
+ // Le nom et le prix ne restent qu'un repli pour les extras sans groupe.
+ var groupe = String(extra.group_label || '').toLowerCase();
+ if (groupe === 'crudite') return true;
+ if (groupe !== '') return false;
+ return extra.convert_price === 0 && isCruditeName(extra.name);
```

**Ce que ce correctif ne fait PAS**, et c'est l'essentiel :
- il ne modifie **aucun calcul de prix** — le total reste calculé par `PricingService`
  depuis la base, jamais depuis l'écran (NF525, CLAUDE.md §8) ;
- il ne change **aucun prix en base** : `Maïs`/`Olives`/`Poivrons cuits` restent à 0,90 € et
  restent **facturés** ;
- il ne retire **aucun** article de l'écran : les trois passent de la section
  « Suppléments » à la section « Crudités », avec leur badge de prix ;
- il ne touche ni la sauce, ni la viande, ni la formule, ni le paiement, ni le ticket.

**Un extra sans `group_label`** (52 lignes en production, à 1,00 €) garde exactement
l'ancien comportement : le repli nom+prix s'applique. C'est délibéré — sans groupe, il n'y a
pas de vérité à préférer.

## 5. Ce qui reste volontairement en dehors

- La **2ᵉ sauce** affichée comme supplément : ce n'est **pas** un défaut. Elle est facturée
  0,50 € via l'extra générique `Sauce supplémentaire` (groupe `sauce`), et le nom réel est
  ré-étiqueté « Sauce supplémentaire : Américaine » par `kdsSymbolic.js:240`. C'est bien un
  supplément payant, à sa place. Aucun changement.
- Le **découplage de `buildCartDisplay()`** (`pos-wizard.js:3026-3038` lit les noms via
  `steps`, la grille vient de `lastItemData.extras`) : périmètre étroit (Bol Frites, Bol Riz,
  catégorie Frites), symptôme distinct, **non traité ici** — il mériterait son propre LOCK.

## 6. Preuves exigées avant fusion

- Banc de régression prouvé **rouge sans le correctif**, vert avec, couvrant : crudité
  payante avec groupe, crudité gratuite avec groupe, extra sans groupe (repli inchangé),
  supplément payant (doit rester supplément).
- Suite Vitest complète verte.
- `menu:verifier-etapes` OK sur les trois surfaces.
- `fiscal:verify-chain --all` CHAIN OK avant et après.
- Empreinte SHA-256 de `pos-wizard.js` réalignée dans
  `tests/Feature/Sentinels/frozen-zone-sha256-baseline.json`, **dans le même commit**.

## 7. Retour arrière

`git revert` du commit de correctif, puis `npx mix --production`. Les trois crudités
repasseraient sous « Suppléments » — l'état d'aujourd'hui. Aucune donnée n'est modifiée,
donc aucune migration ni restauration de base n'est nécessaire.

## 8. Signature

- Rédigé par : Claude (session `01GrTqufiiQqapiru57DCYK5`), 2026-09-05.
- **Contresignature propriétaire : ☐** — requise par CLAUDE.md §10 (porte humaine) pour
  toute modification de `public/js/pos-wizard.js`, classé **STRICT no-touch** en
  CONSTITUTION §3.1.
