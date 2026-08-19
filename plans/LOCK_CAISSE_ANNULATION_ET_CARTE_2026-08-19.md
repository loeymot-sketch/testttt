# LOCK — Annulation après « Prêt » + encaissement carte sans code à 4 chiffres

- **Identifiant** : `LOCK-OSM-CANCEL-AFTER-READY` + `LOCK-PAY-NO-CARD4`
- **Date** : 2026-08-19
- **Branche** : `pos/category-first-caisse-2026-06-23`
- **Gate propriétaire** : **OBTENU** — questions posées et répondues explicitement en séance
  (voir §2). Les deux réponses ont été « ouvrir » / « supprimer le champ ».
- **Origine** : `/goal` propriétaire du 2026-08-19, rapport terrain dicté.

---

## 1. Fichiers gelés touchés (CLAUDE.md §7)

| Fichier | Zone §7 | Nature de la modification |
|---|---|---|
| `app/Domain/Order/OrderStateMachine.php` | Backend multi-tenant + payment critical | +2 arêtes de transition |
| `resources/js/components/admin/pos/PaymentComponent.vue` | POS payment component | −1 champ de saisie, −1 pavé en mode carte, 1 computed assoupli |

Aucun autre fichier gelé n'a été modifié. En particulier, **restent intacts** :
`FiscalSequenceService`, `ZReportService`, `AuditLogService`, `PricingService`,
`BranchScope`, `IdempotencyKeyMiddleware`, `SealedOrderGuard`, `pos-wizard.js`,
`pos-wizard.css`, `admin-pos-v4.blade.php`, `PosV5TrancheRow.vue`.

---

## 2. Demande du propriétaire (verbatim, dictée)

> « l'écran de préparation pour visualiser les commandes en préparation […] j'arrive
> pas à annuler les commandes qu'ils ont passé de certaines heures […] je veux
> pouvoir les annuler si je veux »

> « j'arrive pas à valider l'encaissement par carte bleue, sauf si je tape quatre
> chiffres […] pourtant si je tape n'importe quel code ça passe. Moi je voulais
> directement quand je clique sur carte bleue ça passe directement, pas besoin de
> taper quatre chiffres »

Gate confirmé en séance :
- Annulation → **« Oui, ouvrir »** (Prête→Annulée ET En livraison→Annulée).
- Carte → **« Supprimer le champ »**.

---

## 3. Diagnostic mesuré AVANT modification

### 3.1 Annulation

Sonde exécutée sur le code réel (`OrderStateMachine::allows`) :

```
Peut-on ANNULER (statut 16) depuis ... ?
EN ATTENTE       ( 1) : caissier=OUI   admin=OUI
ACCEPTEE         ( 4) : caissier=OUI   admin=OUI
EN PREPARATION   ( 7) : caissier=OUI   admin=OUI
PRETE            ( 8) : caissier=NON   admin=NON     ← blocage
EN LIVRAISON     (10) : caissier=NON   admin=NON     ← blocage
LIVREE           (13) : caissier=NON   admin=NON
```

Le bypass Admin (`OrderStateMachine.php`, case CANCELED/REJECTED/RETURNED) ne
couvre que les statuts **déjà terminaux** : il ne débloquait donc ni PREPARED ni
OUT_FOR_DELIVERY. Aucune fenêtre temporelle n'est en cause (grep
`cancel_window|minutes` dans `app/` et `config/` → 0 résultat).

**Aggravation UX** : `PosOrdersTrackerComponent.vue` affiche le bouton Annuler sur
toutes les voies sauf « Livrés » (`v-if="col.id !== 'delivered'"`). Le propriétaire
cliquait donc un bouton visible qui échouait en 422
« Transition de statut invalide » (`lang/fr/all.php`, `invalid_status_transition`).

**Chronologie réelle** : le cuisinier bipe « Prêt » sur le KDS au bout d'environ
dix minutes → la commande devient PREPARED → elle est inannulable pour toujours.
D'où « les commandes passées il y a quelques heures ».

État de la base au moment du diagnostic : **857 commandes** en statut non terminal
(248 PENDING, 159 ACCEPT, 341 PREPARING, 109 PREPARED), dont **109 définitivement
inannulables**.

### 3.2 Carte à 4 chiffres

| Question | Réponse mesurée | Preuve |
|---|---|---|
| Le code est-il validé métier ? | **Non** | `PosOrderRequest` : `nullable\|numeric\|min_digits:4\|max_digits:4` — contrôle de FORME seul, « 0000 » passe |
| Entre-t-il dans la chaîne d'audit ? | **Non** | `pos_payment_note` absent de tout `app/Services/Fiscal/` ; le payload `order.created.pos` scelle `pos_payment_method`, pas la note |
| Est-il ventilé au Z ? | **Non** | `ZReportService` ventile sur `pos_payment_method` |
| Le serveur l'accepte-t-il vide ? | **Oui, depuis le 2026-08-05** | sentinelle `PosCardDeclarativeNoNoteTest` : vente carte sans note ⇒ 201 |
| Une vente sans TPE passe-t-elle ? | **Oui, depuis le 2026-08-10** | sentinelle `PosCardSaleWithoutTerminalTest` |

Origine du champ : bloc importé le 2026-03-06 avec le socle éditeur — reliquat de
template, jamais un contrôle métier.

---

## 4. Portée exacte des modifications

### 4.1 `OrderStateMachine::allows()`

```
+ PREPARED         → CANCELED
+ OUT_FOR_DELIVERY → CANCELED
```

`DELIVERED → CANCELED` reste **volontairement fermé** : « livrée » signifie remise
au client ; sa seule sortie légitime est `RETURNED` (remboursement tracé).

### 4.2 `PaymentComponent.vue`

- Suppression du bloc `#cardInput` (label + input).
- Pavé numérique : `v-if="paymentMode === 'cash'"` (au lieu de `cash || card`).
- `canConfirmCard` → `return true` : le bouton Confirmer ne peut plus devenir
  **mort sans explication** quand `GET admin/payment-terminals` renvoie une liste
  vide. Le sélecteur de TPE et son bandeau d'avertissement restent affichés — ils
  informent, ils ne bloquent plus.

Aucun changement backend n'a été nécessaire.
`min_digits:4|max_digits:4` est **conservé** côté serveur : la sentinelle
`PosCardDeclarativeNoNoteTest` verrouille le contrat « si fournie, exactement
4 chiffres ». La règle devient inatteignable depuis l'UI mais reste vraie.

---

## 4bis. CORRECTION DU PRÉSENT DOCUMENT — un 4ᵉ effet avait été manqué

> Ajouté le 2026-08-19 après un red-team adverse de ce LOCK et du diff qu'il couvre.
> La section 5 ci-dessous affirmait « ouvrir cette arête ne retire AUCUNE protection ».
> **C'était incomplet.** Les trois gardes citées sont bien intactes, mais il existait un
> quatrième effet — non pas une garde, une **compensation** — que l'analyse initiale
> n'avait pas vu.

**Le stock d'un plat DÉJÀ CUISINÉ était restitué.**

Le stock part à la CRÉATION de la commande (`DecrementStockOnOrderCreated` sur
`OrderCreated`). L'annulation dispatchait `OrderCanceled` **inconditionnellement**, et
trois écouteurs rendaient tout sans jamais regarder le statut de départ :
`ReleaseStockOnOrderCanceled`, `ReleaseAvailabilityOnOrderCanceled`,
`ReverseRawMaterialsOnOrderCanceled`.

Tant que l'annulation n'était possible qu'AVANT « prêt », c'était juste : rien n'était
encore transformé. Depuis l'ouverture de PREPARED→CANCELED, le pain, la viande et la
sauce d'un plat que la cuisine a déclaré prêt revenaient au stock — `on_hand` remontait
et la disponibilité se **ré-ouvrait** : la caisse et la borne proposaient un produit qui
n'existe plus.

Preuve, commande réelle **#6598** (celle-là même qui prouve le §6.1) :

```
stock_movements ref=6598 :
   delta=-1 reason=order_created   2026-08-19 08:50:33
   delta=+1 reason=order_canceled  2026-08-19 09:41:19   ← 51 min APRÈS le bip « Prêt »
```

Ampleur mesurée : **252 unités** fantômes sur les 109 commandes PRÊTES en base.

**Correctif appliqué** (commit `2853dab49`) : `OrderCanceled` porte désormais le statut
quitté (`?int $fromStatus`, facultatif). Au-delà de PREPARED, on ne restitue **rien** et
on inscrit une **perte** (`StockOutflow::TYPE_WASTE`, `stock_decremented = true`) — le
stock reste physiquement juste ET l'annulation garde une trace chiffrable dans le
grand-livre. Les 8 autres sites de dispatch ne transmettent pas le statut : leur
comportement historique est strictement conservé.

Seuil retenu : **PREPARED**, pas PREPARING — il correspond exactement aux deux arêtes
ouvertes par ce LOCK. Annuler depuis ACCEPT ou PREPARING restitue le stock comme avant.

**Leçon à retenir** : une garde n'est pas la seule chose qu'une arête de transition peut
concerner. Une **compensation** (rendre le stock, reprendre des points, sortir de
l'argent) est écrite en supposant un contexte ; élargir la machine à états change ce
contexte sans toucher une seule ligne de la compensation.

---

## 5. Ce qui n'a PAS été affaibli

Les gardes qui protègent réellement l'argent et le fiscal sont **en aval** de la
machine à états et **inchangées** :

1. **Motif obligatoire** — `OrderStateMachine::requiresReason()` + `OrderService`.
2. **Permission `pos-refund`** si la commande est déjà PAYÉE — `PosOrderController`
   (`$movesCashOnStatusChange`). Annuler une commande payée reste un geste
   privilégié.
3. **`SealedOrderGuard`** — refus absolu de toute mutation en place si la commande
   est scellée dans un Z clôturé. C'est la garde NF525 ; elle n'a pas été touchée.
4. **Trace fiscale carte** — `orders.pos_payment_method`, `fiscal_sequence_no` et
   la ligne `audit_logs` chaînée HMAC sont posés par `OrderService::posOrderStore`,
   hors du périmètre d'un patch frontend.

---

## 6. Preuves d'exécution (terrain, navigateur réel)

### 6.1 Annulation après « Prêt »

Commande #6598 passée à la caisse, bipée « Prêt » depuis le KDS, puis annulée
depuis le suivi commandes avec le motif « Client injoignable ».

```
TRACE DES TRANSITIONS (order_status_transitions) :
  EN ATTENTE     -> EN PREPARATION  motif='auto_prepare_on_paid'  acteur=1
  EN PREPARATION -> PRETE                                          acteur=1
  PRETE          -> ANNULEE (16)    motif='Client injoignable'     acteur=1
```

### 6.2 Encaissement carte sans code

Commande #6601 encaissée par carte en **deux clics** (Carte → Confirmer) :

```
mode de reglement (pos_payment_method) : 2      (CARTE)
note carte (pos_payment_note)          : NULL
NUMERO FISCAL (fiscal_sequence_no)     : 2722
audit_logs order.created.pos : prev=1a656adf5e22... -> current=5240199fda5d...
             payload : order_id=6601  pos_payment_method=2  total=1.9
```

### 6.3 Intégrité fiscale après modifications

```
php artisan fiscal:verify-chain --all
SWEEP COMPLETE — CHAIN OK on every active branch (6 total)
```

### 6.4 Suites de tests

| Suite | Résultat |
|---|---|
| `tests/Unit/Domain/Order` | 95 tests OK |
| `tests/Feature/Order` | 104 tests OK |
| `tests/Feature/Delivery` | 50 tests OK |
| `tests/Feature/Pos` | 325 tests OK |
| `tests/Feature/Refund` | 33 tests OK |
| `tests/js/posPaymentCardNoFourDigits.spec.js` (nouveau) | 5 tests OK |

---

## 7. Cliquets mis à jour

- `OrderStateMachineTest::legalPairsProvider` — 2 paires ajoutées.
- `OrderStateMachineTest::test_legal_transitions_matrix_contains_exactly_expected_count`
  — **11 → 13**, avec l'historique du cliquet en commentaire.
- `tests/js/posPaymentCardNoFourDigits.spec.js` — **nouveau** cliquet empêchant la
  réintroduction silencieuse du champ 4 chiffres et la remise en place d'un bouton
  Confirmer désactivable.

---

## 8. Retour arrière

Les deux modifications sont indépendantes et réversibles isolément.

- Annulation : retirer les deux `CANCELED` ajoutés dans `allows()` et remettre le
  cliquet à `assertCount(11, …)`.
- Carte : restaurer le bloc `#cardInput`, remettre
  `v-if="paymentMode === 'cash' || paymentMode === 'card'"` sur le pavé, et
  rétablir `canConfirmCard` à `selectedTerminalId !== null && Number(...) > 0`.
  Puis `npm run prod` — le composant vit dans le chunk asynchrone `pos-shell`,
  **un correctif non recompilé est inactif**.

---

## 9. Reste ouvert (hors périmètre de ce LOCK)

- Le tableau de suivi ne charge que **le jour calendaire courant**
  (`_todayRange()`), décision documentée pour des raisons de charge. Une commande
  prise à 23 h 50 disparaît à minuit, et les 857 commandes non terminées
  antérieures restent invisibles. À arbitrer : journée de SERVICE plutôt que jour
  calendaire, et/ou filtre explicite « en souffrance ».
- La permission `pos-refund` n'est pas accordée au rôle POS Operator (refus
  délibéré, `RolePermissionTableSeeder`). Si le propriétaire opère sous un compte
  caissier, annuler une commande **payée** lui renverra un 403.
