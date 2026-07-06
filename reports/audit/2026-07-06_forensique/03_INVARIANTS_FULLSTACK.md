# FoodKing — Traçage des invariants full-stack

> Partie 3/7 de l'audit forensique du 2026-07-06. **Le rapport le plus important.**
> Méthode : 8 traceurs indépendants (raisonnement effort maximal) ont suivi chaque invariant de bout en bout — route → validation → contrôleur → service → modèle → DB → événement → frontend — en ouvrant chaque maillon. Chaque finding est prouvé par `fichier:ligne`. Le statut de vérification adversariale finale (3 réfutateurs + juge) est consolidé dans le rapport **04**.

## 0. Verdict global : le socle métier ne tient pas

Les quatre invariants « non négociables » de `CLAUDE.md` sont censés être la colonne vertébrale du produit. **Sept invariants sur huit sont violés, le huitième partiellement.**

| # | Invariant | Verdict | Maillon le plus grave |
|---|---|---|---|
| 1 | Backend = seule source de vérité du **pricing** | 🔴 **VIOLÉ** | Remise + `delivery_charge` client acceptés sans recalcul sur endpoint non authentifié |
| 2 | **Isolation par branche** jamais affaiblie | 🔴 **VIOLÉ** | Isolation *opt-in* (5 modèles) au lieu de *deny-by-default* + IDOR public |
| 3 | Intégrité **fiscale** (séquence/HMAC/scellement Z) | 🔴 **VIOLÉ** | Commande scellée reste mutable (le sceau ne couvre que `destroy`) |
| 4 | **Transitions de statut** correctes et contrôlées | 🟠 **PARTIEL** | Override Admin illimité depuis les états terminaux |
| 5 | Intégrité **paiement** (montant/webhook/double-charge) | 🔴 **VIOLÉ** | Troncature du montant Stripe → **perte d'argent déterministe** |
| 6 | **Livraison d'événements** (outbox atomique/idempotent/contrat) | 🔴 **VIOLÉ** | Canal privé de branche écoutable par tout client + outbox non atomique |
| 7 | **Surface d'authz** complète | 🔴 **VIOLÉ** | Le token borne le moins privilégié obtient les **pleins pouvoirs super-admin** |
| 8 | **Idempotence** sous retry/double-submit | 🔴 **VIOLÉ** | Remboursement dupliqué par annulation concurrente (perte d'argent) |

> **Trois findings entraînent une perte d'argent directe et déterministe/probable** : troncature Stripe (#5), double remboursement (#8), `delivery_charge` négatif (#1). **Trois findings sont des fuites de données cross-branch** (#2, #6, #7). Le produit, en l'état, n'est pas sûr pour la production financière.

---

## 1. 🔴 Pricing — le backend n'est PAS la seule source de vérité

**Maillon le plus grave** : `POST /api/table/dining-order` (`routes/api.php:1007`) est sous le seul middleware `apiKey` (clé statique, **non authentifié**). Le champ `discount` du client y est appliqué comme remise sans permission ni motif ; couplé à un `delivery_charge` négatif, un client ramène le total encaissé à ~la TVA.

| Sévérité | Finding | Emplacement |
|---|---|---|
| 🔴 critical | Remise manuelle pilotée par le client, sans autorisation, sur l'endpoint TABLE non authentifié (QR) | `app/Http/Requests/TableOrderRequest.php:36` |
| 🔴 critical | `delivery_charge` accepté du client sans recalcul ni borne (valeur **négative** ⇒ total réduit) | `app/Services/FrontendOrderService.php:514` |
| 🟠 medium | Stripe : troncature des centimes — `(int) $order->total * 100` sous-facture chaque commande | `app/Http/PaymentGateways/Gateways/Stripe.php:47` |
| 🟠 medium | Divergence preview↔commande : le code promo kiosk est affiché en preview mais jamais appliqué au checkout | `app/Services/FrontendOrderService.php:210` |

---

## 2. 🔴 Isolation par branche — architecture *opt-in* au lieu de *deny-by-default*

**Maillon le plus grave** : `BranchScope` n'est appliqué que sur **5 modèles**. Toute entité non couverte (Transaction, Message, commande front non authentifiée) fuit dès qu'un filtre manque. Concrètement, un **Branch Manager** (`branch_id>0`) disposant des permissions transactions/messages **lit, exporte et supprime des données d'autres branches**.

| Sévérité | Finding | Emplacement |
|---|---|---|
| 🔴 critical | **IDOR public non authentifié** : `dining-order/show` renvoie n'importe quelle commande (PII + adresse + paiement) | `app/Http/Controllers/Table/OrderController.php:33` |
| 🔴 critical | Fuite financière cross-branch : liste/export des transactions non scopés par branche (accessibles au Branch Manager) | `app/Services/TransactionService.php:33` |
| 🟠 high | Lecture et suppression cross-branch des messages clients via route-model-binding non scopé | `app/Http/Controllers/Admin/MessageController.php:36` |
| 🟠 high | Défaut d'architecture : isolation *opt-in* (BranchScope sur 5 modèles) au lieu de *deny-by-default* | `app/Models/Scopes/BranchScope.php:13` |

---

## 3. 🔴 Intégrité fiscale — le sceau Z ne scelle presque rien

**Maillon le plus grave** : le scellement post-Z ne couvre **que la suppression** (`destroy` → 409). Aucune garde d'immutabilité au niveau du modèle `Order` : `changeStatus` / `changePaymentStatus` (et tout `forceDelete`/update de masse) **écrivent sur une commande scellée sans blocage**. Le sceau NF525 repose sur une unique vérification applicative, non sur une contrainte structurelle.

| Sévérité | Finding | Emplacement |
|---|---|---|
| 🔴 critical | Scellement Z incomplet : une commande scellée reste **mutable** via `changeStatus`/`changePaymentStatus` | `app/Services/OrderService.php:1499` |
| 🟠 high | Absence de garde d'immutabilité au niveau modèle : `forceDelete`/update de masse contourneraient le sceau | `app/Models/Order.php:79` |
| 🟠 high | Trous de couverture Z : commandes créées hors session Z (entre clôture et ouverture) jamais agrégées | `app/Services/OrderService.php:862` |
| 🟠 medium | Seules les commandes POS reçoivent un n° de séquence fiscale ; ventes en ligne et à table exclues du Z | `app/Services/OrderService.php:484` |
| ⚪ low | Fork de genèse de la chaîne d'audit non bloqué par l'index unique (`prev_hash` NULL) — détectable | `app/Services/Fiscal/AuditLogService.php:167` |

---

## 4. 🟠 Machine d'états — une garde qui cède sur les états terminaux

**Maillon le plus grave** : `OrderStateMachine::allows()` (lignes 60-67) — depuis un état **terminal** (CANCELED/REJECTED/RETURNED), si le rôle est Admin, la méthode retourne `true` pour **n'importe quelle cible**, sans restriction ni compensation. Un état terminal cesse d'être terminal ; combiné à l'absence de verrou (TOCTOU) sur `changeStatus`, c'est là que « correcte et contrôlée » cède.

| Sévérité | Finding | Emplacement |
|---|---|---|
| 🟠 high | Override Admin illimité depuis les états terminaux : CANCELED/REJECTED/RETURNED → n'importe quel statut | `app/Domain/Order/OrderStateMachine.php:63` |
| 🟠 medium | Absence de `lockForUpdate` dans `changeStatus` : fenêtre TOCTOU permettant une transition nette interdite | `app/Services/KitchenDisplaySystemOrderService.php:128` |
| 🟠 medium | Journal des transitions best-effort : `recordTransition` avale toutes les exceptions → trous d'audit possibles | `app/Domain/Order/OrderStateMachine.php:108` |
| ⚪ low | Validation du statut sans liste blanche + promotion kiosk hors garde reposant sur l'ordre numérique | `app/Http/Requests/OrderStatusRequest.php:46` |

---

## 5. 🔴 Intégrité paiement — perte d'argent déterministe

**Maillon le plus grave** : `Stripe.php:47` — `'amount' => (int) $order->total * 100`. En PHP, le cast `(int)` a une **précédence supérieure** à `*`, donc l'expression vaut `((int)$order->total) * 100`. Or `Order.total` est `decimal:6` (fractionnaire) : un total de **12.99 débite 1200 centimes = 12,00 $**. Perte d'argent **déterministe** sur toute commande carte à total non entier — alors que la `Transaction` enregistre 12.99 → **grand livre incohérent**.

| Sévérité | Finding | Emplacement |
|---|---|---|
| 🔴 critical | Stripe : montant tronqué envoyé au PSP (précédence de cast PHP) → perte d'argent déterministe | `app/Http/PaymentGateways/Gateways/Stripe.php:47` |
| 🟠 high | Aucun webhook signé : confirmation par redirection navigateur seule → double charge à la reprise | `app/Http/PaymentGateways/Gateways/Stripe.php:46` |
| 🟠 high | Borne : commande marquée **PAID sur simple assertion client**, sans vérification PSP ni montant | `app/Http/Controllers/Frontend/OrderController.php:111` |
| 🟠 medium | PayPal : montant capturé non revérifié contre le total serveur à la capture | `app/Http/PaymentGateways/Gateways/Paypal.php:133` |
| 🟠 medium | Credit : double déduction de solde possible par course (pas de verrou), token faible | `app/Http/PaymentGateways/Gateways/Credit.php:79` |
| ⚪ low | Passerelles fantômes : Razorpay/Easypaisa/Senangpay sans classe Gateway, route webhook morte (drift) | `app/Http/PaymentGateways/Routes/senangpay.php:18` |

---

## 6. 🔴 Livraison d'événements — fuite temps réel + outbox non atomique

**Deux maillons cassés.** (1) Canal `private-branch` : tout client (`branch_id=0`, cf. `CustomerService:68` + `users.branch_id` default 0) passe le bypass « admin » de `routes/channels.php:33` → **écoute toute branche = fuite cross-branch temps réel**. (2) L'outbox écrit **après** le commit (`OrderService:1556`) dans un `try/catch` qui avale l'erreur : fenêtre *at-most-once*, événement perdu sans ligne, donc **irrécupérable**.

| Sévérité | Finding | Emplacement |
|---|---|---|
| 🔴 critical | Fuite cross-branch : tout client (`branch_id=0`) autorisé sur le canal privé de n'importe quelle branche | `routes/channels.php:33` |
| 🟠 high | Outbox non atomique : événements dispatchés **après** le commit (fenêtre at-most-once, perte irrécupérable) | `app/Services/OrderService.php:1556` |
| 🟠 medium | Ordre non garanti : `UPDATE_ITEM` applique l'état du payload sans garde d'ordre/version | `resources/js/components/frontend/kiosk/KioskAppComponent.vue:387` |
| 🟠 medium | Contrat non mono-sourcé : `schema.json` jamais importé, validation front plus faible que le contrat | `resources/js/services/eventContract.js:31` |
| ⚪ low | Redélivrance at-least-once : son « prêt » rejoué à chaque duplicata (aucune dédup par `correlation_id`) | `resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue:142` |

---

## 7. 🔴 Surface d'authz — le token le moins privilégié devient super-admin

**Maillon le plus grave** : l'absence totale d'enforcement des *abilities* de token Sanctum sur `/api/admin/*`, **combinée** à `AuthServiceProvider.php:30-32` (`Gate::before` → admin = `true`) et au fait que la borne est propriétaire de l'utilisateur admin `id=1` : un token `kiosk:order` — censé être le **moins privilégié** — obtient les **pleins pouvoirs super-admin** sur toute la chaîne.

| Sévérité | Finding | Emplacement |
|---|---|---|
| 🔴 critical | Le token borne `kiosk:order` obtient les pleins pouvoirs super-admin : abilities Sanctum jamais appliquées | `routes/api.php:229` |
| 🟠 high | IDOR non authentifié : `GET /api/table/dining-order/show/{order}` expose n'importe quelle commande (PII) | `app/Http/Controllers/Table/OrderController.php:36` |
| 🟠 high | Contrôle d'accès rompu : plusieurs écritures `/api/admin/*` sans aucune garde de permission | `app/Http/Controllers/Admin/TableOrderController.php:26` |
| 🟠 high | Affaiblissement de l'isolation : les clients ont `branch_id=0`, que `BranchScope` interprète comme « toutes branches » | `app/Models/Scopes/BranchScope.php:33` |
| 🟠 medium | `x-api-key` : secret statique partagé unique comme seule barrière des endpoints publics/refresh | `app/Http/Middleware/ApiKeyMiddleware.php:21` |
| 🟠 medium | IDOR lecture admin : `GET /api/admin/my-order/show/{user}/{order}` sans garde de permission | `app/Http/Controllers/Admin/MyOrderDetailsController.php:22` |

---

## 8. 🔴 Idempotence — remboursement dupliqué par concurrence

**Maillon le plus grave** : `changeStatus:661` (annulation client) exécute un *check-then-act* **sans** `DB::transaction` ni `lockForUpdate`, sur une route sans throttle. Deux annulations concurrentes d'une commande en ligne **payée** passent chacune la garde de seuil et appellent `cashBack` (`user.balance += order->total`) ; `transactions` n'ayant aucun `unique(order_id)`, cela produit un **double remboursement = perte d'argent**.

| Sévérité | Finding | Emplacement |
|---|---|---|
| 🔴 critical | Remboursement dupliqué : annulation client concurrente sans verrou ni transaction (perte d'argent) | `app/Services/FrontendOrderService.php:661` |
| 🟠 high | Commande à table (QR, non authentifiée) : aucune idempotence — double-submit crée deux commandes | `app/Services/OrderService.php:979` |
| 🟠 medium | Ledger de paiement en ligne : check-then-insert sans `unique` ni verrou (transactions dupliquées) | `app/Services/PaymentService.php:15` |
| 🟠 medium | Charge passerelle émise sans clé d'idempotence (double-charge possible) | `app/Http/PaymentGateways/Gateways/Stripe.php:46` |
| 🟠 medium | Idempotence de commande entièrement optionnelle : dépend d'un en-tête client, non imposé serveur | `app/Services/FrontendOrderService.php:130` |
| ⚪ low | Décalage de troncature de clé d'idempotence : lookup clé complète vs stockage tronqué à 64 | `app/Services/FrontendOrderService.php:186` |

---

## 9. Motifs systémiques (ce qui relie ces violations)

Les 8 traces convergent vers **5 causes racines** transverses :

1. **Confiance au client sur les endpoints non authentifiés** (table/QR, borne) : prix, remises, statut de paiement et IDs sont acceptés sans recalcul ni contrôle d'appartenance.
2. **Sécurité *opt-in* au lieu de *deny-by-default*** : `BranchScope` sur 5 modèles, abilities Sanctum non imposées, gardes de permission oubliées → le défaut d'usine est « ouvert ».
3. **`branch_id=0` surchargé** : sert à la fois de « client sans branche » et de « toutes branches / admin » → collision sémantique exploitée en fuite cross-branch (canal privé, scope).
4. **Absence d'atomicité/verrous sur les chemins argent** : `changeStatus`, remboursements, ledger, outbox — tous en check-then-act sans transaction ni `lockForUpdate`.
5. **Le sceau et le contrat reposent sur une seule vérification applicative**, non sur une contrainte structurelle (DB `unique`, garde de modèle, schéma mono-sourcé).

Corriger ces 5 causes racines neutralise la majorité des findings ci-dessus. La feuille de route (rapport **07**) est organisée autour d'elles.

---

*Findings issus de la phase de traçage d'invariants (effort xhigh). Le statut de vérification adversariale finale (CONFIRMÉ/REJETÉ après 3 réfutateurs + juge) est consolidé dans le rapport 04.*
