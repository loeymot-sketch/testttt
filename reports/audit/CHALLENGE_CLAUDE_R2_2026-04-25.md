Lecture des fichiers SSOT et vérification ciblée des preuves Codex R1 effectuée. Je rends le verdict orchestrateur.

---

# AUDIT ORCHESTRATEUR — Challenge Codex R1 (2026-04-25)

## SECTION A — D'accord (analyse Codex solide)

- **KDS doit être restreint à PREPARING/PREPARED** — confirmé par `docs/DEVICE_FLOW.md:21` (« `ACCEPT → PREPARING → PREPARED` ») et `docs/ORDER_FLOW.md:107-109` (« Interdictions : Un Kiosk ne peut pas… ; KDS écrit cuisine seulement »). Or `OrderStateMachine.php:42, 49` autorise toujours `ACCEPT|PREPARING → CANCELED` *via* `KitchenDisplaySystemOrderService::changeStatus` (`:150`) — invariant doc violé. **P0 légitime**.
- **Promo borne preview vs création** — preview existe (`PricingPreviewService`), payload kiosk envoie `kiosk_promo_code` (`kioskCart.js:26-37`), mais la règle n'est pas reconnue par `OrderRequest.php:35-68`. Asymétrie *affiché ≠ facturé* = atteinte directe à la règle SSOT prix (`docs/BUSINESS_RULES.md §1`). **P0 légitime**, mais à arbitrer V1 (support complet OU retrait preview).
- **Idempotence borne branch-scoped** — solide : contrainte DB `2026_04_18_140003_scope_idempotency_key_to_branch.php` + test `IdempotencyBranchScopedTest.php`. Codex a raison de classer ça en **acquis**.
- **after-commit / Outbox / EventContract** — chaîne validée : `DispatchableAfterCommit:29-41`, `EventContract:34-129`, `EventServiceProvider:102-133`. Codex classe correctement comme robuste mais signale stuck-claim potentiel.
- **`payment-confirm` non blindé borne/TPE** — vérifié `OrderController.php:77-118` : Sanctum + ownership only ; **aucune** preuve de contexte borne (KioskMachine), aucune validation amont du `transaction_id` côté TPE. `routes/api.php:893-895` : route exposée à tout `auth:sanctum`. Risque promotion `PAID` artificielle. **P0 légitime**.
- **KDS Echo `branch.{id}` correctement gardé** via `routes/channels.php:25-38` — bon point.

## SECTION B — Contestation

- **[PRIORITE_SURCOTEE] P0 « verrou optimiste KDS faible »** (`KitchenDisplaySystemOrderService.php:120-148`) : Codex sous-estime que `expectedFrom` est lu *fresh* lors du request, comparé sous `lockForUpdate`, et abort 409 est tracé (`:147`). Le vrai défaut est l'absence de paramètre `expected_status` **client** ; à requalifier **P1** + ajouter contrat HTTP, pas P0.
- **[PREUVE_INSUFFISANTE] P0 « identity transitions répètent side-effects »** (`OrderService.php:1548-1575`) : Codex marque `UNVERIFIED` lui-même. `OrderStateMachine::recordTransition:92-94` **skip** déjà l'identité ; le risque cashback/refund est plausible mais non démontré. À classer **NEEDS_EVIDENCE** (1 test ciblé) avant tout patch.
- **[PREUVE_INSUFFISANTE] P0 « TPE accepté + payment-confirm fail → front continue »** : claim sur `KioskPaymentComponent.vue:448-575` non lue ici, marquée `UNVERIFIED`. Risque réel mais doit être **NEEDS_EVIDENCE** (trace réseau + simulation), pas P0 exécutable tant que comportement front non capturé.
- **[ERREUR_CODEX possible] P1 « catch duplicate idempotency POS non scopé »** (`OrderService.php:1008-1018` UNVERIFIED) : la précheck *est* scopée (`:568-589`) — si MySQL UNIQUE composite est `(branch_id, idempotency_key)` (cf. migration `2026_04_18_140003`), une collision concurrente ne peut survenir que dans la même branche. Risque réel limité à code admin `branch_id=0`. À requalifier **NEEDS_EVIDENCE** + test concurrent.
- **[PRIORITE_SOUSCOTEE] P1 « OrderStatusRequest::authorize permissif »** (`OrderStatusRequest.php:23-49`) : c'est *le* point d'entrée commun POS/KDS/borne sans politique par surface — Device Flow exige des couloirs distincts. Mérite **P0** (auth/RBAC) et est l'un des deux verrous nécessaires pour P0 KDS terminal.
- **[INVARIANT_MANQUANT] symétrie `OrderService` / `FrontendOrderService`** : Codex effleure mais ne challenge pas la **symétrie systématique sur tout changement d'ordre** (AGENTS.md §FoodKing Non-Negotiables). Manque audit explicite d'arrondi, taxes, plafond coupons, paiement multi-méthode. À ajouter en P0 du plan V1.
- **[PRIORITE_SURCOTEE] P1 « outbox dispatched_at avant broadcast »** (`DispatchDomainEventsJob.php:65-86`) : claim correcte (claim ≠ delivery), mais en V1 minimal le risque est *acceptable* avec un retry/heartbeat *supervisor* externe ; à classer **P2** sauf preuve de stuck en prod (NEEDS_EVIDENCE).
- **[INVARIANT_MANQUANT] branch_id LIKE risk dans `OrderService::list`** : si la doc `ADMIN_CROSS_BRANCH_MAP_2026-04-20.md:55-57` confirme `LIKE`, c'est une fuite cross-branche **P0** (invariant non négociable AGENTS.md). Codex le classe P1 — **sous-coté**.
- **[ERREUR_CODEX] P1 « POS cash via endpoint KDS »** (`PosComponent.vue:1414-1421` UNVERIFIED) : à confirmer mais peu impactant si la machine d'état autorise `PREPARING→DELIVERED` sous permission `pos` (cf. `OrderStateMachine.php:38-46`). **PREUVE_INSUFFISANTE**, à requalifier P2/dette nommage.
- **[PREUVE_INSUFFISANTE] P0 promo borne** : la preuve serveur (`FrontendOrderService.php:216-227`) marquée UNVERIFIED ; classer comme P0 contingent à **un test contractuel** preview→checkout avant patch.
- **[INVARIANT_MANQUANT]** Aucune mention par Codex du contrat `correlation_id` côté backend pour déduplication, alors que P2 le mentionne côté frontend. Asymétrie EventContract méritait un point dédié.
- **[PRIORITE_SOUSCOTEE]** sealed-Z `changeStatus`/`changePaymentStatus` — Codex en P1, mais NF525 est doctrine `docs/BUSINESS_RULES.md §6`. Si V1 inclut fiscal réel → **P0**. Si V1 = parcours minimal sans clôture Z → P2. **Décision V1 requise** avant priorisation.
- **[ERREUR_CODEX possible] P1 « variation quantité perdue en preview »** (`PricingPreviewRequest.php:40-42`) : si `kioskPricingPreview.js:70-75` mappe la qty au niveau item parent et non variation, c'est un défaut UX mais le serveur reste source de vérité au checkout. **P2**, pas P1.

## SECTION C — Priorisation V1

**V1 = parcours opérationnel minimal cohérent : (1) Backend SSOT prix/branch/status + (2) POS cash & card + (3) Borne TPE confirm robuste + (4) KDS PREPARING/PREPARED, le tout sous Echo + outbox + branch isolation. Hors V1 : NF525 fiscal complet, fidélité avancée, reporting analytique.**

| Prio | Sujet | Action | Source |
|---|---|---|---|
| **P0** | KDS whitelist transitions + `OrderStatusRequest` policy par surface | Service KDS refuse tout sauf `ACCEPT→PREPARING`, `PREPARING→PREPARED` ; Request authorize selon route group | `KitchenDisplaySystemOrderService.php:150`, `OrderStatusRequest.php:23-49`, `DEVICE_FLOW.md` |
| **P0** | `payment-confirm` blindé borne/TPE | Exiger token kiosk ou device-bound + propriété machine→branche, refuser hors contexte borne | `OrderController.php:77-118`, `routes/api.php:893-895` |
| **P0** | `branch_id` filtre exact partout (LIKE → strict) | Auditer `OrderService::list:61-72,133-151` ; corriger si LIKE | `ADMIN_CROSS_BRANCH_MAP_2026-04-20.md:55-57` |
| **P0** | Promo borne : décider V1 (support OU retrait preview) | Soit câbler `kiosk_promo_code` dans `OrderRequest`/`FrontendOrderService::prepareOrderItems`, soit retirer du payload | `FrontendOrderService.php:216-227`, `kioskCart.js:26-37` |
| **P0** | Symétrie systématique OrderService/FrontendOrderService | Diff audit prix/taxes/coupons/idempotency entre les deux services | AGENTS.md non-négociables |
| **P1** | `expected_status` client requis sur mutations status | Ajouter param obligatoire ; 409 si différent | `KitchenDisplaySystemOrderService.php:122-148` |
| **P1** | Garde no-op idempotente avant side-effects status | Test cashback/refund non rejoué sur retry | NEEDS_EVIDENCE |
| **P1** | TPE retry failure UX | État explicite « paiement accepté, confirmation en attente » | NEEDS_EVIDENCE Vue front |
| **P1** | Catch duplicate idempotency POS | Test concurrent admin branch_id=0 ; sinon non actionnable | NEEDS_EVIDENCE |
| **P2** | Outbox claim_at vs dispatched_at | Surveillance + job de récupération | `DispatchDomainEventsJob.php:65-86` |
| **P2** | POS cash via endpoint KDS | Renommer route POS dédiée, dette nommage | `PosComponent.vue:1414-1421` |
| **P2** | EventContract front parité backend | Aligner `correlation_id`/`branch_id` | `eventContract.js:23-45` |
| **P2** | Sealed-Z status/payment | **Conditionnel** : P0 si NF525 V1 ; P2 sinon | `OrderService.php:1661-1823` |
| **NEEDS_EVIDENCE** | Suite E2E POS cash/card + borne TPE + KDS bout-en-bout | Playwright critical-flow | `reports/antigravity/` |

## SECTION D — Verdict

**`CHALLENGE_VERDICT: SPLIT`** — 4 P0 confirmés et 1 P0 ré-haussé (LIKE branche), 3 P0 Codex à requalifier en NEEDS_EVIDENCE / P1 (expectedFrom, identity side-effects, TPE retry UX), 2 erreurs probables (catch idempotency, POS via KDS) à confirmer avant exec. Ne pas merger en bloc, ne pas rejeter en bloc : redécouper le plan V1 selon Section C.

## SECTION E — Instructions Codex R3 (preuves attendues)

1. **payment-confirm** : prouver par `routes/api.php` + `OrderController.php` qu'aucun *middleware kiosk-token* n'existe ; si présent, citer ligne. Exiger réponse OUI/NON sur garde borne.
2. **KDS terminal** : exécuter `tests/Feature/KdsChangeStatus*` couvrant le rôle Chef appelant `CANCELED` sur `PREPARING` — fournir résultat HTTP attendu vs effectif.
3. **expectedFrom** : démontrer le scénario concret de race deux onglets KDS qui *passe* malgré `lockForUpdate` ; sinon admettre **P1**.
4. **Identity side-effects `OrderService::changeStatus:1548-1575`** : citer extrait code complet (cashback/refund/audit) prouvant qu'aucune garde no-op n'existe ; si garde présente, retirer le P0.
5. **Catch duplicate idempotency POS `:1008-1018`** : produire l'extrait. Si le catch est dans une transaction scopée par `branch_id`, admettre **erreur**.
6. **POS collect cash via endpoint KDS** : preuve `PosComponent.vue:1414-1421` *exactement* + URL appelée. Si `/admin/pos-order/change-status` et non `/kds-order/...`, retirer.
7. **Promo borne** : trace `kiosk_promo_code` dans `OrderRequest::rules()` et dans `FrontendOrderService::store/computeTotals` — affirmer **présence** ou **absence** sans `UNVERIFIED`.
8. **branch_id LIKE risk** : extrait exact `OrderService::list:61-72` + `:133-151`. Si requête utilise `=`, retirer le risque ; si `LIKE`, réhausser **P0**.
9. **Symétrie OrderService/FrontendOrderService** : tableau diff (méthodes, validations, idempotency, taxes, coupons, after-commit). Pas un narratif, un tableau.
10. **NF525 V1 ou pas** : trancher la portée fiscale V1 — si exclu, sealed-Z status/payment passe P2 ; si inclus, **P0** + tests.
11. **Sub-agent fallback** : confirmer `EXECUTE_DELEGATION` cible (`codex-extension` ou `foodking-complex-implementer`) pour chaque P0 du plan V1 proposé.
12. **E2E V1** : proposer **5 scénarios Playwright** maximum (POS cash, POS card, Borne TPE confirm, KDS preparing/prepared, branch 1 vs 10) avec assertions outbox + Echo. Pas plus.

---

**Note orchestrateur** : aucun gate humain clos ici, aucun `run-cycle` lancé. Ce challenge est un cadrage pré-PLAN V1. La V1 ne démarre **pas** tant que (a) les NEEDS_EVIDENCE sont levés et (b) la décision NF525 V1 est tranchée par humain.
