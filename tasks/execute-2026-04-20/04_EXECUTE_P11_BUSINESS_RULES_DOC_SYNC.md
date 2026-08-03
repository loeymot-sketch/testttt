# EXECUTE — P11_BUSINESS_RULES_DOC_SYNC — 2026-04-20

## Status
**STATUS:** `READY_TO_LAUNCH`
**GATE_REQUIRED:** **NON** (docs only, aucun code applicatif)
**VAGUE:** V1 (parallélisable backend — plan §2 ligne 112)
**BLOCKING:** Aucun

## Source
- `plans/PLAN_POST_VERIFY_2026-04-20.md` §1.1 ligne 36
- `reports/review/VERIFY_TRACKER_2026-04-20.md` F-VERIFY-20-01, 20-02, 01-07
- `reports/review/VERIFY_20_BUSINESS_RULES_DOC_ALIGNMENT_2026-04-20.md`
- `reports/review/VERIFY_01_P1_AVAILABILITY_2026-04-20.md` §6 verdict FAIL sur section `Stock Management`

## Routing (AGENTS.md §Model Roles)
- **PRIMARY_MODEL:** `Composer` (AGENTS.md:16 — documentation, no schema, no auth, no pricing)
- **SUBAGENT:** `foodking-routine-implementer`
- **RUNNER_MODE:** `single-session`

## Scope

### SUBSYSTEMS_TOUCHED
- `docs/BUSINESS_RULES.md` (fichier unique)

### SCOPE_FILES (whitelist stricte)
- `docs/BUSINESS_RULES.md` **UNIQUEMENT**

### SUBSYSTEMS_OFF_LIMITS
- **TOUT** sauf `docs/BUSINESS_RULES.md`
- Interdit : `app/`, `resources/`, `routes/`, `database/`, `tests/`, autres `docs/*.md`, `.cursor/`, `plans/`, `reports/`

## Invariants at Risk
- **Aucun invariant applicatif touché** (docs only)
- **Risque documentaire unique :** doc qui ment crée drift de compréhension futurs cycles → impact indirect sur toute zone. Rigueur de vérité requise.

## Dependencies
- Aucune (parallélisable avec C1/C2/C3/C4 dès maintenant)

## Plan bref (sections à mettre à jour)

### Section 1 — §"Stock Management (not implemented)" — **REWRITE COMPLET**
Actuel (`docs/BUSINESS_RULES.md` vers L55-57) :
> "FoodKing v1 does not track item stock levels. There is no `stock` column, no `is_available` flag, and no stock validation at order time."

→ Remplacer par (référence code : `app/Services/Menu/AvailabilityService.php`, `app/Models/BranchItemAvailability.php`, `app/Services/Orders/assertItemsOrderableForBranch`, event `ItemAvailabilityChanged`, migration `branch_item_availabilities`) :
> FoodKing gère la disponibilité item **par branche** via la table `branch_item_availabilities` (modèle `BranchItemAvailability`). Deux boutons admin activent/désactivent : `POST /api/admin/menu/availability/toggle`. À la commande, `assertItemsOrderableForBranch` rejette (HTTP 422) tout item marqué `available=false`. Diffusion temps réel via event `ItemAvailabilityChanged` sur canal `private-branch.{id}`. Cache kiosk invalidé à chaque toggle. *Le tracking de quantité (`stock_quantity`) n'est pas implémenté ; la logique est booléenne (disponible/rupture).* UI admin côté Menu en cours d'implémentation (cycle P11_AVAILABILITY_TOGGLE_UI_ADMIN).

### Section 2 — §"Transitions d'États Logiques (Order Status)" — AUGMENTER
Après le bullet `RETURNED (22)` :
> - **RETURNED idempotent** (cycle P11_RETURNED_IDEMPOTENCY, 2026-04-20) : un second appel `changeStatus(RETURNED)` sur une commande déjà `RETURNED` retourne **200 OK** sans rejouer le cashback loyalty ni ajouter de ligne `audit_logs`. Garde fiscale sealed-Z active (cycle P11_FISCAL_Z_OPEN_HARDENING) : RETURNED après Z clôturé → **HTTP 423 Locked**.
> - **Accès chemin KDS** (cycle P11_RETURNED_KDS_BYPASS_LOCKDOWN) : transition `DELIVERED → RETURNED` interdite via `KitchenDisplaySystemOrderService::changeStatus` ; seul l'endpoint POS `admin/pos-order/{order}/return` autorisé (audit fiscal + cashback).

### Section 3 — §"Réductions (Coupons)" — AUGMENTER
Après "Le prix plancher est `0.00 €`." :
> - **Scope par branche** (cycle P11_COUPON_BRANCH_ISOLATION, V2) : chaque coupon porte un `branch_id` (nullable = global, sinon scope strict). `CouponService::validateCouponForOrder` filtre automatiquement. Cross-branche → HTTP 422 `coupon_not_applicable_for_branch`.
> - **Limite par utilisateur** (cycle P11_COUPON_LIMIT_PER_USER_KIOSK, V2) : table `coupon_usages` enregistre l'identifiant effectif (user_id OU device_fingerprint kiosk OU phone) scoped branche. `maximum_uses_per_user` appliqué strictement en lock transactionnel (pas de race).

### Section 4 — NOUVELLE §"Conformité NF525"
> Le système respecte la norme NF525 (caisse enregistreuse France) via :
> - **Chaîne HMAC Z-reports** : chaque clôture Z signe la précédente. `ZReportService::open()` vérifie la chaîne (cycle P11_FISCAL_Z_OPEN_HARDENING).
> - **État `CLOSING`** (atomique) : `ZReport` passe `OPEN → CLOSING → CLOSED` sous `lockForUpdate` pour interdire double-close concurrent.
> - **Guard sealed-Z** sur `changeStatus` et `changePaymentStatus` : toute mutation post-Z clôturé rejetée HTTP 423.
> - **Audit immuable `audit_logs`** : append-only, hash chain, idempotent sur `(order_id, from, to, actor_id)` (cycle P11_RETURNED_IDEMPOTENCY).
> - **Paiements** : `PaymentStateMachine` SSOT (cycle P11_PAYMENT_STATUS_STATE_MACHINE), transitions auditées, idempotence via `Idempotency-Key` (cycle P11_IDEMPOTENCY_KEY_MIDDLEWARE).

### Section 5 — NOUVELLE §"Isolation branche (SaaS)"
> - Un utilisateur non-Admin ne voit et ne mute QUE les commandes de sa `branch_id` (global scope `BranchScope` sur `Order`, `FrontendOrder`, `User`, `DiningTable`, `PushNotification`).
> - **Admin (`branch_id = 0`)** : court-circuit explicite `hasRole('Admin')` — peut voir et agir cross-branche. Toute action tracée dans `action_logs` + `audit_logs`. Ce comportement est **assumé par design**.
> - Canaux Pusher `private-branch.{id}` gardés côté `routes/channels.php` : kiosk machine token scope stricte sa branche, staff scope stricte sa branche, admin wildcard.
> - Routes fiscales `admin/fiscal/z-report/*` : `abort_unless($user->can('pos-manage-fiscal'))` + `resolveBranchId()` qui **abort 422 si user.branch_id=0** (admin pur ne peut pas déclencher Z "par accident").

### Section 6 — pied de page : ajouter "Dernière révision"
> **Dernière révision :** 2026-04-20 — cycle `P11_BUSINESS_RULES_DOC_SYNC` — sources d'autorité : `reports/review/VERIFY_*_2026-04-20.md`, `reports/review/AUDIT_POS_110_*.md`, `docs/gates/GATE_VERIFY_P0_FROZEN_CONSOLIDATED_2026-04-20.md`.

## Acceptance Tests
- [ ] `docs/BUSINESS_RULES.md` existe, lisible, ≥ 150 lignes
- [ ] Section "Stock Management (not implemented)" **supprimée** (remplacée)
- [ ] 5 sections ajoutées/révisées ci-dessus
- [ ] Grep `"not implemented"` dans `docs/BUSINESS_RULES.md` retourne 0 match (ou si reste, justifié en §Status)
- [ ] Références croisées aux cycles P11_* présentes
- [ ] `git diff --stat docs/BUSINESS_RULES.md` seul fichier modifié
- [ ] Markdown linter (`npx markdownlint docs/BUSINESS_RULES.md` si dispo, sinon visuel) pas d'erreur structurelle

## Exit Criteria
- [ ] Doc aligné avec le code au 2026-04-20 (preuve par `file:line` dans chaque section revue)
- [ ] 0 modification hors `docs/BUSINESS_RULES.md`
- [ ] `reports/execution/RUN_P11_BUSINESS_RULES_DOC_SYNC_2026-04-20.md` avec Final report

## Scope Pressure Protocol
Si révélation d'un autre doc désynchronisé (ex. `docs/AUTHZ_MATRIX.md`, `docs/FISCAL_OVERVIEW.md`) → **NE PAS ÉDITER** ; signaler dans REPORT_FILE + remonter à Claude pour nouveau cycle séparé (respect `scope.mdc`).

## Remediation
- Attempts 1-2 KO (ex. markdown mal structuré, lien mort) → auto-remediation Claude + re-route Composer
- Attempt 3 même bug → HUMAN_GATE (cas rare, doc only)

## Deliverables
- Diff `docs/BUSINESS_RULES.md` propre
- `reports/execution/RUN_P11_BUSINESS_RULES_DOC_SYNC_2026-04-20.md` (gabarit Final report auto-remediation.mdc:166-187)

## Communication
Subagent renvoie : diff unifié + nombre de sections modifiées + grep "not implemented" output.
