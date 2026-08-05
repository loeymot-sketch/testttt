# GOAL — 8 AXES OWNER : Cuisine · Caisse · Web · Data Tacos
**Slug** : `OWNER_8AXES_CUISINE_CAISSE_WEB` · **Date** : 2026-08-05
**Statut** : PLAN-ONLY — aucune ligne de code écrite. Attente « lance le GOAL ».

---

## §0 — PRÉAMBULE

### §0.1 Décision arbre de travail (OBLIGATOIRE avant toute vague)

État constaté au moment de l'écriture :

| Fait | Valeur vérifiée |
|---|---|
| Branche | `pos/category-first-caisse-2026-06-23` |
| HEAD local | `1bd3d872d` |
| Fichiers non commités | **98** (`git status --porcelain \| wc -l`) |
| Retard VPS | BRAIN §2 : local **en avance sur VPS `827afae93`** → **gate déploiement ouverte** |
| Repo web déployé | `/Users/1millnonstop/Downloads/lecayenne-web-deploy/Site lecayenne` — remote `Site-lecayenne.git`, branche `main`, HEAD `e15bb42`, **arbre sale** (`legal/cgv.html`, `screens-v3.jsx`) |

**Décision imposée (Vague 1)** : les 98 fichiers sont majoritairement des rapports/captures/plans (`reports/`, `tests/captures/`, `plans/`). Ils ne sont **pas** dans le périmètre de ce GOAL.
→ **Commit de nettoyage séparé** `chore(reports): checkpoint avant GOAL 8-axes` AVANT la Vague 2, pour que tout `git diff` de vague soit lisible.
→ Le repo web a **2 fichiers sales** : les committer ou les stasher séparément dans son propre repo AVANT la Vague 6. Ne jamais mélanger.

⛔ Interdiction : `git add .` / `git add -A` (CLAUDE.md §3quater). Liste explicite uniquement.

### §0.2 Contradictions anti-drift détectées — À ARBITRER PAR L'OWNER AVANT EXÉCUTION

Ce GOAL contredit **trois décisions stables** du projet. CLAUDE.md §12 impose de les remonter, pas de les écraser en silence.

| # | Contradiction | Preuve | Arbitrage requis |
|---|---|---|---|
| **C-1** | **KDS 3 cartes max** est un **mandat owner ANTÉRIEUR** (commit `c70b1e518`, 2026-07-05 : « 3 commandes MAX affichées à la fois, chacune plein écran, grande + lisible »). L'axe 1 demande **6 cartes + scroll**. | `KdsV2Grid.vue:252-258`, `:434-443` ; sentinelle `tests/js/sentinels/KdsV2GridOverflowChipSentinel.spec.js:6-12` (`FK-KDS-3CARDS-001`) | **G-1** — l'owner confirme la RÉVOCATION du mandat 3-cartes. Les 3 sentinelles seront réécrites, pas contournées. |
| **C-2** | **`PaymentComponent.vue` est FROZEN** (CLAUDE.md §7 — « V1 untouched protected file »). L'axe 3 exige d'y toucher. | `CLAUDE.md §7` ; fichier `resources/js/components/admin/pos/PaymentComponent.vue` (1496 lignes) | **G-2** — LOCK doc obligatoire via skill `lock-plan`, contresigné owner, avant la moindre ligne. |
| **C-3** | **`public/js/pos-wizard.js` est FROZEN** (design parfait selon owner). L'axe 8 (crudités/tacos) touche au wizard caisse. | `CLAUDE.md §7` ; `pos-wizard.js` 319 544 octets | **G-3** — vérifié : le paiement n'est PAS dans ce fichier (`grep -c "payment" pos-wizard.js` = **0**). Si l'axe 8 peut être résolu en **DATA seule** (ItemExtra en base), le frozen n'est pas touché → chemin préféré. Sinon LOCK. |

### §0.3 Périmètre — 8 axes owner

| Axe | Intitulé owner | Système | Sévérité |
|---|---|---|---|
| A1 | KDS : 6 commandes visibles + scroll horizontal + barre de scroll souris | KDS | Amélioration |
| A2 | Nom du client sur commande téléphone — introuvable à la caisse | POS | Défaut UX |
| A3 | Encaissement : CB non fonctionnel + multi-paiement (CB + espèces) absent | POS + Borne + Web | **Bloquant métier** |
| A4 | Duplication de libellés sur ticket cuisine / KDS (menu, formule, frites) | Ticket + KDS | Défaut |
| A5 | Audit + e2e web complet — P0 « quitter le paiement = commande passée quand même » | Web | **P0** |
| A6 | Transfert web → caisse : détails complets, synchronisés, exploitables | Web ↔ POS | Défaut |
| A7 | Ticket cuisine : en-tête en gras, sur UNE seule ligne | Ticket | Cosmétique fiscalement neutre |
| A8 | Data tacos : pas de crudités, pas de « galette », option « Aucune crudité », + Poivrons cuits 0,90 € / Maïs / Olives payants sur caisse + borne + web | Data + 3 surfaces | Défaut confirmé |

### §0.4 Pipeline et convergence

Chaque tâche `T-x.y.z` s'exécute via le skill **`ultra-audit-profond`** (14 étapes : 5 spécialistes lecture-seule → synthèse → TDD → RED-team → test → visuel → visuel adversarial) — **non redécrit ici**. Override frozen-zone → **`lock-plan`**. Audit page-par-page → **`test-e2e`**.

**CONVERGÉ = deux cycles consécutifs avec P0+P1 = 0 ET ensembles de findings IDENTIQUES.**
Rejet immédiat si : libellé brut visible, casse de layout, erreur console, ligne de diff en frozen-zone sans LOCK, P0 RED-team non traité, critère d'acceptation sans chemin de test nommé, ou formule « ça marche presque ».

---

## §1 — CARTE DES SYSTÈMES (ancres VÉRIFIÉES ce jour)

| Système | Ancre principale vérifiée | Tests existants |
|---|---|---|
| **KDS** | `Admin/KitchenDisplaySystemController.php`, `Admin/KdsSyncController.php` ; 7 composants `resources/js/components/admin/kitchenDisplaySystem/` | 23 `tests/Feature/KDS/*` + ~20 specs `tests/js/kds*` |
| **POS Caisse** | `Admin/PosOrderController.php`, `Admin/Pos/CashDrawerController.php`, `PosOrderRequest.php` ; front `PosComponent.vue`, `PaymentComponent.vue` (FROZEN), `PosCounterCollectModal.vue` ; `public/js/pos-wizard.js` (FROZEN) | 40 `tests/Feature/Pos/*` |
| **Ticket / Impression** | `Hardware/OrderReceiptEscPosRenderer.php` (assemblage), `KitchenTicketSymbolicFormatter.php` (fragments), `EscPosCommandBuilder.php`, `EscPosTicketBytesService.php` ; `Listeners/PrintKioskKitchenTicketOnOrderCreated.php` | 16 `tests/Feature/Hardware/*` + 3 `tests/Unit/Hardware/*` |
| **Paiement / Split** | `config/split_payment.php` (**true**, `.env:84`), `Payments/SplitPaymentService.php:51/:211/:342`, `helpers/posSplitPayment.js` | `SplitPaymentEndToEndTest`, `TerminalIdWireInTest`, `PosCashTrailTest` |
| **Borne** | `frontend/kiosk/KioskWizardComponent.vue` (FROZEN), `steps/KioskStepGarnituresComponent.vue` | `tests/Feature/Frontend/*` |
| **Data menu (SSOT)** | Table `items` (**114 items**) ; miroir seeder `MenuResetLeCayenneCommand.php` | — (à créer, cf. §9) |

## §2 — SYSTÈME SÉPARÉ : SITE WEB CLIENT

**Chemin canonique** : `/Users/1millnonstop/Downloads/lecayenne-web-deploy/Site lecayenne` · remote `Site-lecayenne.git` · `main` · HEAD `e15bb42` (2026-08-04) · déployé Vercel.

⚠️ **PIÈGE CONFIRMÉ** : `/Users/1millnonstop/Downloads/web` existe aussi (`web/sync-caisse-2026-06-26`, `186feb6`, **sans remote**, 2026-07-14) — **snapshot mort**, jamais déployé. Idem `📦 À trier/frontend public/testttt`.
→ **Règle dure** : avant toute édition web, `git -C "<dir>" remote -v` ; refuser si ≠ `Site-lecayenne.git`.

Fichiers-clés : `funnel.jsx` (commande/paiement), `index.html` (routage + historique + retour Mollie), `api.js`, `data/menu.js` (SSOT menu web, 689 l.), `wizard-v2.jsx`, `tests-e2e/`.

---

## §3 — AXE 1 : KDS — 6 commandes + défilement horizontal

### Contrat
L'écran cuisine doit montrer **6 commandes simultanément**, avec **défilement horizontal** vers la droite pour voir la suite de la file, et une **barre de défilement visible et manipulable à la souris** (l'écran tactile peut tomber en panne). Objectif métier : le chef voit ce qui arrive ensuite et peut mettre toutes les viandes à cuire d'un coup.

### Frozen zones concernées
Aucune. `KdsV2Grid.vue` n'est pas en frozen-list.

### Ancres vérifiées
- `resources/js/components/admin/kitchenDisplaySystem/KdsV2Grid.vue:257` → `return this.activeOrders.slice(0, 3);`
- `KdsV2Grid.vue:259-260` → `overflowActiveCount = max(0, activeOrders.length - 3)`
- `KdsV2Grid.vue:437-443` → `grid-template-columns: repeat(3, minmax(0,1fr)); grid-template-rows: 1fr; overflow: hidden;`
- `KdsV2Grid.vue:446-447` → dégradés `data-count="1"` / `="2"`
- `KdsV2Grid.vue:305-318` → raccourcis clavier `[A]`–`[H]` **indexés sur `visibleActiveOrders`** (correctif P2-k 2026-07-18 : indexer sur la file complète permettait de bumper une commande invisible)
- `KdsV2Grid.vue:76-77` → pastille « +N en attente »
- `KdsOrderCard.vue:388` → hauteur de carte dérivée de la rangée `1fr` (KDS-3CARDS)

### Sub 1.1 — Passage 3 → 6 cartes
- **T-1.1.1** Porter `visibleActiveOrders` de 3 à 6 **via une constante nommée** (`KDS_VISIBLE_CARDS`) et non un littéral, pour que la prochaine révision owner soit un changement d'une ligne.
  • anchor : `KdsV2Grid.vue:252-261`
  • test : `tests/js/sentinels/KdsV2GridOverflowChipSentinel.spec.js` (**à RÉÉCRIRE** — encode aujourd'hui `FK-KDS-3CARDS-001`)
- **T-1.1.2** Réaligner `overflowActiveCount` sur la nouvelle constante (sinon la pastille « +N » ment).
  • test : même sentinelle
- **T-1.1.3** Réaligner les raccourcis `[A]`–`[H]` : 6 cartes visibles ⇒ 6 lettres actives. Vérifier qu'aucune lettre ne bumpe une commande hors écran (régression P2-k).
  • anchor : `KdsV2Grid.vue:305-318` · test : `tests/js/kds/kds-v2-grid-keys.spec.js`
- **T-1.1.4** Vérifier `kdsV2ArchiveSlotSentinel.spec.js:78-98` qui référence explicitement `activeOrders.slice(0,3)`.
  • test : `tests/js/sentinels/kdsV2ArchiveSlotSentinel.spec.js`

**Acceptance 1.1** : les 3 specs ci-dessus PASS après réécriture ; `npx vitest run tests/js/kds tests/js/sentinels` = 0 fail.

### Sub 1.2 — Défilement horizontal + barre visible
- **T-1.2.1** Remplacer `overflow: hidden` par un flux horizontal défilable : `grid-auto-flow: column`, `grid-auto-columns` calibré pour que **6 cartes tiennent dans la largeur**, `overflow-x: auto`.
  • anchor : `KdsV2Grid.vue:436-444`
  • test : (à créer) `tests/js/kds/kdsV2GridHorizontalScroll.spec.js`
- **T-1.2.2** **Barre de défilement TOUJOURS visible** (pas d'auto-hide macOS/WebKit) : `::-webkit-scrollbar` largeur ≥ 14 px + contraste Cayenne, `scrollbar-width: auto`. Exigence owner explicite : utilisable à la souris si le tactile lâche.
  • test : même spec (assertion sur la classe/style appliqué)
- **T-1.2.3** Ajouter **deux boutons de défilement ◀ ▶** larges (cible tactile ≥ 64 px) en secours du drag — la barre seule est une cible fine sur un écran cuisine.
  • test : même spec
- **T-1.2.4** Conserver la pastille « +N » **au-delà** de la fenêtre défilable (la file peut dépasser ce qui est atteignable au scroll).

**Acceptance 1.2** : spec créée PASS + **capture Playwright de `/kds` avec 9 commandes actives**, lue et analysée : 6 cartes lisibles, barre visible, pas de carte écrasée, pas de débordement vertical du corps de page.

### Sub 1.3 — Lisibilité à 6 cartes (le vrai risque)
Le mandat 3-cartes existait *parce que* 6 cartes serrées deviennent illisibles depuis le passe. Contrepartie à **prouver**, pas à supposer.
- **T-1.3.1** Largeur de carte **plancher** (px) sous laquelle on défile au lieu de compresser.
- **T-1.3.2** Captures à 1920×1080 **et** à la résolution réelle de l'écran cuisine (**G-4**).
- **T-1.3.3** `KdsOrderCard` reste lisible : produit, viandes, suppléments, allergènes non tronqués (`KdsOrderCard.vue:388`).

**Acceptance 1.3** : captures lues aux deux résolutions ; **verdict écrit** lisible/non lisible. Si non lisible → remonter à l'owner **avant** de livrer un écran cuisine dégradé.

---

## §4 — AXE 2 : Nom du client sur commande téléphone

### Contrat
Quand la caisse prend une commande par téléphone, l'opérateur doit pouvoir saisir le **nom du client** au moment de finaliser, et ce nom doit apparaître **sur le ticket**. Aujourd'hui l'owner écrit le nom **au stylo** après impression.

### Ancres vérifiées — le champ EXISTE déjà
- `resources/js/components/admin/pos/PosComponent.vue:923-940` → deux `<input>` : `pos_customer_name` (`data-testid="pos-customer-name"`) et `pos_customer_phone`, **conditionnés à `order_type !== delivery`**
- `resources/js/languages/fr.json:488-489` → `pos_customer_name_placeholder` = « Nom du client (optionnel) » — **clé i18n présente, pas de libellé brut**
- `app/Http/Requests/PosOrderRequest.php:119` → `pos_customer_name` validé `nullable|string|max:60`
- `PosComponent.vue:4939` → envoyé au submit ; `:4948` → `phone_order: true` sur le flux téléphone
- `PosComponent.vue:458-462` → badge nom+tél affiché pour les commandes `source_surface === 'web'`

**Conclusion d'ancrage** : le champ n'est pas absent — il est **inatteignable ou invisible dans le parcours téléphone réel**. Toute correction écrite avant reproduction serait une supposition.

### Sub 2.1 — Reproduction avant correction (BLOQUANT)
- **T-2.1.1** Reproduire en conditions réelles sur `http://127.0.0.1:8000/admin/pos` : ajouter au panier → mode « Commande téléphone » → aller jusqu'à la fin. **Capturer chaque écran.** Déterminer laquelle est vraie : (a) le champ est hors écran / sous la ligne de flottaison, (b) le flux téléphone (`PosComponent.vue:4948`) court-circuite l'écran portant le champ, (c) le champ est masqué par le type de commande sélectionné, (d) le bundle servi est périmé.
  • **Piège mémoire** : `pos-app.js` est compilé par Laravel Mix (`admin-pos-v4.blade.php:120`) — un correctif non recompilé ne changera rien à l'écran. Vérifier le hash réellement servi.
  • test : (à créer) `tests/Playwright/pos-phone-order-customer-name-2026-08-05.spec.js`

### Sub 2.2 — Correction selon la cause établie
- **T-2.2.1** Rendre la saisie du nom **explicite et impossible à manquer** dans le parcours téléphone (étiquette visible, pas seulement un `placeholder` gris, et positionnée dans le flux du regard).
- **T-2.2.2** Rendre le champ disponible **aussi en livraison** si le champ nom dédié de la livraison ne remonte pas jusqu'au ticket cuisine (à vérifier, pas à supposer).
- **T-2.2.3** Vérifier la présence du nom **sur le ticket imprimé** de bout en bout.
  • test : `tests/Feature/Pos/PhoneOrderDeferredTest.php` (existant — étendre) + `tests/Feature/Pos/PosTicketBytesEndpointTest.php`

**Acceptance §4** : `PhoneOrderDeferredTest` + `PosTicketBytesEndpointTest` PASS ; spec Playwright créée PASS ; capture de l'écran téléphone lue montrant le champ nom **visible sans défiler** ; octets ESC/POS contenant le nom saisi.

---

## §5 — AXE 3 : Encaissement CB + multi-paiement

C'est l'axe **le plus lourd** et le seul à toucher une frozen-zone. Il se scinde en trois problèmes **distincts** que l'owner décrit comme un seul.

### §5.0 Ce qui est déjà VRAI en base et en code (vérifié)
| Fait | Preuve |
|---|---|
| Le multi-tender est **activé** | `config/split_payment.php:19` `enabled` défaut **true** ; `.env:84` `SPLIT_PAYMENT_ENABLED=true` |
| Le service backend existe | `SplitPaymentService.php:51` `validateBreakdown`, `:211` `persistTranches`, `:342` `persist` |
| L'UI multi-tranches existe | `PaymentComponent.vue:69-73` onglet `multi`, `:177-303` panneau tranches, `:842-847` payload `payment_breakdown` |
| Il y a **1 TPE actif** en base | `PaymentTerminal` : 1 total, 1 actif |
| Le mode CB exige un TPE | `PaymentComponent.vue:112-138` ; `PosOrderRequest.php:100-107` (`terminal_id` `required_if` hors split) |
| **Le multi-paiement N'EXISTE PAS pour encaisser une commande borne/web/téléphone** | `PosCounterCollectModal.vue:16-27` : « minus the multi-tranche split… **deferred** » ; `routes/api.php:961-971` : le endpoint valide un **`mode` unique**, aucun `payment_breakdown` |

→ **L'axe 3 n'est pas « le split ne marche pas ». C'est : le split marche pour une commande créée à la caisse, et n'existe pas pour l'encaissement d'une commande déjà passée (borne / web / téléphone).** C'est exactement le cas d'usage que l'owner décrit.

### Frozen zones concernées
`resources/js/components/admin/pos/PaymentComponent.vue` — **LOCK obligatoire (G-2)**.
`resources/js/components/admin/pos/v5/PosV5TrancheRow.vue` — également frozen (CLAUDE.md §7). **LOCK si touché (G-5)**.

### Sub 3.1 — Reproduire le « CB pas fonctionnel » (BLOQUANT, avant tout code)
- **T-3.1.1** Encaisser une commande caisse en CB, en conditions réelles, et **capturer l'échec exact** : 422 ? bouton désactivé (`canConfirmCard`, `PaymentComponent.vue:311`) ? TPE non pré-sélectionné ? bundle périmé ?
  • anchor : `PaymentComponent.vue:109-158`, `:311-313`
  • test : (à créer) `tests/Playwright/pos-encaissement-cb-2026-08-05.spec.js`
- **T-3.1.2** Vérifier le hash `pos-shell.*.js` réellement servi vs le dernier build. **11 bundles `pos-shell.*.js` cohabitent dans `public/js/`** — le risque de servir un bundle périmé est documenté et récurrent dans ce projet.
- **T-3.1.3** Décider avec preuve : défaut de code, défaut de configuration TPE, ou défaut de build.

### Sub 3.2 — CB comme simple ENREGISTREMENT comptable (demande owner explicite)
L'owner encaisse **manuellement** sur son TPE. Le logiciel ne pilote pas le terminal : il **enregistre** que X € ont été payés en carte, pour la ventilation espèces / CB.
- **T-3.2.1** Vérifier si `terminal_id` bloque un encaissement CB purement déclaratif. Si oui, le rendre optionnel **sans affaiblir la piste NF525** (le mode reste tracé ; seule la référence matérielle devient facultative).
  • anchor : `PosOrderRequest.php:100-107` · test : `tests/Feature/Pos/TerminalIdWireInTest.php` (étendre, **ne pas casser**)
- **T-3.2.2** Ventilation CB/espèces correcte au **rapport Z** et au journal de caisse.
  • test : `tests/Feature/Pos/PosCashTrailTest.php`
  ⚠️ **NF525** : `ZReportService` / `FiscalSequenceService` sont frozen. Cet axe **lit** la ventilation, il ne modifie pas la chaîne. Toute dérive = **STOP + gate owner**.

### Sub 3.3 — Multi-paiement à l'encaissement (le vrai manque)
Cas owner : commande à 20 € → « je tape 12 € en carte, il me reste 8 €, je choisis espèces pour le reste ».
- **T-3.3.1** Étendre `POST /counter-collect/{order}/confirm` pour accepter un `payment_breakdown` (tableau de tranches) en réutilisant `SplitPaymentService::validateBreakdown` + `persistTranches` — **ne pas dupliquer la logique monétaire**.
  • anchor : `routes/api.php:961-1004` ; `SplitPaymentService.php:51,:211`
  • test : (à créer) `tests/Feature/Pos/CounterCollectSplitPaymentTest.php`
- **T-3.3.2** Câbler le panneau multi-tranches dans `PosCounterCollectModal.vue` (reprendre les atomes V5 existants et le helper `posSplitPayment.js` — arithmétique en **centiers entiers**, jamais en flottants).
  • anchor : `PosCounterCollectModal.vue:75-160` ; `resources/js/helpers/posSplitPayment.js`
  • test : (à créer) `tests/js/posCounterCollectSplit.spec.js`
- **T-3.3.3** Afficher le **reste à payer** en direct après chaque tranche (exigence owner littérale : « il m'affiche le reste combien il reste »).
  • anchor : helper `splitRemainingCents` (`posSplitPayment.js`) ; miroir `PaymentComponent.vue:424-430`
- **T-3.3.4** Idempotence : la clé actuelle est `pos-counter-collect-{orderId}-{modeInt}-{minuteBucket}` (`PosCounterCollectModal.vue:30`). En multi-tranches, `modeInt` n'est plus unique → **risque de double encaissement ou de rejet erroné**. Reconcevoir la clé sur le contenu du breakdown.
  • test : `tests/Feature/Pos/CounterCollectQueueRobustTest.php` (existant — étendre)
- **T-3.3.5** Vérifier l'exclusion mutuelle : deux caissiers encaissant la même commande → 409 `payment_already_collected` doit tenir en multi-tranches.
  • anchor : `routes/api.php:984-999`

**Acceptance §5** : `SplitPaymentEndToEndTest`, `PosCashTrailTest`, `TerminalIdWireInTest`, `CounterCollectQueueRobustTest` PASS + les 3 tests créés PASS + **preuve monétaire au centime** : commande 20,01 € réglée 12,00 € CB + 8,01 € espèces → somme des `order_payments` = total exact, ventilation Z correcte, diff frozen-zone = 0 hors LOCK contresigné.

---

## §6 — AXE 4 + AXE 7 : Ticket cuisine — duplications et en-tête

### Contrat
Le ticket cuisine doit être **100 % exact** : quand on commande un menu, il affiche « menu + frites + boisson » ; quand on commande des frites seules, il affiche « frites » — **jamais « Menu » puis « Formule Menu »**, jamais un composant listé deux fois. Les suppléments et tous les détails doivent apparaître correctement. L'en-tête doit être **en gras, sur une seule ligne**.

### Frozen zones concernées
Aucune sur le formateur. ⚠️ `FiscalSequenceService` / `ZReportService` / `AuditLogService` sont frozen mais **hors périmètre** : le ticket **cuisine** n'est pas le ticket **fiscal**. Toute dérive vers `OrderReceiptEscPosRenderer` (ticket client fiscal) = **STOP + gate**.

### Ancres vérifiées — correction d'une idée reçue
⚠️ **`KitchenTicketSymbolicFormatter` ne produit PAS le ticket** : il ne rend que des *fragments de lignes*. L'assemblage réel (en-tête + boucle de blocs + ESC/POS) est dans **`OrderReceiptEscPosRenderer::renderKitchenTicket()` (`:248`)**. Toute correction visant le seul formateur raterait la moitié du problème.

- `app/Services/Hardware/OrderReceiptEscPosRenderer.php:248-405` — assemblage du ticket cuisine
- `app/Services/Hardware/KitchenTicketSymbolicFormatter.php` — `mainLine():159`, `supplementLines():251`, `menuLine():447`, `drinkLines():567`, `isDrinkItem():494`, `extractFormuleDrinkLines():538`, `cleanInstruction():600`, `produitCode():715`
- `app/Services/Hardware/EscPosCommandBuilder.php` — `bold():31`, `doubleSize():46`, `doubleHeight():66`, `textLine():152`, `textWrap():225`
- `app/Services/Hardware/EscPosTicketBytesService.php:41-110` — **sélecteur** d'imprimante/largeur/code-page, **aucune commande ESC/POS**
- Impression borne : `app/Listeners/PrintKioskKitchenTicketOnOrderCreated.php:31-83` — appelle le renderer **directement**
- Impression caisse : `app/Http/Controllers/Admin/Pos/PosTicketBytesController.php:26-75` — passe **par** `EscPosTicketBytesService`
- Jumeaux JS du KDS : `resources/js/helpers/kdsSymbolic.js:504-590` (`renderItemSymbolic`), `kdsCustomization.js:254,:291-327` ; rendu `KdsOrderCard.vue:494-498`, `KdsOrderLine.vue:29-89`
- `app/Http/Resources/KDSOrderDetailsResource.php:77` — **ne formate rien** : toute la composition KDS est côté JS

### §6.0 — Trois duplications CONFIRMÉES (preuve directe, à `fichier:ligne`)

| # | Duplication | Mécanisme prouvé | Couvert par un test ? |
|---|---|---|---|
| **D-1** | **La boisson de formule sort DEUX FOIS** | Canal A `drinkLines()` → `"{q} {name}"` (`Formatter:589`, imprimé `Renderer:383-389`). Canal B instruction → `"BOISSON: X"` (`Formatter:549`, injecté `:676-681`, imprimé `:390-394`). Le dédoublonnage `Formatter:675-681` compare **uniquement** les `BOISSON:` entre eux et **ignore totalement** `drinkLines()` ; les formats ne sont pas normalisés (`1 Hawaï 33cl` ≠ `boisson: hawaï 33cl`). Déclencheur : l'addon porte le **vrai** nom de boisson au lieu du nom du conteneur. | **NON** |
| **D-2** | **Le mot « MENU » apparaît deux fois** | Un SKU conteneur « Menu (…) » → head `MENU` (`Renderer:316-324`). Le produit portant les addons `menu_*` → ligne `MENU` (`Formatter:469-471`, imprimé `Renderer:364-370`). Aucune corrélation : commentaire explicite `Renderer:319` « pas de fusion devinée ». | **NON** |
| **D-3** | **« Frites » produit + « FRITES » composant** | Item Frites standalone → `FRI` (`Formatter:715-732`). Autre item avec addon `menu_frites` → `FRITES` (`:472`) ou repli `'F'` (`:478-482`). Aucun rapprochement, aucun dédoublonnage. | **NON** |

**Fait structurel décisif** : il n'existe **aucun** dédoublonnage sur les lignes finales (0 occurrence de `array_unique` / `new Set()` dans le renderer, le formateur, les deux helpers JS, les deux composants Vue et la Resource). Les dédoublonnages existants sont **locaux et partiels** (7 emplacements inventoriés, chacun avec sa limite connue).
**Et les jumeaux JS reproduisent D-1/D-2/D-3 à l'identique** — écran et papier faux de la même manière.

### Sub 4.1 — Matrice de vérité AVANT correction
- **T-4.1.1** Écrire la sortie **attendue** puis **réelle** pour 9 cas : produit seul · produit en menu · SKU formule séparé · frites seules · frites en menu · **frites seules + frites en menu dans la même commande (D-3)** · boisson seule · **boisson formule avec vrai nom d'addon (D-1)** · **produit avec addons menu + SKU menu séparé (D-2)**.
  • test : (à créer) `tests/Feature/Hardware/KitchenTicketNoDuplicateLabelTest.php`
- **T-4.1.2** Miroir KDS, mêmes 9 cas.
  • test : (à créer) `tests/js/kdsNoDuplicateLabel.spec.js`

### Sub 4.2 — Correction : normaliser puis dédoublonner
- **T-4.2.1** **D-1** — normaliser les deux canaux boisson vers une clé comparable (nom seul, minuscules, sans quantité), puis dédoublonner. Corriger **`Formatter:675-681` ET son jumeau `kdsCustomization.js:323-327`** dans le même lot.
- **T-4.2.2** **D-2** — corréler le SKU conteneur et le produit porteur d'addons. ⚠️ Le commentaire `Renderer:319` explique que la fusion a été **volontairement écartée** (le menu n'est pas forcément adjacent à son produit). Ce n'est donc pas un oubli : c'est un compromis assumé. → **G-9 : arbitrage owner** sur la règle métier attendue quand les deux formes coexistent.
- **T-4.2.3** **D-3** — décider si « Frites » produit et « FRITES » composant doivent fusionner ou rester distincts. Métier : ce sont **deux portions différentes**, les fusionner ferait sous-produire la cuisine. → **Recommandation : ne PAS fusionner, mais les rendre visuellement non ambigus.** Relève également de G-9.
- **T-4.2.4** Vérifier suppléments (nom + quantité) complets et non tronqués — `Formatter:251-285`.
- **T-4.2.5** Ne pas régresser le supplément viande nommé (règle 2026-07-24).
  • test : `tests/js/kdsSymbolicViandeName.spec.js`, `tests/Feature/Hardware/KitchenTicketViandeSupplNameTest.php` (existants)
- **T-4.2.6** Maintenir la parité PHP↔JS — elle est déjà sous sentinelle.
  • test : `tests/Unit/Hardware/KitchenSymbolPhpJsParityTest.php`, `tests/js/kitchenParityRealData.spec.js` (existants)

### Sub 4.3 — Axe 7 : en-tête gras une seule ligne — **REPRODUCTION D'ABORD**
⚠️ **Constat qui contredit la demande telle qu'énoncée** : le titre **`CUISINE` est DÉJÀ en gras et sur une seule ligne** — `OrderReceiptEscPosRenderer.php:259-261` (`bold(true)` → `textLine('CUISINE')` → `bold(false)`, aucun `doubleSize`, `textLine` ne se replie pas).
Les éléments d'en-tête qui **peuvent** déborder sur plusieurs lignes sont, eux, identifiés :
- le **numéro d'appel** : `:270-274` bascule en `doubleHeight` + `textWrap` si le numéro dépasse la demi-largeur
- la **bannière type de commande** (`*** SUR PLACE ***`) : `:276-279` en `textWrap`
- les lignes **`Client :`** et **`Tel :`** : `:281-290` en `textWrap`

- **T-4.3.1** **Faire identifier par l'owner, sur un ticket imprimé réel, QUELLE ligne d'en-tête il veut en gras sur une ligne.** → **G-10.** Sans cela, on corrigerait une ligne déjà correcte et on laisserait la vraie intacte.
- **T-4.3.2** Une fois la ligne désignée : gras via `bold()` et non-repli via `textLine` + troncature calibrée sur la largeur réelle (48 caractères par défaut, `EscPosTicketBytesService:67-72`).
- **T-4.3.3** Appliquer sur les **deux** chemins. ⚠️ **Piège structurel réel** : le listener borne (`PrintKioskKitchenTicketOnOrderCreated.php:74-76`) appelle le renderer **directement** et **n'hérite donc PAS** de la logique de largeur/code-page de `EscPosTicketBytesService:67-95`. Un correctif calibré sur la largeur configurée sera **faux côté borne**.
- **T-4.3.4** Assertion sur les **octets de style** (`ESC E`), pas sur la chaîne de texte. **Aucun test existant ne vérifie le style de l'en-tête** — ils cherchent le mot « CUISINE » en clair.
  • test : `tests/Feature/Pos/PosTicketBytesEndpointTest.php` (existant — étendre), `tests/Feature/Hardware/KioskKitchenTicketTest.php` (existant — étendre)

**Acceptance §6** : les 2 tests créés PASS ; **D-1, D-2, D-3 reproduits en rouge puis verts** ; les 17 tests `tests/Feature/Hardware/*` et les specs `tests/js/kdsSymbolic*` existants PASS **sans assouplir leurs attentes** (tout assouplissement doit être justifié par écrit) ; parité PHP↔JS verte ; matrice T-4.1.1 entièrement verte ; dump d'octets décodé d'un ticket réel, lu et analysé.

---

## §7 — AXE 5 : Site web — P0 abandon de paiement + audit e2e complet

### Contrat
Il doit être **impossible** de se retrouver avec une commande enregistrée et un écran « en préparation » sans avoir payé.

### §7.0 Diagnostic structurel VÉRIFIÉ

| Étape | Preuve | Conséquence |
|---|---|---|
| La commande est **créée avant** tout paiement | `funnel.jsx:607` `const order = await api.placeOrder(orderOpts);` | Une commande existe en base dès le clic |
| Le paiement Mollie n'intervient qu'**après** | `funnel.jsx:635-636` `api.mollieCheckout(order.id, cardToken)` | Fenêtre d'abandon entre les deux |
| Redirection 3-D Secure | `funnel.jsx:483-486` `window.location.href = threeDs` | L'utilisateur quitte la page |
| Le retour gère l'annulation | `index.html:294-315` : statut 5 → payé ; statut **16** → panier restauré + retour `payment` | Le cas **webhook d'annulation reçu** est couvert |
| **Mais** : abandon sans annulation (onglet fermé, retour arrière, 3DS abandonné) | aucun chemin trouvé | **La commande impayée subsiste** |
| Le suivi affiche « EN PRÉPARATION » par défaut | `funnel.jsx:1206` `(statusName \|\| 'EN PRÉPARATION')` | **Le client croit sa commande en cuisine alors qu'il n'a pas payé** |

**Sévérité honnête — bornée par une garde backend réelle** :
`app/Domain/Kds/KitchenReleaseRule.php:55-70` — une commande non payée et de statut `< ACCEPT` **ne part PAS en cuisine**. Donc : **pas de perte de marchandise**. Le préjudice est (a) le mensonge affiché au client, (b) la pollution de la caisse et de l'historique par des commandes fantômes impayées.
→ **P0 d'expérience et de fiabilité comptable, pas P0 de production cuisine.** Cette nuance doit figurer dans le rapport final — ne pas dramatiser, ne pas minimiser.

### Sub 5.1 — Fermer la fenêtre d'abandon
- **T-5.1.1** Décider l'architecture, **deux options à trancher** :
  **(A)** Ne créer la commande qu'**après** confirmation du paiement (correction à la racine ; impacte l'idempotence `lc.funnel.idem` et le jeton carte créé en amont, `funnel.jsx:693-700`).
  **(B)** Créer la commande dans un **état « brouillon »** invisible de la caisse et de l'historique, promue seulement au paiement, expirée automatiquement sinon.
  → **Recommandation** : **(B)**. L'option (A) casse l'idempotence anti-double-commande qui protège aujourd'hui contre le double-clic, et le jeton Mollie est créé avant la commande par conception explicite (« aucune commande orpheline »). (B) est réversible et testable isolément.
  → **G-6 : arbitrage owner requis** — c'est une décision d'architecture, pas un détail d'implémentation.
- **T-5.1.2** Purge automatique des commandes web impayées au-delà d'un délai (commande planifiée), avec trace.
  • test : (à créer) `tests/Feature/Web/WebUnpaidOrderExpiryTest.php`
- **T-5.1.3** Le suivi ne doit **jamais** afficher « EN PRÉPARATION » par défaut pour une commande impayée — afficher « paiement non finalisé » et proposer de reprendre.
  • anchor : `funnel.jsx:1206`, `funnel.jsx:1100-1103`
  • test : (à créer, repo web) `tests-e2e/abandon-paiement.spec.js`
- **T-5.1.4** Couvrir les **trois** modes d'abandon, pas seulement celui déjà géré : onglet fermé pendant 3DS, retour navigateur depuis 3DS, expiration Mollie sans webhook.
  • anchor : garde existante `index.html:245-258` (`onPop`), `index.html:250`

### Sub 5.2 — Audit e2e complet du site (skill `test-e2e`)
- **T-5.2.1** Parcours complet : menu → wizard → panier → compte/OTP → checkout → paiement → confirmation → suivi → historique.
- **T-5.2.2** Chaque écran **capturé, lu et analysé** (CLAUDE.md §6) : libellé brut, layout, état vide, état d'erreur, i18n, image de marque. Console et réseau propres.
- **T-5.2.3** Boucle correction ↔ test jusqu'à **deux cycles identiques à P0+P1 = 0** (exigence owner littérale).

**Acceptance §7** : les 2 tests créés PASS ; **preuve directe** — commande web abandonnée en 3DS **absente** de la caisse et de l'historique, et le suivi n'annonce jamais « en préparation ». Deux cycles d'audit consécutifs identiques.

---

## §8 — AXE 6 : Transfert web → caisse complet et synchronisé

### Contrat
Une commande venue du site doit arriver à la caisse **avec tous ses détails**, synchronisés et exploitables — pas une ligne opaque.

### Ancres vérifiées
- `app/Services/OrderService.php:91` → `source_surface` (`kiosk|pos|web|app`) ; `:216-233` filtrage historique web
- `resources/js/components/admin/pos/PosComponent.vue:458-462` → badge 🌐 nom + téléphone pour `source_surface === 'web'`
- `app/Http/Controllers/Frontend/OrderController.php:63` `store`, `:88` `show`, `:154` `paymentConfirm`
- `app/Http/Resources/OrderDetailsResource.php` → charge utile exposée aux surfaces
- Contrat de synchronisation : `SYNC_CONTRACT.md` (13 événements réels recensés le 2026-07-29)

### Sub 6.1 — Établir l'écart, champ par champ
- **T-6.1.1** Construire un **tableau de parité** : ce que le site envoie (`api.js:612-679`) → ce que la base stocke → ce que la caisse affiche → ce que le ticket cuisine imprime. Une ligne par champ métier : viandes, sauces, crudités, suppléments, menu/formule, boisson, frites, instructions, allergies, nom, téléphone, créneau programmé.
  • test : (à créer) `tests/Feature/Web/WebOrderPosParityTest.php`
- **T-6.1.2** Identifier les champs **perdus en route** et lesquels sont perdus **définitivement** (jamais stockés) vs **seulement non affichés** — la correction n'est pas la même.
  • **Précédent projet** : le ticket cuisine web perdait le **nom** de la sauce (2026-07-21). Vérifier explicitement que la régression n'est pas revenue.

### Sub 6.2 — Affichage et contrôlabilité à la caisse
- **T-6.2.1** Détail complet et déplié d'une commande web à la caisse.
- **T-6.2.2** Actions attendues disponibles (encaisser, réimprimer, annuler) — cohérentes avec §5.
  • test : `tests/Feature/Pos/WebOrderInlineAcceptTest.php` (existant), `PosOperatorWebOrderPermissionTest.php` (existant)
- **T-6.2.3** Synchronisation temps réel : une commande web apparaît sans rechargement manuel.
  • **Racine documentée 2026-07-22** : la cause d'une désynchronisation était le **worker de file d'attente à l'arrêt**, masqué par un soketi actif. Vérifier le worker AVANT de conclure à un défaut de code.
  • test : `tests/Feature/Kds/KdsSyncBoardReleaseConsistencyTest.php` (existant)

**Acceptance §8** : test de parité créé PASS avec **zéro champ perdu** ; les 2 tests POS existants PASS ; capture de la fiche commande web à la caisse, lue, montrant la composition complète ; ticket cuisine de cette même commande **identique** à la composition.

---

## §9 — AXE 8 : Data tacos, crudités, nouveaux suppléments

### §9.0 Défaut CONFIRMÉ en base (preuve directe)

```
26 | tacos-m              | Tacos M            | cat=Tacos            | crudites=Salade|Tomate|Oignon|Oignons cuits
97 | tacos-l              | Tacos L            | cat=Tacos            | crudites=Salade|Tomate|Oignon|Oignons cuits
27 | big-tacos-2-viandes  | Big Tacos          | cat=Tacos            | crudites=AUCUNE
76 | tacos-signature-xl   | Tacos Signature XL | cat=Tacos Signature  | crudites=AUCUNE
23 | galette-normale      | Galette Normale    | cat=Galette          | crudites=Salade|Tomate|Oignon|Cornichon|Oignons cuits
24 | galette-cayenne      | Galette Cayenne    | cat=Galette          | crudites=Salade|Tomate|Oignon|Cornichon|Oignons cuits
10 | galette-pommes-de-terre | Galette pommes de terre | cat=Suppléments | crudites=AUCUNE
```

**L'owner a raison** : Tacos M et Tacos L portent des crudités alors que la règle métier dit qu'un tacos n'en a pas. Et ils sont **incohérents entre eux** (Big Tacos n'en a pas). La base a **dérivé du seeder** : `MenuResetLeCayenneCommand.php` appelle `seedCruditesAsExtras` uniquement aux lignes `:463, :481, :496, :514` — **jamais pour les tacos** (`:519-545`). Le seeder est correct, **la base ne l'est pas**.

⚠️ Rappel CLAUDE.md §3bis : **la table `items` est la SSOT**, pas le seeder. On corrige donc la **donnée**, et on ajoute un **test de garde** pour que la dérive ne revienne pas.

### Sub 8.1 — Retirer les crudités des tacos
- **T-8.1.1** Retirer les crudités des items 26 et 97 par **migration de données idempotente** (pas de `MenuReset` global : il écraserait 114 items de production).
  • test : (à créer) `tests/Feature/Data/TacosNoCruditeGuardTest.php` — garde permanente : aucun item de catégorie Tacos ne porte d'extra `group_label='crudite'`
- **T-8.1.2** Vérifier le rendu sur les **trois** surfaces : caisse, borne, site web.
  • anchor web : `data/menu.js:172` (`CRUDITES`), `:617` (`defaultCruditeIds`), `wizard-v2.jsx:168` (étape crudités)

### Sub 8.2 — Supprimer le mot « galette » devant un tacos
- **T-8.2.1** Localiser où « Galette » précède un tacos dans un libellé ou un ticket. ⚠️ **« Galette » est une catégorie légitime** (Galette Normale, Galette Cayenne) : la supprimer globalement casserait deux produits réels. La correction doit être **ciblée sur les tacos uniquement**.
- **T-8.2.2** Appliquer la règle owner sur la répétition : un composant unique ne s'écrit pas au pluriel ni en double ; à partir de 2 exemplaires, la quantité est indiquée (« 2× »), le libellé n'est pas répété.
  • test : couvert par `tests/Feature/Kitchen/KitchenTicketNoDuplicateLabelTest.php` (§6)

### Sub 8.3 — Option « Aucune crudité »
Aujourd'hui, pour retirer les crudités, il faut **désélectionner chaque crudité une par une**. L'owner veut **un seul geste**.
- **T-8.3.1** Ajouter un choix **« Sans crudités »** exclusif (le sélectionner désélectionne tout le reste, et inversement).
  • **Précédent réutilisable** : « Sans sauce » existe déjà avec un drapeau `solo` exclusif (mémoire 2026-07-31). **Reprendre ce mécanisme, ne pas en inventer un second.**
- **T-8.3.2** Câbler sur **caisse**, **borne**, **site web** — les trois, avec parité prouvée.
  • test : (à créer) `tests/js/aucuneCruditeExclusive.spec.js` + garde backend dans le test de parité §8

### Sub 8.4 — Nouveaux suppléments payants
Demande owner : **Poivrons cuits 0,90 €**, **Maïs**, **Olives** — payants, sur caisse + borne + **site web**.
- **T-8.4.1** Confirmer les **prix de Maïs et Olives** (non chiffrés par l'owner) → **G-7**. Ne rien inventer (CLAUDE.md §3bis).
- **T-8.4.2** Choisir le **groupe** : crudités actuelles à 0 € ; suppléments génériques à 1,00 € (`MenuResetLeCayenneCommand.php:62-72`) ; le web documente « 9 suppléments payants +0,90 € » (`data/menu.js:180-182`). → Rattacher aux **suppléments payants**, pas aux crudités gratuites, sinon **le prix ne sera pas facturé**.
  ⚠️ **Précédent grave** : la 2ᵉ sauce frites était **affichée +0,50 € et jamais facturée** (2026-07-29). « Affiché mais non facturé » est le risque n°1 de cet axe.
- **T-8.4.3** Créer les extras de façon idempotente (`updateOrCreate`, jamais `firstOrCreate` — leçon 2026-07-15).
- **T-8.4.4** Miroir `data/menu.js` (web) + wizard borne + wizard caisse.
- **T-8.4.5** **Preuve de facturation** : prix affiché = prix scellé = prix au ticket, sur les trois surfaces.
  • test : (à créer) `tests/Feature/Pricing/NewSupplementsBilledTest.php` — **TDD obligatoire**, test rouge d'abord.

**Acceptance §9** : les 4 tests créés PASS ; requête base prouvant 0 crudité sur toute la catégorie Tacos ; ajout de Poivrons cuits sur les 3 surfaces avec **total augmenté de 0,90 € exactement** ; captures des 3 wizards lues.

---

## §A — ARMÉE D'AGENTS

Rôles (tous `general-purpose` sauf Architect = `Plan`) et section où chacun se déclenche :
**Architect** (lecture) — arbitrages T-5.1.1, T-8.4.2, D-2/D-3 · **Security** (lecture) — §5 idempotence/double encaissement/409, §7 commande fantôme · **DBA** (lecture) — §9 migration idempotente, §8 parité de champs · **SRE/Sync** (lecture) — §8 worker de file et événements · **UX/A11y** (lecture + axe-core) — §3 lisibilité 6 cartes, §4 visibilité du champ nom · **Fiscal** (lecture) — §5 ventilation CB/espèces au rapport Z, **sans toucher** aux services frozen · **Implementer** (édition) — **jamais deux en parallèle** · **RED-team** (lecture) — après chaque commit, avant tout « terminé » · **QA Visual + RED Visual** — en parallèle, jamais le même agent pour capturer et pour juger.

**Déclenchement** — §3 : Architect+UX+Impl+RED+QA/RED Visual · §4 : UX+Impl+RED+QA Visual · §5 : Architect+Security+Fiscal+DBA+Impl+RED · §6 : Architect+Impl+RED · §7 : Architect+Security+UX+Impl+RED+QA/RED Visual · §8 : Architect+Security+DBA+SRE+Impl+RED · §9 : DBA+Architect+Impl+RED+QA Visual.

**Contrat de rapport** : sur disque, `reports/goal-8axes-2026-08-05/<vague>/<role>.json`, format `[P0-P3] fichier:ligne — titre / reproduction / preuve / recommandation`, ~1500 mots max.
**Anti-hallucination** : chaque agent reçoit `git rev-parse HEAD` et le rappelle en tête de rapport. Tout constat sans `fichier:ligne` vérifiable est **rejeté** (précédent : un agent a audité un arbre périmé et inventé un P1 monétaire).

---

## §X — VAGUES DE CONVERGENCE

| # | Vague | Périmètre | Parallélisme | Point de contrôle |
|---|---|---|---|---|
| **1** | Pré-vol | §0.1, branche `backup/pre-8axes-2026-08-05`, sauvegarde base, relevés de référence (PHPUnit + Vitest, `audit_logs` count + dernier hash), worker + soketi, `df -h` ≥ 15 Go | séquentiel | Relevés écrits ; gates §G qualifiées |
| **2** | **Reproductions bloquantes** | T-2.1.1, T-3.1.1/2, T-4.1.1/2 — **aucune correction** | fan-out lecture seule | Chaque symptôme owner a une **cause prouvée** ou est déclaré non reproductible. ⛔ Rien ne démarre avant. |
| **3** | Data (axe 8) | §9 en entier | séquentiel | Base propre, tests de garde verts, 3 wizards capturés |
| **4** | Ticket + KDS (axes 4 et 7) | §6 en entier | séquentiel | Matrice de vérité verte, ticket capturé |
| **5** | KDS 6 cartes (axe 1) | §3 en entier — **après G-1** | parallèle possible avec 4 (domaines disjoints) | Captures aux deux résolutions, verdict lisibilité écrit |
| **6** | Caisse (axes 2 et 3) | §4 + §5 — **après G-2** | séquentiel (frozen-zone) | Preuve monétaire au centime, diff frozen = 0 hors LOCK |
| **7** | Web (axes 5 et 6) | §7 + §8 — **après G-6** | repo web **isolé** | Commande abandonnée absente de la caisse ; parité de champs complète |
| **8** | Convergence finale | Suite complète, e2e transversal borne → KDS → OSS → caisse, diff frozen sur toute la plage, chaîne NF525 | séquentiel | **Deux cycles identiques à P0+P1 = 0** |

**Clôture de vague** : toutes tâches PASS ou échec documenté ; diff frozen-zone = 0 ; chaîne NF525 inchangée ou en ajout seul ; portail visuel déclenché et **captures lues** ; contestation RED-team traitée ; BRAIN §2/§3 à jour.

**Interruption** : committer le partiel (`wip(vague N): …`), écrire `reports/goal-8axes-2026-08-05/INTERRUPT_<vague>.md` (dernier SHA vert, dernière tâche + statut, tâche suivante), mettre à jour BRAIN §2. À la reprise : lire le manifeste, `git status`, refaire la dernière tâche en fumée, continuer.

**Blocage après 3 cycles de soin** : STOP, agent `Plan` pour analyse de cause, `STUCK_<vague>.md`, remonter à l'owner avec 4 options (accepter documenté / pivot / reporter V1.0.X / gate humaine). **Ne pas choisir seul.**

---

## §G — PORTAILS OWNER

| Gate | Description | QUI | QUOI | OÙ | Statut | Bloque |
|---|---|---|---|---|---|---|
| **G-1** | Révoquer le mandat « KDS 3 cartes max » (`c70b1e518`, 2026-07-05) au profit de 6 cartes défilables | Owner | Confirmation écrite | BRAIN §6 Journal des décisions | **EN ATTENTE** | Vague 5 |
| **G-2** | Autoriser la modification de `PaymentComponent.vue` (frozen §7) | Owner | LOCK contresigné (skill `lock-plan`) | `plans/LOCK_PAYMENT_8AXES.md` §10 | **EN ATTENTE** | Vague 6 |
| **G-3** | Autoriser `pos-wizard.js` **si et seulement si** l'axe 8 ne peut être résolu en data seule | Owner | LOCK ou renoncement écrit | idem | Conditionnelle | Vague 3 |
| **G-4** | Résolution réelle de l'écran cuisine | Owner | Valeur en pixels | BRAIN §2 | **EN ATTENTE** | T-1.3.2 |
| **G-5** | `PosV5TrancheRow.vue` (frozen) si le multi-tranches à l'encaissement l'exige | Owner | LOCK | idem G-2 | Conditionnelle | Vague 6 |
| **G-6** | Arbitrer T-5.1.1 : commande en **brouillon** (recommandé) vs **création après paiement** | Owner | Choix écrit | BRAIN §6 | **EN ATTENTE** | Vague 7 |
| **G-7** | Prix de **Maïs** et **Olives** (non chiffrés dans la demande) | Owner | Deux montants en euros | BRAIN §2 | **EN ATTENTE** | T-8.4.1 |
| **G-8** | Déploiement VPS (local en avance sur `827afae93`) | Owner | Autorisation explicite de pousser | Message de commit + journal de déploiement | **EN ATTENTE** | Après vague 8 |
| **G-9** | Règle métier attendue quand un SKU « Menu » séparé coexiste avec un produit portant des addons menu (D-2), et quand « Frites » produit coexiste avec « FRITES » composant (D-3) — fusionner ou distinguer ? | Owner | Règle écrite | BRAIN §6 | **EN ATTENTE** | T-4.2.2 / T-4.2.3 |
| **G-10** | Désigner, sur un **ticket imprimé réel**, la ligne d'en-tête à mettre en gras sur une seule ligne — « CUISINE » l'est déjà | Owner | Photo ou désignation de la ligne | `reports/goal-8axes-2026-08-05/` | **EN ATTENTE** | Sub 4.3 |

**Pendant qu'une gate est en attente** : les vagues qui n'en dépendent pas s'exécutent. Concrètement, les **vagues 1 à 4 sont lançables immédiatement** ; 5, 6 et 7 attendent respectivement G-1, G-2 et G-6.

---

## §R — RÉFÉRENCES

`CONSTITUTION.md` · `PROJECT_BRAIN.md §2` · `SYSTEM_MAP.md` · `SYNC_CONTRACT.md` · `PARALLEL_PROTOCOL.md` · `CLAUDE.md §§6,7,8,10,12,13`. Skills : `ultra-audit-profond` · `test-e2e` (§7) · `lock-plan` (G-2/G-3/G-5) · `verify-before-report`.
Précédents à ne pas répéter : sauce frites affichée non facturée (07-29) · nom de sauce perdu au ticket web (07-21) · worker de file à l'arrêt masqué par soketi (07-22) · agent auditant un arbre périmé · bundle POS périmé servi malgré correctif.

---

## §F — RÈGLE FINALE

**Terminé** si et seulement si : (1) les 8 axes ont une **preuve directe** — pas un test vert, une preuve ; (2) deux cycles d'audit consécutifs à P0+P1 = 0 avec findings **identiques** ; (3) diff frozen-zone **nul** hors LOCK contresigné ; (4) chaîne NF525 intacte ou en ajout seul ; (5) chaque surface touchée **capturée, lue, analysée** ; (6) BRAIN §2/§3/§6 à jour, gates non levées listées **comme telles**.

**Partiel vaut mieux que faux. Bloqué vaut mieux que dangereux en silence.**
Aucun axe n'est déclaré terminé sur la foi d'un test vert seul — c'est exactement ainsi que l'owner a été trompé par le passé.
