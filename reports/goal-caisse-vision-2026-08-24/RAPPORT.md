# RAPPORT — GOAL CAISSE VISION (2026-08-24)

**Branche** `goal/caisse-vision-2026-08-24` · base `43b120c7d` (= HEAD de
`pos/category-first-caisse-2026-06-23`, == origin, == prod) · **non poussé**.

---

## 1. La demande, et ce qu'elle a révélé

> « faudrait pas juste voir le total, vaut mieux voir si je clique sur voir tout toute la
> liste … si j'ai un client devant moi, j'ai pas pris son nom, je peux voir ce qu'il a pris
> et toutes les personnalisations qu'il a fait … surtout la rapidité et l'interface. »

Ce n'était pas une préférence d'affichage : **c'était impossible**. Le serveur n'envoyait
au suivi de caisse que `item_id`, `item_name`, `quantity`, `instruction`
(`SimpleOrderResource.php:224-245`). Ni sauce, ni pain, ni cuisson, ni extras, ni
suppléments. Deux sandwichs identiques commandés différemment étaient indistinguables,
et voir le reste imposait un changement de page — depuis `/admin/pos-v4` un **rechargement
complet** (`pos-app.js:118-140` déclare ces routes en `window.location.assign`).

---

## 2. Sept défauts confirmés dans le code — aucun rapporté sur parole

| id | sév | Constat | Preuve | État |
|---|---|---|---|---|
| D1 | P0 | Le suivi n'expose aucune personnalisation | `SimpleOrderResource.php:224-245` | **CORRIGÉ** |
| D2 | P0 | Pas de « voir tout » : carte coupée à 3 lignes, `<li>` inerte | `PosOrdersTrackerComponent.vue:1995`, `:331` | **CORRIGÉ** |
| D3 | P1 | Suppléments de formule facturés + imprimés mais absents du détail caisse | `grep -c addon PosOrderShowComponent.vue` = **0** | **CORRIGÉ** |
| D4 | P1 | Commande **téléphone** indistinguable d'une vente au comptoir (🛒 « Caisse ») ; idem Uber, livraison | `sourceOf()` `:1944-1958` ne connaît ni `phone`, ni `uber_eats`, ni `delivery` | **CORRIGÉ** |
| D5 | P1 | Cuisine : extras invisibles — le gabarit lit `extra.name`, l'instantané porte `extra_name` | Sérialisation de la ligne réelle **#3956** : `extra_name='Salade'`, `extra.name=NULL` | **CORRIGÉ** (hors voie) |
| D6 | P2 | `item_name` null (article retiré du catalogue) → ligne muette | `SimpleOrderResource.php:237` | **CORRIGÉ** |
| D7 | P2 | Cadence réelle du suivi = 5 s / 12 req/min en permanence, pas 60 s ; aucune pause onglet caché | `:794,801,813,1406,1409-1419` ; pas de `visibilitychange` | **CONSIGNÉ** (porte G3) |

---

## 3. Ce qui a été livré

**Backend — la composition voyage, sans coûter une requête.**
`app/Support/Order/CompositionCompactor.php` : port fidèle du normaliseur canonique JS
(`posReceiptBuilder.js:146-190`), qui réconcilie les deux formes de stockage dont les rôles
sont **inversés** — instantané NF525 (`attribute_name` = libellé) et forme héritée
(`variation_name` = libellé). Les confondre produit des « undefined » : c'est le défaut déjà
corrigé côté ticket. Forme compacte, clés absentes quand vides, quantité omise quand elle
vaut 1, **aucun prix** (la caisse identifie un client ici ; le montant fait foi sur le ticket).

**Caisse — voir tout, sur place.** Composition résumée sous chaque produit ; bouton
« Voir tout » ouvrant le contenu intégral (toutes les lignes, tous les choix, tous les extras,
tous les suppléments, l'instruction jamais tronquée), **zéro appel réseau**, Échap pour
fermer, et un compte « 4 articles · 7 au total » pour dire de combien de « tout » il s'agit.
Le bouton n'apparaît que s'il y a réellement quelque chose de plus à voir.

**Canaux.** `phone`, `uber_eats`/`deliveroo`, `delivery` reçoivent pictogramme (📞 🛵 🚗) et
libellé FR. L'onglet de filtre d'un canal n'apparaît que si ce canal est présent sur le
tableau — la barre reste courte en service normal.

**Détail commande.** Les suppléments de formule passent par le même normaliseur que le
ticket : la fiche et le papier racontent désormais la même commande.

---

## 4. Preuves

**Tests**
- `tests/Feature/Pos` : **333 verts, 0 rouge** (88 s).
- `tests/Feature/Pos/TrackerCompositionPayloadTest.php` (**nouveau**, 8 cas) : les deux formes
  de stockage, la garde N+1 (`DB::listen` ⇒ **0** requête à la sérialisation), le budget
  d'octets, le rejet des identifiants nus, l'absence de clé vide.
- `tests/js/posTrackerCompositionVisible.spec.js` (**nouveau**, 17 cas), **éprouvé par mutation** :
  retrait du canal téléphone → **4 rouges** ; neutralisation du résumé → **3 rouges** ; 17 verts
  au rétablissement.
- `tests/js/kdsExtrasInstantaneNf525.spec.js` (**nouveau**, 6 cas) : importe la méthode RÉELLE du
  composant (pas une réplique inline) + garde de non-retour relisant le source.
- `tests/e2e/goal-caisse-vision-2026-08-24.spec.js` (**nouveau**, 4 cas) : bout en bout sur données
  réelles semées en base, dont la mise en page à **1366×768 et 1024×600** (aucun débordement
  horizontal, aucune composition hors carte).
- 7 specs de suivi existants : **64 verts**, inchangés.

**Performance — mesurée sur les données réelles, pas estimée** (100 commandes) :

| Contrainte GOAL §3 | Budget | Mesuré | |
|---|---|---|---|
| Requêtes SQL par tick | ≤ 8 | **6** | ✅ |
| Temps serveur | ≤ 100 ms | **64 ms** | ✅ |
| Payload / 100 commandes | ≤ 125 Ko | **105,7 Ko** | ✅ |
| Enrichissement moyen | ≤ 150 o/cmd | **52,8 o** | ✅ |
| Pire commande | ≤ 600 o | **394 o** | ✅ |
| `pos-request-budget.spec.js` | vert | **vert** | ✅ |

L'enrichissement coûte **0 requête SQL** : `item_variations`, `item_extras` et
`composition_snapshot` sont des colonnes de `order_items`, déjà rapatriées par le `select *`
existant — elles voyageaient jusqu'à PHP pour être jetées.

**Zones gelées §7** : `git diff --stat 43b120c7d..HEAD` sur les 15 fichiers → **0 ligne**.

**Visuel** : 5 captures dans `captures/`, **lues et analysées**, pas seulement prises. Un
défaut trouvé par cette lecture et corrigé : le titre du panneau se lisait
« #GCV24-COMPO— Admin » (le compilateur Vue supprime le nœud d'espace entre deux `<span>`).

---

## 5. Ce que j'ai touché en dehors de la demande, et pourquoi

**Le correctif cuisine (D5) est dans un commit séparé, étiqueté HORS VOIE CAISSE.**
Un extra perdu en cuisine, c'est un produit remis au client sans ce qu'il avait demandé.
Trop grave pour être seulement consigné — isolé pour pouvoir être annulé seul.

**Deux specs e2e réalignés sur le réel, sans être affaiblis :**
- `wave-s4` et `wave-q1` figeaient « 4 couloirs ». Le tableau en compte **cinq** depuis
  `131d79055` (2026-05-20), qui a inséré « EN LIVRAISON » — le jour même où ces specs étaient
  écrits. Ils échouaient donc en permanence **depuis trois mois**, occupant la place d'un
  garde-fou sans en être un.
- `wave-q1` attendait « Sandwich Cayenne » ; l'article #22 s'appelle **« Cayenne »**
  (vérifié en base).
- `wave-s4` exigeait « borne » avant « paiement » ; le libellé réel est « Paiement au comptoir
  (borne · caisse · tél · web) » depuis l'ajout du canal téléphone.

---

## 6. Ce que je n'ai PAS fait, et ce qui reste ouvert

- **D7 — cadence 5 s.** Le suivi consomme à lui seul tout le budget de repos, et ne se met pas
  en pause quand l'onglet est caché. La cause est identifiée précisément (`lastEventAt` n'est
  réarmé que par un event Echo livré ⇒ `eventsStale` vrai en permanence). **Non traité** : c'est
  un changement du contrat de synchro, zone partagée §6 — coordination requise. Porte **G3**.
- **`wave-s4` S-4.2 est instable** (passe au 2e essai) : il compte les cartes d'un couloir sur
  une base MySQL **partagée** avec d'autres sessions. Faiblesse d'isolation préexistante, non
  touchée ici — la corriger reviendrait à relâcher une assertion réelle.
- **Aucun push.** `CLAUDE.md §3quater` l'interdit sans accord explicite du propriétaire.
- **Porte G1 toujours ouverte** : validation sur le VRAI poste de caisse. Tout ce qui précède a
  été mesuré en local, sur les données réelles, mais pas sur le comptoir.

---

## 7. Commits

| SHA | Objet |
|---|---|
| `5b895b1f1` | feat(caisse) — le suivi montre ce que le client a pris, personnalisations comprises |
| `351cd33e6` | fix(caisse) — compte d'articles, espacement du titre, 2 specs périmés réalignés |
| `35c53efca` | fix(cuisine) — extras de l'instantané NF525 redeviennent lisibles **[HORS VOIE CAISSE]** |
