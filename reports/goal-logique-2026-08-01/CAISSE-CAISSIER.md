# AUDIT LOGIQUE MÉTIER — CAISSE / RÔLE CAISSIER
**Date** : 2026-08-02 · **Mandat owner** : « CONTRÔLE TOTAL — maximum de contrôle pour le caissier »
**Question directrice** : *en tant que caissier en rush, est-ce que je garde la maîtrise de TOUT ce qui se passe ?*

**Environnement de preuve** : backend local `php artisan serve 127.0.0.1:8766` (APP_URL du dépôt),
DB `foodking_e2e` (MySQL), worker `queue:work --queue=high,default` actif, Redis UP,
`POS_SIMULATION_HARDWARE=true`, `APP_ENV=local`.
**Compte joué** : `pos@lecayenne.fr` (rôle **POS Operator**, 9 permissions —
`availability_toggle, dashboard, kitchen-display-system, online-orders, order-status-screen, pos,
pos-discount-up-to-10, pos-orders, pos.redeem-loyalty`).

**Méthode** : parcours réels au navigateur (Playwright, specs `tests/Playwright/zz-audit-caissier-s*-2026-08-02.spec.js`),
captures dans `reports/goal-logique-2026-08-01/shots/`, vérification systématique en base
(`php artisan tinker`) et lecture du code (`file:line`). Toute affirmation non reproduite est
marquée **non vérifié**.

**Données créées (préfixe ZZ-TEST)** : commande caisse `#0208266053` (Cayenne Mixte+Algérienne+Cheddar,
espèces 20 € → rendu 11,70 €), commande **web** `#0208266054 / A0041` (client `ZZ-TEST-WEB Manon`,
tél `0699887766`), commande **borne** `#0208266055 / A0042` (2× Tacos M), commande split
`#0208266056 / A0043` (espèces 2 € + carte 2 €), sortie hors-vente `stock_outflows#1`,
commande parkée `pos_parked_orders#45` (`ZZ-TEST-PARK-1`).

---

## DÉCOMPTE

| Sévérité | Nombre |
|---|---|
| **P0** | **0** |
| **P1** | **4** |
| **P2** | **9** |
| P3 | 2 |

Le cœur money-path est **sain** : total juste au centime à chaque étape (panier → wizard → ticket →
DB), séquence fiscale NF525 allouée et monotone (2698 → 2699 → 2700), TVA 10 % ventilée,
rendu monnaie exact, split cash+carte au centime, annulation d'une commande non encaissée
n'écrit aucun mouvement de tiroir. **Aucun P0 argent-faux / commande-perdue / NF525 n'a été
reproduit.** Les P1 portent tous sur la **perte de contrôle du caissier**, pas sur la justesse des
montants.

---

## P1 — LE CAISSIER PERD LE CONTRÔLE OU DOIT CONTOURNER

### P1-1 — « Ouvrir tiroir » (no-sale) : **aucune trace**, alors que l'UI promet « Action tracée »

Le bouton `💵 Ouvrir tiroir` de la barre caisse est **100 % client**. Il n'existe **aucun**
endpoint, colonne ou action d'audit côté serveur.

- `resources/js/components/admin/pos/PosComponent.vue:4607-4624` — `triggerNoSaleOpenDrawer()`
  n'appelle que `kioskHardwareOpenDrawer()` ; **zéro `axios`**.
- `resources/js/services/kioskHardware.js:268-289` — `openDrawer()` parle au bridge Electron ou,
  à défaut, au pont d'impression local `:9100/raw`. Jamais au backend.
- `resources/js/languages/fr.json:251` — `"no_sale_hint": "Ouvre le tiroir-caisse sans créer de
  commande (pour rendu de monnaie hors-vente). **Action tracée.**"` (idem `en.json:250`).
- `grep -rn "no_sale" app/ routes/ database/` → **0 résultat**.

**Preuve d'exécution** (spec `zz-audit-caissier-s6` test S6b, capture `shots/s6b-01-no-sale.png`) :
clic à 05:22 → `toast: null`, aucun modal. En base au même instant :
dernier `cash_movements` = id 480 à **04:57**, `audit_logs` ne contient que des `user.login`.
Aucune ligne créée.

**Impact contrôle total** : l'ouverture de tiroir hors-vente est LE vecteur de détournement d'un
POS. L'owner croit — parce que l'interface le lui dit — pouvoir auditer qui a ouvert le tiroir et
quand. Il ne le peut pas. Le libellé constitue une **fausse assurance** sur une action de caisse.

---

### P1-2 — Split avec tranche ESPÈCES : **aucun mouvement de tiroir** en mode simulation (asymétrie avec le paiement espèces simple)

Un encaissement espèces **simple** écrit un `cash_movement` IN ; le **même euro** encaissé dans une
tranche d'un multi-paiement n'en écrit **aucun**, dès que `pos.simulation_hardware` est vrai.

- `app/Services/Payments/SplitPaymentService.php:236-252` — si
  `config('pos.simulation_hardware') === true`, la recherche de session caisse est **entièrement
  sautée** → `$cashSession = null` → le bloc d'écriture `lines 317-328` (gardé par
  `$cashSession !== null`) ne s'exécute jamais.
- `app/Services/PaymentService.php:566-569` — le chemin espèces simple, lui, ne fait que
  **rétrograder `strict` → soft** : le mouvement est écrit normalement si une session est ouverte.
- `app/Providers/AppServiceProvider.php:178` — le boot-guard qui interdit
  `POS_SIMULATION_HARDWARE=true` ne s'applique **que** `if (app()->environment('production'))`.
  La V1 est une installation **LOCALE mono-poste** ; le `.env` du dépôt est `APP_ENV=local` +
  `POS_SIMULATION_HARDWARE=true` — donc le garde-fou **ne se déclenche pas**.

**Preuve d'exécution** (spec `zz-audit-caissier-s10`, capture `shots/s10-02-apres-confirm.png`) :
commande `#0208266056`, total 4,00 €, tranches Espèces 2,00 € + Carte 2,00 €, ticket
« Détail paiement : Espèces 2,00 € / Carte 2,00 € », `fiscal_sequence_no = 2700`.
En base :
```
order_payments : 2 lignes  (mode 1 amount 2.00 tendered 2.00 | mode 2 amount 2.00 terminal_id 1)
cash_movements  : []        ← les 2,00 € physiques n'entrent JAMAIS au tiroir
```
Comparaison de contrôle : la commande espèces simple `#0208266053` a bien produit
`cash_movements` id 478, `order_payment / in / 8.30`.

**Impact contrôle total** : sur une caisse installée en `APP_ENV=local` (posture V1 documentée), le
montant attendu de la session est sous-compté du total de toutes les tranches espèces. À la
clôture, le caissier est **structurellement accusé d'un manquant** qu'il n'a pas fait, et l'owner
pilote un tiroir faux. En production stricte (`APP_ENV=production`, simulation interdite au boot)
le chemin est correct — d'où P1 et non P0.

---

### P1-3 — Le caissier **ne peut pas rembourser** et il n'existe aucune élévation manager sur la caisse

- `database/seeders/RolePermissionTableSeeder.php:96-117` — le rôle POS Operator reçoit
  `dashboard, pos, pos-orders, pos-discount-up-to-10, pos.redeem-loyalty, online-orders`.
  `pos-refund` est explicitement **exclu** (commentaire ligne 86-89 : « mass-refund vector
  mitigation », accordé à Branch Manager `:89` et Admin).
- `resources/js/components/admin/posOrders/PosOrderShowComponent.vue:583-593` (`canShowRefund`)
  + `:198` (`v-if="canShowRefund"`) — le CTA `pos-order-refund-open` est masqué sans la permission.

**Preuve d'exécution** (spec `zz-audit-caissier-s4` test S4b, capture `shots/s4b-01-order-show.png`) :
sur `/admin/pos-orders/show/6053` (commande **Payée**, espèces, 8,30 €), l'audit relève
`refund_cta => null` — le bouton n'existe pas dans le DOM. Vérification base :
`$u->can('pos-refund')` → **NO**.

**Aucun mécanisme d'élévation** : `grep` sur les composants caisse ne trouve aucun dialogue
PIN/manager-override. La seule permission d'élévation existante
(`cash.reconcile.variance.override`, seeder `:81`) concerne la variance de clôture, pas le refund.

**Impact contrôle total** : c'est un arbitrage anti-fraude défendable, mais il contredit
frontalement le mandat. En rush, un client qui veut être remboursé bloque la file jusqu'à ce
qu'un manager se connecte physiquement. Il n'existe aucun chemin « manager valide par PIN sur le
poste du caissier ». **Décision owner requise** : accorder `pos-refund` au caissier, ou implémenter
une élévation PIN.

---

### P1-4 — Détail d'une commande dans « À encaisser au comptoir » : composition **illisible** (`undefined`)

L'écran où le caissier vérifie ce qu'il encaisse affiche les variations et les extras cassés.

- `resources/js/components/admin/pos/PosComponent.vue:1605`
  ```js
  item.item_variations.map(variation => `${variation.variation_name || 'Option'}: ${variation.name}`)
  ```
  Le template attend `{variation_name: <libellé>, name: <valeur>}`. L'API renvoie le
  **composition_snapshot** (`app/Http/Resources/OrderItemResource.php:76-82`,
  `resolveVariationsForApi()` priorise le snapshot) dont les clés sont
  `{attribute_name: "Viande 1", variation_name: "Viande Hachée"}` → le rendu devient
  **`"Viande Hachée: undefined"`**.
- `resources/js/components/admin/pos/PosComponent.vue:1609` — même bug pour les extras :
  le template lit `extra.name`, le snapshot fournit `extra_name` → `"Extras:, ,"`.

**Preuve d'exécution** (spec `zz-audit-caissier-s11`, capture `shots/s11-01-details-expanded.png`) :
```
1× Cayenne
Variations: Algérienne: undefined, Pain: undefined
Extras:, ,
```
`undefined_occurrences = 4` sur les 3 premières commandes développées.
Vérification base — snapshot réel de la commande borne ZZ-TEST `#6055` :
`{"attribute_name":"Viande 1","variation_name":"Viande Hachée"}`.

**Portée** : `3375 / 3413` lignes `order_items` possèdent un `composition_snapshot` → le bug touche
**toutes les commandes modernes**, tous canaux, pas un cas limite.

**Impact contrôle total** : avant de prendre l'argent d'une commande borne/web/téléphone, le
caissier ne peut pas vérifier la composition à l'écran. Il encaisse en aveugle ou doit réimprimer
un ticket cuisine.

---

## P2 — FRICTION / PERTE DE LISIBILITÉ

**P2-1 — La modale d'encaissement comptoir annonce « Commande Borne » pour une commande WEB.**
`PosCounterCollectModal.vue:63` affiche `$t('label.cc_source_kiosk')` **en dur** (= « Borne »,
`fr.json:631`) et le titre `label.encaisser_mode_title` = « Encaisser la commande borne »
(`fr.json:619`), quelle que soit l'origine réelle. Preuve (spec S3a) : la commande `A0041`
(`source_surface = 'web'`) s'affiche « 💳 Encaisser La Commande **Borne** / N° A0041 / **BORNE** ».
La note du mouvement de tiroir hérite du même mensonge :
`PosCounterCollectModal.vue:553` → `cash_movements.notes = "Encaissement borne au comptoir (SSOT modal)"`
sur une vente web (vérifié en base sur `order_id 6054`).

**P2-2 — Annuler une commande JAMAIS encaissée la marque « Remboursé ».**
`app/Services/PaymentService.php:851-853` force `payment_status = REFUNDED` sur
`cancelCounterPayment()`. Preuve : commande borne `#0208266055`, jamais encaissée
(`cash_movements` vide, `fiscal_sequence_no = null`), apparaît dans `/admin/historique` avec
`PAIEMENT = Remboursé` (capture `shots/s4a-02-recherche.png`). Un contrôle de caisse lit une
sortie d'argent qui n'a jamais eu lieu. *(Le comportement monétaire est correct : aucun mouvement
n'est écrit — c'est l'étiquette qui trompe.)*

**P2-3 — Annulation possible sans motif hors interface.** Le bouton « Oui, annuler la commande »
n'est **pas** désactivé quand le motif est vide (relevé live : `confirm_without_reason =>
{disabled: false}`) ; la garde ≥ 3 caractères vit uniquement dans le handler JS
(`PosComponent.vue:4202-4209`). Côté serveur, `routes/api.php:1000-1002` valide
`'reason' => ['nullable', 'string', 'max:255']` → une annulation sans motif est acceptée. La
traçabilité (audit `order.counter_payment_canceled`, `PaymentService.php:866-878`) enregistre alors
`reason: null`.

**P2-4 — En rush, le caissier ne voit que 4 lignes sur 15-19.** Les trois panneaux de l'écran
caisse sont tronqués à `slice(0, 4)` : prêts à livrer `PosComponent.vue:376`, à encaisser `:440`,
commandes web `:547`. Relevé live : « À ENCAISSER — COMPTOIR (**17**) » n'affiche que 4 cartes +
« Voir plus (13) → » ; « COMMANDES WEB · **19** » n'en affiche que 4. Les files sont bien en FIFO
`created_at ASC` cap 200 côté serveur (`routes/api.php:895,935`) — c'est l'affichage qui masque.
Conséquence directe : notre commande web `A0041` (la plus récente) était **invisible** depuis
l'accueil caisse ; il a fallu passer par « Suivi commandes ».

**P2-5 — « Mettre en attente » repose sur `window.prompt()`.**
`PosComponent.vue:4361` : `const label = window.prompt(promptLabel, '')`. Sur une caisse tactile,
c'est un dialogue système non tactile ; si l'opérateur coche « empêcher cette page de créer
d'autres dialogues » (proposé par Chrome après quelques dialogues), `prompt()` renvoie `null` et
`:4362-4364` **annule silencieusement le parking** (aucun toast d'erreur). Un shell Electron ne
supporte pas `prompt()` du tout. Preuve : avec le dialogue auto-dismissé (S7c), le parking n'a rien
créé et rien signalé ; avec le dialogue accepté (S8a/S9a), tout fonctionne
(`pos_parked_orders#45`, restauration exacte de la ligne 4,00 €).

**P2-6 — Deux onglets caisse = deux paniers divergents.** Preuve (spec S7b) : onglet 2 ajoute une
Petite Frites (1 ligne / 2,50 €) ; l'onglet 1, resté ouvert, affiche toujours 0 ligne / 0,00 €.
Le panier est persisté par scope `pos_cart:b<branch>:u<user>` en `localStorage`
(`resources/js/store/modules/posCart.js:30-47`) sans synchronisation inter-onglets : le dernier
onglet qui écrit écrase l'autre. Risque : encaisser le mauvais panier.

**P2-7 — Un produit passé en 86 pendant la prise de commande vide la ligne du panier sans
confirmation ni possibilité de forcer.** Preuve (spec S5b, captures `shots/s5b-01/02`) :
panier `1 ligne / 6,00 €` → après passage en rupture depuis le panneau,
`0 ligne / 0,00 €` avec la bannière « 2 articles indisponibles : Cheese Burger, Fish Burger ».
Protection légitime, mais le caissier n'a **aucun moyen de vendre la dernière portion déjà
préparée** — il doit réactiver le produit, encaisser, puis le remettre en 86.

**P2-8 — Clic sur une tuile 86 : refus totalement silencieux.** Preuve (spec S5a, capture
`shots/s5a-04-clic-tuile-86.png`) : la tuile porte bien le badge « ÉPUISÉ », la classe
`is-unavailable` et `aria-disabled="true"`, le clic n'ajoute rien au panier — mais
`toast: null`. En rush, l'opérateur retape sans comprendre pourquoi rien ne se passe.

**P2-9 — Pas d'indicateur de santé système sur l'écran caisse.** `PosSystemHealthPill.vue` n'est
monté que dans `PosOrdersTrackerComponent.vue:508,548` (écran « Suivi commandes »). Sur
`/admin/pos`, relevé live `health_pill => []`. L'API répond pourtant
(`GET admin/pos/system-health` → 200, `{"overall":"ok", checks:{sync, fiscal, stock, aging}}`).
Le caissier qui reste sur l'écran de prise de commande ne verra pas une panne de synchro.

---

## P3 — COSMÉTIQUE

**P3-1 — « Opérateur » du ticket = le client, pas le caissier.** `ReceiptDataService.php:70`
renseigne `operator_name = optional($order->user)->name`. Sur la commande caisse `#6053`,
`user_id = 2` (« Client passage », le walk-in customer) et `creator_id = 3` (« Caissier Le
Cayenne ») → le ticket imprime **« Opérateur: Client passage »**. Le vrai opérateur est stocké
(`creator_id`) mais jamais affiché. Le rendu ESC/POS a la même source
(`OrderReceiptEscPosRenderer.php:223-224`, préfixe « Caissier : »).

**P3-2 — Une commande borne affiche « Admin Le Cayenne » comme client** dans le suivi et
l'historique (le nom du compte machine borne). Cosmétique, mais brouille la colonne CLIENT.

---

## LISTE DES TROUS DE CONTRÔLE (cœur du mandat « contrôle total »)

Ce que le caissier **NE PEUT PAS** faire aujourd'hui, et devrait pouvoir sous ce mandat :

1. **Rembourser une commande** — permission `pos-refund` non accordée, CTA masqué, aucune
   élévation PIN manager sur le poste. *(P1-3)*
2. **Ouvrir le tiroir de façon tracée** — l'action existe mais n'écrit rien ; l'owner ne peut ni
   auditer ni rapprocher. *(P1-1)*
3. **Modifier une commande existante** (ajouter/retirer/changer une ligne), quel que soit le canal.
   Vérifié : **0** route `PUT`/`PATCH` dans `routes/api.php` ; le groupe `pos-order`
   (`routes/api.php:1098-1129`) n'expose que `change-status`, `change-payment-status`,
   `select-delivery-boy`, `refund-with-counter-entry`, `redeem-loyalty`, `destroy`,
   `reorder-items`. Aucune UI d'édition de ligne (relevé live sur la fiche commande :
   les seuls boutons « Modifier » sont ceux du profil utilisateur). Le contournement est
   annuler + reprendre la commande. *(Doctrine NF525 : une commande scellée est immuable —
   mais le caissier doit alors gérer un client mécontent sans outil.)*
4. **Vendre un produit passé en 86** (dernière portion déjà préparée) — la ligne est retirée du
   panier d'office, sans override. *(P2-7)*
5. **Voir toute la file d'encaissement d'un coup d'œil** — 4 lignes affichées sur 17. *(P2-4)*
6. **Vérifier la composition d'une commande borne/web avant d'encaisser** — le détail affiche
   `undefined`. *(P1-4)*
7. **Corriger une remise après encaissement** — aucune route ; la seule voie est le refund,
   interdit au caissier. *(dérivé de P1-3)*
8. **Annuler une commande DÉJÀ encaissée** — l'annulation caisse (`counter-collect/cancel`) ne
   couvre que les commandes **non** encaissées (`assertCounterDeferredOrder`,
   `PaymentService.php:817`) ; une vente payée ne peut être défaite que par le refund
   NF525 → interdit au caissier. *(dérivé de P1-3)*
9. **Élévation manager par PIN** — inexistante pour l'ensemble des points ci-dessus.
10. **Savoir que la synchro est tombée sans quitter l'écran de vente** — pastille santé absente
    de `/admin/pos`. *(P2-9)*

---

## CE QUI MARCHE (contrôle confirmé, à ne pas casser)

- **Total juste au centime, de bout en bout.** Cayenne 7,40 € + Cheddar 0,90 € = panier 8,30 € →
  ticket `SOUS-TOTAL 7,55 € / VAT 10 % 0,75 € / TOTAL 8,30 €` → base `orders.total = 8.300000`,
  `order_items.total_price = 8.30`. Rendu monnaie sur 20 € : **11,70 €** affiché et imprimé.
- **NF525** : séquences 2698 → 2699 → 2700 monotones, empreinte audit sur le ticket, aucune
  séquence allouée sur les commandes non encaissées.
- **Encaissement d'une commande WEB au comptoir** : 7,00 € dû, 10,00 € reçus → rendu 3,00 €
  affiché ; base : `payment_status = PAID`, `fiscal 2699`, `cash_movements IN 7.00`
  (le rendu n'est pas banké — correct).
- **Nom + téléphone du client web visibles** en caisse : le tracker affiche
  `N°A0041 🌐 ZZ-TEST-WEB Manon 0699887766` (fallback `OrderDetailsResource.php:139-140` sur le
  compte web) et le panneau « à encaisser » porte le badge `pos-shortcut-web-contact-<id>`
  (`PosComponent.vue:456-462`).
- **Accepter / encaisser / annuler une commande web ou borne** depuis la caisse : fonctionnel,
  motif obligatoire côté UI, audit `order.counter_payment_canceled` avec motif, remboursement
  des points fidélité symétrique (`PaymentService.php:840-846`).
- **Rupture 86** : le caissier marque et réactive un produit (`availability_toggle` accordé) ;
  la tuile passe en « ÉPUISÉ » + `aria-disabled` immédiatement ; la provenance manuelle est
  conservée (`item_branch_availability.manual_unavailable_since`) donc un restock ne réactive
  pas en douce.
- **F5 en pleine commande : le panier survit** (1 ligne / 4,00 € intacte après rechargement,
  `posCart` persisté par scope caissier).
- **Commande parkée** : libellé, âge, montant, `Restaurer` / `Supprimer` ; la restauration rend la
  ligne à l'identique (`pos_parked_orders#45`).
- **Split cash + carte** : `COUVERT 4,00 € / RESTE DÛ 0,00 €`, confirmation bloquée tant que ce
  n'est pas équilibré, ticket « Détail paiement : Espèces 2,00 € / Carte 2,00 € »,
  2 `order_payments` exacts. *(Réserve : le mouvement de tiroir manquant → P1-2.)*
- **Historique** : colonnes `N° COMMANDE / ORIGINE / N° FILE / CLIENT / MONTANT / PAIEMENT /
  N° FISCAL / DATE / STATUT / ACTION`, filtres Aujourd'hui / Hier / Annulé / Remboursé,
  réimpression qui rouvre le ticket complet (n° fiscal 2698 lisible).
  Le compteur NF525 + le marqueur DUPLICATA ne s'incrémentent qu'au clic « Ticket Client »
  (`ReceiptComponent.vue:650-660` → `POST admin/pos/orders/{id}/print-receipt`) — consulter est
  gratuit, **imprimer** est tracé : comportement correct.
- **Session de caisse** : fond initial 100,00 €, date d'ouverture, 28 mouvements, montant attendu
  210,40 €, « Voir les mouvements », « Clôturer la caisse ».
- **Sortie hors-vente** : `stock_outflows#1` = `Petite Frites ×1 / staff_meal / user_id 3`,
  visible dans « DERNIÈRES SORTIES ».
- **Suppression de commande verrouillée** : `OrderService::destroy` refuse 403 hors branche,
  403 sur une commande payée sans `pos-destroy-paid`, et bloque toute commande fiscalement scellée.

---

## REPRODUCTION

```bash
# serveur + worker
PHP_CLI_SERVER_WORKERS=10 php artisan serve --host=127.0.0.1 --port=8766
php artisan queue:work --queue=high,default

# specs (Playwright)
PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 NODE_PATH="$(pwd)/node_modules" \
  npx playwright test tests/Playwright/zz-audit-caissier-s{1,2,2b,3,4,5,6,7,8,9,10,11}-2026-08-02.spec.js
```

| Spec | Couvre |
|---|---|
| `zz-audit-caissier-s1` | Scénario 1 — commande complète + espèces + rendu + ticket |
| `zz-audit-caissier-s2` / `s2b` | Scénario 2 — voir/accepter/détailler les commandes web & borne |
| `zz-audit-caissier-s3` | Scénario 3 — encaissement comptoir + annulation ciblée |
| `zz-audit-caissier-s4` | Scénario 5 + remboursement + panneau rupture |
| `zz-audit-caissier-s5` | Scénario 4 + 7 — 86, propagation, produit 86 déjà au panier |
| `zz-audit-caissier-s6` | Scénario 6 — session caisse, no-sale, sortie hors-vente, santé |
| `zz-audit-caissier-s7` | Scénario 7 — F5, 2 onglets, parking, multi-paiement |
| `zz-audit-caissier-s8` / `s9` | Parking via `prompt`, restauration, split détaillé |
| `zz-audit-caissier-s10` | Split confirmé + vérification money-path en base |
| `zz-audit-caissier-s11` | Preuve visuelle du rendu `undefined` |

Captures : `reports/goal-logique-2026-08-01/shots/s*.png` · relevés JSON : `shots/s*-report.json`.

**Nettoyage effectué** : disponibilité des items 98 et 100 restaurée
(`item_branch_availability.is_available = 1`). Les commandes ZZ-TEST et la commande parkée
`ZZ-TEST-PARK-1` sont conservées en base `foodking_e2e` **comme pièces à conviction**.
