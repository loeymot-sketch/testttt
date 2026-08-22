# GOAL — CAISSE PARFAITE (Le Cayenne, 2026-08-22)

> Mission : amener **l'écran de caisse** à l'état production-parfait, en fermant les
> contradictions mesurées le 2026-08-22, sans jamais dégrader ce qui a été gagné les 14 et 19/08.
>
> Ce document est un **plan**, pas une exécution. Aucune ligne de code n'est modifiée par sa
> rédaction. Lancement : dire « lance le GOAL » (protocole §L).

---

## §0 — PRÉAMBULE

### §0.1 Décision sur l'arbre de travail

| Point | État vérifié le 2026-08-22 |
|---|---|
| Arbre local | **PROPRE** (`git status --short` → vide) |
| HEAD | `e1ef70887` |
| `origin/pos/category-first-caisse-2026-06-23` | `e1ef70887` (identique, relu par `git ls-remote`) |
| VPS `/var/www/lecayenne` | `e1ef70887`, déployé et vérifié, `git status` → **0 ligne** |

**Décision** : brancher `goal/caisse-parfaite-2026-08-22` depuis `e1ef70887`. Raison : ce GOAL
touche l'ergonomie visible par le personnel et attend deux arbitrages propriétaire (§G) ; il ne
doit pas s'accumuler sur la branche qui sert à déployer.

⚠️ **Si quelqu'un utilise un `git worktree`** : ne PAS symlinker `vendor/`. Un `vendor/` lié
résout `__DIR__` vers l'autre arbre, `App\` pointe sur le dépôt partagé, et **les modifications
du worktree ne sont jamais exécutées** — une suite verte n'y prouve rien. Liens durs
(`rsync --link-dest`), puis vérifier par `ReflectionClass::getFileName()`.
Réf. mémoire `uber_scan_titre_entier_et_vendor_symlink_2026-08-20`.

⚠️ **`.env.testing` est ignoré par git** (`.gitignore:14`). Sans lui sur un arbre neuf,
~336 rouges **fantômes**. Le copier AVANT de conclure quoi que ce soit d'une suite rouge.

### §0.2 Périmètre — 3 systèmes

| # | Système | Pourquoi il est dans ce GOAL |
|---|---|---|
| S1 | **CAISSE — écran d'accueil et ergonomie de saisie** | La consigne écrite dans le code n'est pas ce que l'écran fait (mesuré) |
| S2 | **CHAÎNE DU NOM CLIENT** (caisse → `OrderService` → KDS → ticket) | Le champ concerné par S1 alimente l'écran cuisine ; le déplacer sans preuve casserait le service |
| S3 | **CENTRAL / CATALOGUE — fermer la dérive du spec menu** | `menu:reset-le-cayenne` est bloqué par une garde ; le débloquer demande un arbitrage, pas du code |

Hors périmètre, explicitement : borne (kiosk), OSS, web/mobile standalone, cloud. Aucun de ces
systèmes n'est touché par les tâches ci-dessous.

**Écart assumé au gabarit du skill.** Il demande 3-4 sous-systèmes par système. S1 en a 3.
**S2 et S3 n'en ont qu'UN chacun**, et c'est délibéré : leur périmètre réel est étroit (une
chaîne de données de bout en bout ; une commande artisan et son arbitrage). Les découper en
trois pour respecter la forme aurait produit des sous-systèmes inventés — exactement ce que la
règle anti-fiction interdit. Mieux vaut un écart déclaré qu'un remplissage crédible.

### §0.3 Zones gelées concernées (CLAUDE.md §7)

| Fichier gelé | Touché par ce GOAL ? |
|---|---|
| `public/js/pos-wizard.js` · `public/css/pos-wizard.css` · `resources/views/admin-pos-v4.blade.php` | **NON** — strict no-touch absolu |
| `resources/js/components/admin/pos/PaymentComponent.vue` | **NON** |
| `resources/js/components/admin/pos/v5/PosV5TrancheRow.vue` | **NON** |
| `app/Services/Pricing/PricingService.php` · `app/Domain/Order/OrderStateMachine.php` | **NON** |
| `app/Services/Fiscal/*` · `BranchScope` · `IdempotencyKeyMiddleware` | **NON** |

**Vérifié** : les deux fichiers que S1 modifie —
`resources/js/components/admin/pos/PosComponent.vue` et `resources/css/pos-v5.css` — ne
figurent PAS dans la liste §7. Aucun `LOCK_*.md` n'est requis par ce GOAL. Si une tâche en vient
à en exiger un → **STOP + porte propriétaire**, invoquer le skill `lock-plan`.

### §0.4 Pipeline par tâche (référence unique, non répétée)

Chaque tâche `T-x.y.z` s'exécute via **`ultra-audit-profond`** (~/.claude/skills/ultra-audit-profond/).
Ce GOAL ne redécrit pas le pipeline en 14 étapes. Skills composés :
`superpowers:test-driven-development` (rouge d'abord) · `superpowers:systematic-debugging`
(tout défaut avant correctif) · `superpowers:dispatching-parallel-agents` (fan-out §A) ·
`test-e2e` (boucle visuelle adverse) · `verify-before-report` (porte anti-hallucination) ·
`lock-plan` (uniquement si une zone gelée devenait inévitable).

### §0.5 Note d'honnêteté — l'`advisor()` n'existe pas dans cette session

Le skill demande un appel `advisor()` avant écriture. **Aucun outil de ce nom n'est disponible
ici.** Je ne prétends donc pas l'avoir appelé. Il a été remplacé par une **passe d'ancrage
exhaustive**, dont la sortie réelle est reproduite en §1.3 : chaque chemin cité dans ce document
provient d'un `find`/`grep`/`ls` exécuté le 2026-08-22, ou porte la mention explicite
`(À CRÉER)`.

### §0.6 Critères de convergence de CE GOAL (mesurables, pas d'opinion)

Le GOAL est DONE quand **les six** sont vrais, chiffres à l'appui :

| # | Critère | Mesure | Seuil |
|---|---|---|---|
| C1 | La grille des catégories est atteignable sans défiler | `y(grille) + hauteur(1re rangée) ≤ hauteur fenêtre`, à 1366×768 | **VRAI** |
| C2 | Aucun contrôle de saisie coupé dans l'en-tête du panier | nb d'`input`/`button` dont `bottom > clientHeight` du conteneur | **0** |
| C3 | Le gain du 19/08 n'est pas repris | `hauteur(.pos-v5-cart__body) ≥ 20vh` ET pied jamais rogné | **VRAI** |
| C4 | Le nom client atteint toujours la cuisine | test nommé en §4 | **VERT** |
| C5 | Aucune régression | `tests/Feature` complet + `tests/js` complet | **8 échecs préexistants, pas 9** |
| C6 | Zones gelées | `git diff --stat <base>..HEAD -- <§7>` | **0 ligne** |

Et les règles de rejet de l'Axe 6 du skill s'appliquent littéralement : un libellé brut visible,
une erreur console, une rupture de mise en page sur un seul gabarit testé, un P0 RED non traité
⇒ **REJET**, soin, recapture. « Presque bon » = rejet.

**Convergence = deux cycles consécutifs avec P0+P1 = 0 ET ensembles de constats IDENTIQUES.**
Deux cycles aux constats différents ⇒ pas convergé, on reboucle (garde anti-instabilité).

---

## §1 — CARTE DES SYSTÈMES

### §1.1 Maturité mesurée

| Système | Maturité | Preuve |
|---|---|---|
| S1 Caisse (UI accueil) | **DÉRIVÉE** | 53 % de la page visible à 1366×768 ; grille à `y=792` sur un écran de 768 |
| S2 Chaîne nom client | **FONCTIONNELLE, mais l'entrée est à moitié cachée** | chemin de données vérifié `OrderService.php:847` → `KitchenDisplaySystemOrderService.php:318` |
| S3 Catalogue / spec menu | **BLOQUÉE VOLONTAIREMENT** | garde livrée le 2026-08-22, sortie 2, vérifiée EN PRODUCTION |

### §1.2 Mesures de référence (à rejouer telles quelles en W1)

Prises le 2026-08-22, navigateur réel, `/admin/pos`, compte `pos@lecayenne.fr`, `zoom: 0.9` :

| Gabarit | `main` visible | à défiler | en-tête panier caché |
|---|---|---|---|
| 1366×768 (portable comptoir) | **53 %** (768/1455) | **687 px** | **214 px** (152 visibles sur 366) |
| 1280×800 (tablette paysage) | 55 % (800/1455) | 655 px | 207 px |
| 1024×768 (vieux poste) | **48 %** (768/1587) | 819 px | 214 px |
| 1920×1080 (grand écran) | 97 % (1080/1116) | 36 px | 43 px |

Budget vertical de la colonne gauche à 1366×768 :
`en-tête « Commande rapide » 209 px` + `bloc pos-shortcuts (4 panneaux de suivi) 432 px`
+ `recherche 15 px` → **grille des catégories : y = 792, hauteur 475 px**.
La grille commence **24 px sous le bord bas de l'écran**.

Données de production (relevées sur le VPS le 2026-08-22) : **57 articles actifs, 9 catégories
actives** — identiques à la base locale. La mise en page mesurée est donc bien celle du comptoir,
pas un artefact de bac d'essai.

### §1.3 Ancrages VÉRIFIÉS (sortie réelle des commandes, 2026-08-22)

```
resources/js/components/admin/pos/PosComponent.vue      7274 lignes
  :405   data-testid="pos-shortcuts"          ← les 4 panneaux de suivi
  :798   data-testid="pos-category-grid"      ← la grille, rendue APRÈS
  :789   commentaire « Owner direction: the first POS screen shows the categories »
  :893   <header class="pos-v5-cart__head pos-v4-cart-head flex-shrink-0">
  :1049  <!-- [C2-CAISSE 2026-07-05] Nom du client (optionnel) -->
  :1058  label 'Nom du client (imprimé sur le ticket cuisine)'
  :1117  placeholder="Nom du client"
  :1251  <div class="pos-v5-cart__body flex-1 min-h-0 overflow-y-auto ...">
  :1377  <footer class="pos-v5-cart__foot pos-v4-cart-footer flex-shrink-0">
  :2792  computed posBrowseMode        :2799  showCategoryGrid === 'categories'

resources/css/pos-v5.css
  :715   .pos-v5-cart__head { ... }
  :766   bloc [T-PANIER-40PX 2026-08-19] — la mesure d'origine (head 404 / body 40 / foot 394)
  :783   .pos-v5-cart .pos-v5-cart__head { flex:0 1 auto; max-height:30vh; overflow-y:auto }
  :805   exception livraison :has(#orderdelivery:not(.hidden))
  :829   @media (max-height: 800px) → head max-height:20vh ; body min-height:20vh

resources/css/app.css:320   .db-main { ... h-screen overflow-auto ... }

app/Http/Controllers/Admin/  PosController.php · AdminPosV4Controller.php ·
                              PosOrderController.php · PosCategoryController.php
app/Services/OrderService.php:847-848                  pos_customer_name (saisie → commande)
app/Services/KitchenDisplaySystemOrderService.php:293  select ... 'pos_customer_name'
app/Services/KitchenDisplaySystemOrderService.php:318  'customer_name' => $order->pos_customer_name ?: $order->user?->name

app/Console/Commands/MenuResetLeCayenneCommand.php     garde catalogueDriftReport() + EXIT 2
app/Console/Commands/AddSandwichClassiqueCommand.php   cloneAddons()

tests/Feature/Pos/            60 fichiers
tests/Feature/Menu/           33 fichiers (dont MenuResetDriftGuardTest.php, SandwichClassiqueCloneAddonsTest.php)
tests/Feature/Hardware/       dont KioskKitchenTicketTest.php
tests/js/sentinels/           58 fichiers  (413 cas verts au 2026-08-22)
tests/js/posBrowseView.spec.js · posCart.spec.js · posA11y.spec.js   (existants, vérifiés par ls)
tests/e2e/                    55 specs contenant « pos » ou « caisse »
```

### §1.4 Systèmes séparés — sans objet

Aucun système standalone (mobile RN, web `/Users/1millnonstop/Downloads/web`) n'est dans le
périmètre. **Ne pas les câbler** : mandat propriétaire V1, `CONSTITUTION.md §4`.

---

## §2 — SYSTÈME S1 : CAISSE, ÉCRAN D'ACCUEIL ET ERGONOMIE DE SAISIE

### Contrat
Le comptoir prend une commande en un minimum de gestes, voit son ticket en cours en entier, et
n'a jamais à chercher un contrôle. La consigne propriétaire inscrite dans le code
(`PosComponent.vue:789`) : **le premier écran montre les catégories**.

### Le défaut, formulé sans interprétation
La grille des catégories est rendue **après** trois blocs qui totalisent 641 px, sur un écran de
768. Ce n'est l'erreur de personne en particulier : le hub catégories date du 2026-06-23, le
panneau « Commandes web » du 2026-07-13, « Web payées » du 2026-08-10 (P0 propriétaire). Chacun
a été inséré au-dessus, pour une bonne raison. **Personne n'a mesuré le cumul.**

---

### Sub 1.1 — Budget vertical de l'écran d'accueil

**Ancrages** : `PosComponent.vue:405` (`pos-shortcuts`), `:798` (`pos-category-grid`), `:789`
(consigne écrite), `resources/css/app.css:320` (`.db-main`, `h-screen overflow-auto`).

**Tâches**

- **T-1.1.1** Figer la mesure de référence dans un test, AVANT tout correctif.
  • anchor : `PosComponent.vue:405,798`
  • test : **(À CRÉER)** `tests/js/sentinels/posLandingCategoryGridAboveFoldSentinel.spec.js`
  • forme : épreuve de **caractérisation** — verte aujourd'hui parce que la grille est sous la
    ligne, ROUGE le jour où elle remonte. Elle sera inversée par T-1.1.3, dans le même commit,
    avec le commentaire qui explique le retournement.
  • ⚠️ interdit : `markTestSkipped` — une épreuve suspendue ne peut ni passer ni échouer.

- **T-1.1.2** Décider de l'option retenue — **PORTE PROPRIÉTAIRE G1** (§G). Trois options
  chiffrées, rien à inventer :
  | Option | Geste | Grille à | Effet mesuré |
  |---|---|---|---|
  | **A** (recommandée) | Replier les 4 panneaux par défaut (compteurs seuls, dépliables) | y ≈ 360 | récupère 432 px ; 2 rangées de tuiles visibles ; panneaux toujours accessibles, et les raccourcis « Suivi commandes » / « À encaisser » sont déjà en haut |
  | **B** | Monter la grille au-dessus des panneaux | y ≈ 224 | fidèle à la consigne écrite ; les panneaux passent sous la ligne |
  | **C** | Grille collante (sticky) | y = 0 | la grille ne quitte jamais l'écran ; réduit la place du reste |
  Aucune n'est appliquée sans G1.

- **T-1.1.3** Implémenter l'option retenue, scope-minimal.
  • anchor : `PosComponent.vue` (ordre du template) et/ou `resources/css/pos-v5.css`
  • test : inverser `posLandingCategoryGridAboveFoldSentinel.spec.js` (T-1.1.1)
  • visual : `http://127.0.0.1:8766/admin/pos` aux **4 gabarits** de §1.2
  • acceptance : **C1 vrai** — `y(grille) + hauteur(1re rangée) ≤ 768` à 1366×768, mesuré au
    navigateur et écrit dans le rapport, pas estimé.

- **T-1.1.4** Non-régression des panneaux de suivi : les 4 panneaux gardent leur fonction.
  • anchor : `PosComponent.vue:405-670`
  • test : `tests/js/posBrowseView.spec.js` (existant) + **(À CRÉER)**
    `tests/js/posLandingPanelsStillReachable.spec.js`
  • acceptance : les 4 compteurs restent lisibles sans geste ; « Web payées » (P0 du 10/08)
    reste visible sans dépliage — un P0 propriétaire ne se replie pas en silence.

**Acceptance Sub 1.1** : C1 VRAI aux 4 gabarits · `posBrowseView.spec.js` VERT ·
2 sentinelles neuves VERTES · captures relues (pas seulement prises).

---

### Sub 1.2 — En-tête du panier : ce que le compromis du 19/08 a coûté

**Ancrages** : `pos-v5.css:766-836` (le bloc qui documente déjà `head 404 / body 40 / foot 394`),
`PosComponent.vue:893` (en-tête), `:1049-1117` (champ nom client), `:1251` (corps), `:1377` (pied).

**⚠️ À LIRE AVANT DE TOUCHER** : le rognage **n'est pas un bug**. C'est un correctif mesuré du
2026-08-19 : avant lui, en-tête et pied étaient tous deux incompressibles (798 px fixes) et le
panier n'avait plus que **40 px** — le caissier lisait sa vente par un hublot. Ma mesure du
2026-08-22 (152 px = 20 vh à 768) confirme que la règle s'applique exactement comme écrite.
**Toute tâche qui reprendrait ces 214 px à l'en-tête sans rendre les 40 px au corps est un
retour en arrière déguisé. C6/C3 la rejettent.**

Le vrai problème est ailleurs : **ce qui est tombé dans les 214 px cachés**. La justification
écrite dit que l'en-tête « porte des réglages consultés au début de la vente, pas pendant la
saisie ». Vrai pour le type de commande. **Faux pour le nom du client** : le travail fidélité du
2026-08-14 l'avait précisément déplacé DANS le flux de vente (« rattacher le client PENDANT la
vente »). Deux décisions justes, à cinq jours d'écart, qui se contredisent — et aucun test ne
peut l'attraper, puisque les deux fonctionnent comme prévu.

**Tâches**

- **T-1.2.1** Mesurer et nommer ce qui est caché à 768 px.
  • anchor : `PosComponent.vue:893..1250`
  • test : **(À CRÉER)** `tests/js/sentinels/posCartHeadHiddenControlsSentinel.spec.js`
  • acceptance : la liste des contrôles dont `bottom > clientHeight` est produite et versionnée
    dans le rapport de vague. Attendu aujourd'hui : « Mettre en attente », « Nom du client »,
    « Téléphone », « Programmer ».

- **T-1.2.2** Sortir le champ nom/téléphone de l'en-tête compressible vers le corps du panier.
  • anchor : de `PosComponent.vue:1049-1117` vers `:1251` (`.pos-v5-cart__body`)
  • test : `tests/js/posCart.spec.js` (existant) + la sentinelle T-1.2.1 (qui doit alors ne plus
    lister le champ nom)
  • acceptance : **C2 = 0** contrôle de saisie coupé · **C3 préservé** (corps ≥ 20 vh, pied
    intact) · le champ reste dans le même formulaire et poste toujours `pos_customer_name`
    (prouvé par S2, T-2.1.1).
  • ⚠️ **Piège connu, à ne pas rejouer** : l'exception `:has(#orderdelivery:not(.hidden))` de
    `pos-v5.css:805` existe parce que la liste de suggestions d'adresse est en `position:absolute`
    et **ne s'échappe jamais d'un ancêtre qui défile**. Si le champ nom déménage, vérifier qu'aucun
    élément positionné ne se retrouve enfermé dans un nouveau conteneur `overflow`.

- **T-1.2.3** Affordance : un contrôle à moitié visible se lit comme un bug d'affichage.
  • anchor : `pos-v5.css:783-800`
  • test : **(À CRÉER)** `tests/js/posCartHeadScrollAffordance.spec.js`
  • acceptance : quand l'en-tête déborde, un indice visuel permanent le dit (ombre de bord ou
    barre visible) — pas une barre qui n'apparaît qu'au survol.

- **T-1.2.4** Non-régression du gain du 19/08, explicitement.
  • test : **(À CRÉER)** `tests/js/sentinels/posCartBodyFloorSentinel.spec.js`
  • acceptance : à 1366×768 ET 1024×600, `hauteur(.pos-v5-cart__body) ≥ 20 vh` et le bouton
    d'encaissement du pied est **entièrement** dans l'écran. C'est la sentinelle qui empêche ce
    GOAL de défaire le précédent.

**Acceptance Sub 1.2** : C2 = 0 · C3 VRAI · `posCart.spec.js` VERT · 3 sentinelles neuves VERTES.

---

### Sub 1.3 — Cibles tactiles, contraste, clavier

**Ancrages** : `tests/js/posA11y.spec.js` (existant), `PosComponent.vue` (boutons d'action),
`pos-v5.css`.

**Mesure de départ** : 28 boutons visibles ont une hauteur < 44 px à 1366×768 (dont les
raccourcis « À encaisser », les boutons « Cuisine » / « Client » des lignes de commande). Les
tuiles de catégories, elles, sont généreuses (~207 × 160 px) — cette partie est bien faite.

**Tâches**

- **T-1.3.1** Établir si le poste du comptoir est **tactile ou souris+clavier** — **PORTE
  PROPRIÉTAIRE G4**. Sans cette réponse, « 44 px » est un dogme, pas un critère. On ne corrige
  rien avant.
- **T-1.3.2** (conditionnel à G4 = tactile) Porter à ≥ 44 px les cibles des actions **répétées
  en service** uniquement : encaisser, accepter une commande web, ouvrir tiroir. Pas les
  réglages consultés une fois.
  • test : `tests/js/posA11y.spec.js` (existant, à étendre)
- **T-1.3.3** Vérifier le parcours clavier complet d'une vente sans souris.
  • test : **(À CRÉER)** `tests/js/posKeyboardOrderPath.spec.js`
  • acceptance : catégorie → produit → panier → encaisser, atteignable au clavier, focus visible
    à chaque étape.
- **T-1.3.4** Contraste WCAG 2.1 AA sur les états d'alerte de la caisse (bandeau rupture, badge
  « Caisse ⚠️ »).
  • test : rapport axe-core joint au rapport de vague (`§A` rôle UX/A11y)

**Acceptance Sub 1.3** : `posA11y.spec.js` VERT · rapport axe-core sans violation sérieuse ·
parcours clavier prouvé.

---

## §3 — SYSTÈME S2 : CHAÎNE DU NOM CLIENT (transverse)

### Contrat
Le nom saisi au comptoir doit arriver **intact** sur l'écran cuisine et sur le ticket imprimé.
S1 déplace le champ ; ce système est la preuve que le déplacement ne casse rien.

### Ancrages (chemin de données VÉRIFIÉ, pas supposé)
```
PosComponent.vue:1058,1117   saisie (libellé + champ)
OrderService.php:847-848     pos_customer_name, tronqué à 60 caractères
KitchenDisplaySystemOrderService.php:293   select ... 'pos_customer_name'
KitchenDisplaySystemOrderService.php:318   'customer_name' => $order->pos_customer_name ?: $order->user?->name
```

### Sub 2.1 — Le chemin bout en bout

- **T-2.1.1** Verrouiller le chemin AVANT le déplacement du champ (S1 T-1.2.2 en dépend).
  • test : **(À CRÉER)** `tests/Feature/Pos/PosCustomerNameReachesKitchenTest.php`
  • acceptance : une commande caisse portant `pos_customer_name` ressort avec ce nom dans la
    charge utile KDS. **Ce test doit être VERT avant T-1.2.2 et rester VERT après.**
- **T-2.1.2** Le repli quand le champ est vide.
  • anchor : `KitchenDisplaySystemOrderService.php:318` (`?: $order->user?->name`)
  • test : même fichier, cas dédié
  • acceptance : champ vide ⇒ nom du compte client s'il existe, sinon rien — **jamais** une
    chaîne « null » ni « undefined » à l'écran cuisine.
- **T-2.1.3** La troncature à 60 caractères ne coupe pas au milieu d'un caractère accentué.
  • anchor : `OrderService.php:848` (`mb_substr`)
  • test : même fichier, cas dédié avec un prénom accentué de 60+ caractères
- **T-2.1.4** Le ticket cuisine IMPRIMÉ porte le nom.
  • anchor : `app/Listeners/AutoPrintKitchenTicketOnKitchenEntry.php`
  • test : `tests/Feature/Hardware/KioskKitchenTicketTest.php` (existant) — à étendre, ou
    **(À CRÉER)** `tests/Feature/Hardware/KitchenTicketCustomerNameTest.php`
  • ⚠️ **La production ne peut PAS joindre les imprimantes** (hébergeur ≠ LAN restaurant) :
    l'impression passe par un pont qui RÉCLAME depuis le poste (caisse `127.0.0.1:9100`, cuisine
    `9101`). Le test valide la **charge utile ESC/POS**, pas une impression réelle.

**Acceptance S2** : **C4 VERT** · les 4 cas passent AVANT et APRÈS le déplacement du champ.

---

## §4 — SYSTÈME S3 : CENTRAL / CATALOGUE — FERMER LA DÉRIVE DU SPEC MENU

### Contrat
`menu:reset-le-cayenne` doit soit refléter la carte réellement vendue, soit rester bloqué. Il ne
doit jamais créer de doublon en caisse.

### État vérifié EN PRODUCTION le 2026-08-22
La garde livrée le jour même y trouve, sur la vraie base :
- **2 articles ACTIFS nommés « Sandwich Classique »** en puissance : `#119` (`sandwich-classique`)
  à **7,40 €** et `#25` (`sandwich-classique-faluche`) à **6,50 €** que le spec ressusciterait ;
- **7 produits retirés de la vente** qui reviendraient (5 bols supprimés le 2026-05-28,
  `big-tacos-2-viandes`, `sandwich-classique-faluche`) ;
- **2 articles créés de toutes pièces** : « Sandwich Cayenne » à **7,00 €** face au vrai
  `cayenne` **#22 à 7,40 €**, et « Tacos » à 8,50 €.

### Sub 3.1 — L'arbitrage, puis seulement le code

- **T-3.1.1** **PORTE PROPRIÉTAIRE G2** : quel article est le vrai « Sandwich Cayenne » ?
  `cayenne` #22 à 7,40 € (vendu aujourd'hui) ou `sandwich-cayenne-classique` à 7,00 € (du spec,
  inexistant en base) ? **Aucun code avant cette réponse** — décider à la place du propriétaire,
  c'est redéfinir la carte (CLAUDE.md §10, correction métier incertaine).
- **T-3.1.2** (après G2) Aligner `SPEC_ITEMS` et les payloads de `step9CreateNewItems()` sur la
  décision.
  • anchor : `MenuResetLeCayenneCommand.php` (const `SPEC_ITEMS` + step 9)
  • test : `tests/Feature/Menu/MenuResetDriftGuardTest.php` (existant, 8 cas) — le cas
    `test_spec_items_couvre_tous_les_articles_ecrits_par_step9` échoue si les deux divergent
  • acceptance : `php artisan menu:reset-le-cayenne --dry-run` affiche
    « catalogue conforme au spec — aucune dérive », sortie 0.
- **T-3.1.3** Décider du sort des 7 produits retirés : archivés pour de bon, ou remis à la carte ?
  • test : `MenuResetDriftGuardTest::test_un_produit_retire_de_la_vente_ne_ressuscite_pas_en_silence`
  • acceptance : quel que soit le choix, il est **écrit** dans `PROJECT_BRAIN.md §6 DECISIONS LOG`.
- **T-3.1.4** Aligner la prose de la commande sur les prix réels.
  • anchor : `MenuResetLeCayenneCommand.php:214-215` (« Sandwich Cayenne 7.00€ »)
  • ⚠️ Piège documenté : « Cayenne » est un **sous-texte** de « Galette Cayenne » — un
    chercher/remplacer naïf casse les deux. Et l'espace **insécable** avant « € ».

**Acceptance S3** : dry-run sans dérive OU garde toujours active avec la raison écrite au BRAIN ·
`MenuResetDriftGuardTest` 8/8 VERT · aucune écriture en base par ce GOAL.

---

## §A — CARTE DES COMPÉTENCES (armée d'agents)

Rôles, types et gabarits de brief : `~/.claude/skills/ultra-architect-planify/SKILL.md` Axe 4.
Ce GOAL ne les recopie pas ; il déclare **qui tire sur quoi**.

### Matrice de tir par type de tâche

| Tâche | Architecte | Sécurité | UX/A11y | DBA | SRE | Implémenteur | RED | QA Visuel | RED Visuel |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| S1 (UI Vue/CSS) | x | . | **x** | . | . | x | x | **x** | **x** |
| S2 (service + ticket) | x | x | . | x | . | x | x | . | . |
| S3 (commande catalogue) | x | . | . | **x** | . | x | x | . | . |

`x` = tir obligatoire · `.` = sauf déclencheur.

### Discipline de dispatch (non négociable)
- Les spécialistes **lecture seule** partent dans **UN SEUL message**, en parallèle. Voies
  disjointes, aucun état partagé, chaque brief = but + contraintes + livrable + format de retour.
- **Un seul implémenteur à la fois.** Jamais deux en parallèle : conflit d'écriture.
- **QA Visuel et RED Visuel en parallèle**, sur les mêmes captures, **sans se parler**. Le RED
  Visuel relit les captures du QA de façon indépendante et conteste. Sauter ce croisement, c'est
  garder le biais de confirmation du premier agent.
- **RED-team dispute TOUJOURS après le commit de l'implémenteur, AVANT de déclarer DONE.**
- Tout constat sans `file:line` vérifié par `grep`/`Read` est **REJETÉ**, pas remonté.

### Contrat de rapport
Chaque agent écrit sur disque —
`reports/test-e2e/caisse-parfaite-2026-08-22/<vague>/<role>.json` — et le fil principal
synthétise **depuis le disque**, pas depuis le contexte : c'est ce qui survit à une coupure.
Format par constat : `[P0|P1|P2|P3] <fichier>:<ligne> — <titre>` + `reproduction:` +
`evidence:` + `recommendation:`. Plafond ~1200 mots par agent.

---

## §X — VAGUES

Défaut : **séquentiel**, avec fan-out parallèle **à l'intérieur** de la phase d'audit.

### Vague 1 — Pré-vol et lignes de base
- Créer `goal/caisse-parfaite-2026-08-22` depuis `e1ef70887`. Instantané SQL avant toute chose.
- Rejouer les mesures de §1.2 aux 4 gabarits → elles doivent **reproduire** les chiffres publiés.
  Si elles divergent, ce GOAL repose sur du sable : **STOP**, on remesure avant tout.
- Lignes de base : `tests/Feature` complet (attendu **4840 / 36 / 8**), `tests/js` complet,
  `audit_logs` count + `MAX(current_hash)`.
- **Parallélisme** : aucun. **Checkpoint** : les 4 mesures reproduites, baselines écrites.

### Vague 2 — S1 audit (lecture seule)
- Fan-out **Architecte + UX/A11y + RED** en un message sur Sub 1.1/1.2/1.3.
- **Parallélisme** : 3 agents lecture seule. **Checkpoint** : rapports sur disque, `verify-before-report`
  passé (chaque `file:line` regreppé), P0/P1 classés.

### Vague 3 — S1 implémentation (bloquée par G1)
- T-1.1.1 → T-1.1.4, puis T-1.2.1 → T-1.2.4, puis Sub 1.3 (T-1.3.2 bloquée par G4).
- **Parallélisme** : implémenteur **seul**. QA Visuel ‖ RED Visuel après commit.
- **Checkpoint** : C1, C2, C3 VRAIS aux 4 gabarits, captures **relues** (Read), 0 erreur console.

### Vague 4 — S2 chaîne du nom client
- T-2.1.1 doit être VERT **avant** T-1.2.2 (ordre imposé, pas indicatif).
- **Parallélisme** : aucun. **Checkpoint** : C4 VERT avant ET après le déplacement.

### Vague 5 — S3 catalogue (bloquée par G2)
- **Voies disjointes de S1/S2** (commande artisan + tests Feature/Menu) ⇒ **peut tourner en
  parallèle des vagues 2–4** si et seulement si G2 est répondue et qu'aucun implémenteur ne
  tourne ailleurs au même moment.
- **Checkpoint** : dry-run sans dérive OU raison écrite au BRAIN ; 8/8 VERT.

### Vague 6 — Convergence finale
- Suite complète PHPUnit + Vitest + Playwright sur les surfaces touchées.
- E2E transverse : caisse → commande → **écran cuisine** (le nom client y est lisible).
- `git diff --stat <base>..HEAD -- <§7>` = **0 ligne**. `fiscal:verify-chain --all` = CHAIN OK.
- Deux cycles consécutifs aux constats **identiques** avec P0+P1 = 0.
- BRAIN §2/§3/§4 + `chore(brain): seal`. **Aucun push sans accord explicite propriétaire.**

### Protocole de coupure (limite d'usage, fin de session)
1. Committer le partiel — `wip(vague-N): partiel jusqu'à T-x.y.z`. Un WIP vaut mieux qu'un état perdu.
2. Écrire `reports/test-e2e/caisse-parfaite-2026-08-22/INTERRUPT_<vague>_<horodatage>.md` :
   dernier commit vert, dernière tâche + statut, tâche suivante, rapports d'agents déjà sur disque.
3. Mettre à jour `PROJECT_BRAIN.md §2` avec l'état d'interruption.
4. À la reprise : lire le manifeste, `git status`, rejouer la dernière tâche en fumée, puis continuer.

### Protocole de non-convergence (3 boucles sur le même problème)
STOP à la 3ᵉ. Écrire `STUCK_<vague>_<horodatage>.md`. Remonter au propriétaire avec quatre
options : A) accepter avec documentation, B) pivot d'architecture, C) reporter en V1.0.X,
D) porte humaine. **Ne pas choisir à sa place.**

---

## §G — PORTES PROPRIÉTAIRE

| Porte | Ce qu'il faut décider | QUI | QUOI (l'artefact qui débloque) | OÙ (la preuve) | Bloque | Statut |
|---|---|---|---|---|---|---|
| **G1** | Option d'accueil caisse : **A** (replier les panneaux, recommandée) · **B** (grille au-dessus) · **C** (grille collante) | Propriétaire | Réponse écrite « A », « B » ou « C » | `PROJECT_BRAIN.md §6 DECISIONS LOG` + message de commit de T-1.1.3 | Vague 3 | **EN ATTENTE** |
| **G2** | Identité du « Sandwich Cayenne » : `cayenne` #22 à 7,40 € ou `sandwich-cayenne-classique` à 7,00 € ? Et sort des 7 produits retirés | Propriétaire | Réponse écrite, article par article | `PROJECT_BRAIN.md §6` + `MenuResetDriftGuardTest` | Vague 5 | **EN ATTENTE** |
| **G3** | Inventaire d'ouverture des 13 matières (toutes à `on_hand` négatif : ce sont des CUMULS DE SORTIES, pas des quantités en réserve) | Propriétaire (physique, compte le stock) | Quantités de départ saisies | table `raw_material_stocks` + note BRAIN §2 | rien dans ce GOAL — **ouvert depuis le 19/08** | **EN ATTENTE** |
| **G4** | Le poste du comptoir est-il **tactile** ou **souris+clavier** ? | Propriétaire | Réponse + photo/modèle du poste | `PROJECT_BRAIN.md §2` | T-1.3.2 seulement | **EN ATTENTE** |
| **G5** | Validation visuelle finale **sur le vrai poste du comptoir**, pas sur un simulateur | Propriétaire | « c'est bon » après une vente réelle de bout en bout | rapport de vague 6 | clôture du GOAL | **EN ATTENTE** |

**Pendant qu'une porte est EN ATTENTE** : n'exécuter aucune vague qui en dépend, exécuter celles
qui n'en dépendent pas, et écrire dans BRAIN §2 lesquelles tournent et lesquelles sont bloquées.
Concrètement : **les vagues 1, 2 et 4 peuvent démarrer immédiatement**, sans aucune porte.

---

## §R — RÉFÉRENCES

**Skills** : `ultra-audit-profond` (pipeline par tâche) · `superpower-gstack` (LOOP par tâche) ·
`test-e2e` (boucle visuelle adverse) · `verify-before-report` (porte anti-hallucination) ·
`superpowers:test-driven-development` · `superpowers:systematic-debugging` ·
`superpowers:dispatching-parallel-agents` · `lock-plan` (si une zone gelée devenait inévitable).

**Documents projet** : `CONSTITUTION.md` (lue en premier) · `CLAUDE.md` §§5-13 ·
`PROJECT_BRAIN.md §2` · `SYSTEM_MAP.md` · `SYNC_CONTRACT.md` · `PARALLEL_PROTOCOL.md`.

**Mémoire à relire avant d'agir** :
`arbre_sale_tests_qui_ecrivent_et_gitignore_inerte_2026-08-22` (un test réécrit un document
owner ; `commit --only` ré-ajoute ; `assertExitCode(1)` ne distingue pas « bloqué » de
« planté ») · `caisse_kds_zoom_teleport_arrondi_2026-08-21` (`100dvh` sous un `zoom`,
`Teleport` qui casse Vue) · `goal_terrain_caisse_cuisine_2026-08-19` (panier écrasé à 40 px ;
`overflow-x` non déclaré devient `auto`) · `page_blanche_vendor_recompile_seul_2026-08-19` ·
`suite_sqlite_memoire_pas_mysql_2026-08-20` (aucune épreuve de CONCURRENCE n'est écrivable dans
cette suite — structurel).

---

## §F — RÈGLE FINALE

Ce GOAL est terminé quand **C1 à C6 sont vrais avec leurs chiffres**, que deux cycles
consécutifs rendent des constats **identiques** avec P0 + P1 = 0, et que la porte **G5** est
signée après une vente réelle au comptoir.

Trois choses qu'aucune pression ne rachète :

1. **Ne pas reprendre au panier les pixels rendus le 19/08.** Le hublot de 40 px était le défaut ;
   le rognage de l'en-tête est le prix payé pour le fermer. Ce GOAL déplace ce qui souffre du
   prix — il ne réclame pas un remboursement.
2. **Ne pas décider à la place du propriétaire** sur G1 et G2. Réordonner l'écran de caisse et
   redéfinir la carte sont des choix produit. L'outil mesure et propose ; il n'arbitre pas.
3. **Une capture prise n'est pas une capture lue.** Chaque preuve visuelle passe par le tool
   `Read` et par une analyse écrite. Un test vert ne prouve pas qu'un écran est correct — c'est
   la raison d'être du mandat visuel (CLAUDE.md §6).

Partiel vaut mieux que faux. Bloqué vaut mieux que silencieusement dangereux.
