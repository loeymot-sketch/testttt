# FoodKing Ultra-Review 6-Wave — VERDICT consolidé 2026-05-16

**Branche** : `feature/mobile-app-le-cayenne-2026-05-10`
**HEAD** : `94f6232a8` (post heal V3.1 burger composer)
**Méthode** : 6 sub-agents read-only parallèles GStack — POS / Kiosk / KDS / Sync / Delivery / Cash drawer
**Date** : 2026-05-16

---

## Verdict global

### **NO-GO V1 ship** — 17 P0 cross-validés sur 6 surfaces

| Wave | Verdict | P0 | P1 | Note |
|------|---------|-----|-----|------|
| A — POS Caisse | **NO-GO** | 2 | 3 | NF525 cash trail rompu |
| B — Kiosk Borne | GO-CONDITIONAL | 1 | 3 | FR-lock breach 30min heal |
| C — KDS Kitchen | **NO-GO** | 4 | 4 | Legacy default ship gèle V2 fixes |
| D — Sync cross-surface | GO-CONDITIONAL | 0 | 3 | Webhook idempotency manquante |
| E — Delivery flow | **PARTIEL** | 5 | 4 | Livreur ne peut pas livrer |
| F — Cash drawer | **EXISTE PARTIEL** | 5 | 7 | Backend OK, UI 0%, TPE absent |
| **TOTAL** | **NO-GO** | **17** | **24** | |

---

## Cross-validation forte — 4 P0 vus depuis ≥2 angles

| Bug | Wave A | Wave F | Évidence |
|-----|--------|--------|----------|
| Cash sans session = invisible Z-report | POS-A1 + A5 | F1 + F3 | `OrderService.php:563-925` + `PaymentService.php:255-281` + 0 hit `cash-drawer/sessions` côté JS |
| UI fond de caisse absente | POS-A5 | F1 | API existe `routes/api.php:813-825`, 0 consommateur frontend |
| SplitPaymentService cash non-tracé | POS-A2 | F1 | `SplitPaymentService.php:143` ne call jamais CashDrawerService |
| `closing_amount` non-gated | — | F4 | `CashDrawerService.php:101-133` accepte tout sans variance_reason |

**Convergence audit forte** — ces bugs ne sont pas des hallucinations.

---

## §1 — POS Caisse (Wave A)

**Verdict** : NO-GO V1 ship — NF525 cash trail rompu

### P0
- **POS-A1** — `OrderService::posOrderStore` (`app/Services/OrderService.php:563-925`) ne crée pas `CashMovement` sur cash direct → seul `PaymentService::confirmCounterPayment` (kiosk counter-collect) écrit. Z-report cash variance bidon.
- **POS-A2** — `SplitPaymentService::persistTranches` (`app/Services/Payments/SplitPaymentService.php:143-end`) idem sur tranches multi-tender cash.

### P1
- **POS-A3** — `/pos/quote` + `/pos/walk-in-customer` sans `permission:pos` middleware (`PosController.php:39` only('store')). Fuite PII walk-in + pricing recon.
- **POS-A4** — Frozen-zone diff non-LOCK-tracké : `pos-wizard.js` +237 lignes, blade +165 lignes (commits 91a1e1b2c, 5218168ef). Drift policy §7.
- **POS-A5** — UI cash-drawer/sessions **0 consommateur** côté JS (cf. cross-validation).

### P2
- **POS-A6** — `PosComponent.vue:2722-2734` envoie `total/discount/subtotal` calculés JS au backend (signoff date_limit 2026-05-10 EXPIRÉ).

---

## §2 — Kiosk Borne (Wave B)

**Verdict** : GO-CONDITIONAL — heal owner-gate ~30 min

### P0
- **K-001 FR-lock breach** — `KsA11ySettings.vue:209-213` expose un radiogroup fr/en/ar dans le drawer A11y idle screen. Contredit ADR-007 « kiosk runtime FR-immutable » (`KioskAppComponent.vue:181-184`). Heal : retirer `selectLocale` OU feature flag `kiosk.locale_switch_allowed=false`.

### P1
- **K-002** — `OrderRequest::authorize()` (`app/Http/Requests/OrderRequest.php:60-63`) fail-open si token null.
- **K-003** — Magic numbers `FRITES_INCLUDED_CATS = [309,310,311,314]` (`KioskWizardComponent.vue:1029`). Renumber DB casse silencieusement.
- **K-004** — Template inference par sous-chaîne `item.name` (`detectTemplateFromName:907-947`). Rename Sandwich XXL = wizard cassé silencieusement.

### Points forts
- 6 stable steps `STEP_KEY_REGISTRY` lignes 315-339 alignment composer step_keys correct.

---

## §3 — KDS Kitchen (Wave C)

**Verdict** : NO-GO V1 ship — Legacy default ship gèle V2 fixes

**Insight structurant** : `useV2Layout` (`KitchenDisplaySystemComponent.vue:1064-1080`) gated `?v2=1` URL / localStorage `kds.v2_enabled=1`. Default `false`. **Tous les fix V2 (accordéon, banners, allergens, bump) sont inert en prod** — V2 visible seulement par owner avec URL param.

### P0
- **KDS-W3-001** — Accordéon items hardcoded fermé `style="height:0px"` lignes 328/484/629/771. Chef tape "Prêt" sans voir items.
- **KDS-W3-002** — Legacy = default ship. 8 P0 audit 2026-05-11 non-résolus en prod.
- **KDS-W3-003** — 5 banners stack lignes 44/56/70/77/84/92 → ~10% écran perdu.
- **KDS-W3-004** — Items Board sans `allergens_snapshot` (lignes 124-166). **Food-safety gap**.

### P1
- W3-005 : `aria-expanded` statique
- W3-006 : `kdsBump` localStorage par device (2 chefs = 2 bumps indépendants)
- W3-007 : Bump button 32px sous-WCAG 44px
- W3-008 : 11 raw FR strings hardcoded

### Points forts
- Backend `changeStatus` (`KitchenDisplaySystemOrderService.php:152-228`) DB::transaction + lockForUpdate + 409 + branch isolation 403 → NF525-conforme
- Polling adaptatif KDS 3s/5s/10s + jitter + backoff 5xx capped 30s
- `kdsCustomization.js:148-243` rend sandwich/burger/bowl/menu_formule correctement

---

## §4 — Sync cross-surface (Wave D)

**Verdict** : GO-CONDITIONAL — webhook idempotency = unique blocker V1 fiscal

### P1
- **P1-SYNC-01** ⚠️ — **Webhook payment idempotency NON-IMPLÉMENTÉE en prod** :
  - `Senangpay.php:33-46` retourne `501 not_implemented` (stub)
  - `Stripe.php:46-72` crée `CapturePaymentNotification` sans `firstOrCreate` sur `webhook_events`
  - `WebhookEvent::` model + table créés mais **0 hit** dans `app/`
  - **Risque financier direct** : duplicate webhook → double-paiement comptabilisé
- **P1-SYNC-02** — Cron `foodking:outbox:retry-failed` **non scheduled** (`Kernel.php:25-100` schedule `outbox:rescue` + `outbox:monitor` mais pas `retry-failed`). Rows `attempts>=5` jamais retried.
- **P1-SYNC-03** — `PersistOrderCreatedToOutbox:50-68` + `PersistOrderPaidAtCounterToOutbox:47-64` dispatch sans `wasRecentlyCreated` (waste queue retry).

### Points forts (architecture solide)
- Outbox atomic claim Phase 1 `lockForUpdate + dispatched_at` bulletproof
- BranchScope 17 models (au-delà des 13 BRAIN)
- Backoff exponentiel `[1,5,15,60,300]` × tries=6
- KDS API latency 83-91ms mesuré rush-sync
- Channel auth kiosk token restreint à `KioskMachine.branch_id`
- `audit_logs` + `z_reports` triggers immutability NF525 OK

---

## §5 — Delivery customer flow (Wave E)

**Verdict** : PARTIEL — existe end-to-end MAIS livreur ne peut pas livrer

### P0
- **DEL-1** ⚠️ — `DeliveryQuoteService.php:33` lit `$address['geocode_status']` mais colonne **n'existe pas** dans migration `2022_11_17_110300_create_addresses_table.php`. **Gate géocodage mort** : adresse "Paris" copiée 50× passe.
- **DEL-2** — `FrontendOrderService.php:526-544` : si IDOR check casse, `OrderAddress::create` silencieusement skip → commande DELIVERY persiste **sans adresse**.
- **DEL-3** ⚠️ — `KDSOrderDetailsResource.php:20-47` + `SimpleOrderResource.php:17-39` **n'exposent NI `order_address`, NI `user.phone`, NI `user.name`**. Cuisinier/livreur voit token + delivery_time, c'est tout. **Le livreur ne peut littéralement pas livrer**.
- **DEL-4** — `User.phone` nullable, jamais required (signup, AddressRequest, OrderRequest). Aucune validation E.164.
- **DEL-5** — `DeliveryFeeService.php:14` barème hardcodé `max(5, ceil(distance/5)*5)` EUR. **Non branch-configurable, non zone-configurable**. Viole SaaS multi-tenant.

### P1
- DEL-6 : ≥10 clés FR i18n manquantes (`label.delivery_address`, `label.preferred_time`, etc.) → raw label leak
- DEL-7 : `BranchService::132` `whereNotNull('zone')` exclut silencieusement branches sans polygon
- DEL-8 : Aucun minimum order delivery (pas de seuil)
- DEL-9 : Assignation livreur 100% manuelle dropdown admin, pas d'auto-dispatch, pas de push/SMS

### P2
- DEL-10 : Schéma `addresses` trop pauvre (pas de floor/intercom/instructions séparés)
- DEL-11 : Pas de fallback Nominatim si `GOOGLE_MAP_KEY` vide

---

## §6 — Cash drawer / fond de caisse (Wave F)

**Verdict** : EXISTE PARTIEL — Backend complet, **UI 0%**, TPE rates absent

### P0
- **F-1** ⚠️ — **UI admin/POS absente** : 0 référence `/cash-drawer/sessions/*` dans `resources/js/**` ni `public/js/pos-wizard.js`. Owner ne peut littéralement pas saisir 50€ d'ouverture.
- **F-2** ⚠️ — **TPE rates feature ENTIÈREMENT manquante** (verbatim owner). Pas de table `payment_terminals`, pas de `fee_percent`/`fee_amount` sur `payment_gateways`.
- **F-3** — Cash sans session = invisible : `PaymentService.php:255-281` si pas de session OPEN, log INFO et `return`. Order PAID, 0 cash_movements (héritage A09-P0-2).
- **F-4** — `CashDrawerService::reconcile:101-133` accepte n'importe quel `closing_amount` sans variance gate ni `variance_reason` requise. Champ existe mais jamais écrit (`CashDrawerSession.php:42`).
- **F-5** — `cash_movements_table.php:47-50` `cascadeOnDelete` → DELETE session efface movements. Viole rétention NF525 6 ans (pas de trigger BEFORE DELETE équivalent à `audit_logs`/`z_reports`).

### P1
- F-6 : Z report `total_by_method` cash/card mais pas breakdown par TPE/terminal, pas net-after-fees
- F-7 : Hardware drawer pop (`CashDrawerController.php:19-33`) n'écrit aucun `cash_movements TYPE_DRAWER_OPEN` ni audit_log
- F-8 : `CashDrawerService` n'écrit jamais `audit_logs` HMAC chain (open/close/reconcile)
- F-9 : `recordMovement` race sans `lockForUpdate` + failures `strict=false` swallowed
- F-10 : Pas de `closed_by_user_id` / `reconciled_by_user_id` — forensic gap
- F-11 : Permission unique `permission:pos` — POS Operator peut clôturer sa propre session sans manager-gate
- F-12 : POS wizard ne bloque pas vente cash sans session

---

## TOP 10 weak points prioritisés pour heal-sprint

| # | ID | Wave | Sévérité | Effort | Impact |
|---|-----|------|----------|--------|--------|
| 1 | DEL-3 | E | P0 | 1j | **Livreur ne peut pas livrer** : KDS/OSS zéro adresse zéro téléphone |
| 2 | F-1 + F-3 | F | P0 | 3j | UI fond de caisse + bloquer vente cash sans session ouverte |
| 3 | POS-A1 + POS-A2 + F-3 | A+F | P0 | 1j | `CashMovement` write sur cash direct + split tranches |
| 4 | F-2 | F | P0 | 2j | TPE rates table + model + UI config (verbatim owner) |
| 5 | DEL-1 + DEL-2 | E | P0 | 0.5j | Gate géocodage + `OrderAddress` mandatory (pas silent-skip) |
| 6 | DEL-4 | E | P0 | 0.5j | `User.phone` required + validation E.164 |
| 7 | P1-SYNC-01 | D | P1 | 1j | Webhook idempotency Stripe + SenangPay via `WebhookEvent::firstOrCreate` |
| 8 | KDS-W3-002 + W3-001 | C | P0 | 1j | Flip `useV2Layout` default=true OR fix accordéon legacy |
| 9 | F-4 + F-5 | F | P0 | 0.5j | Variance gate + `variance_reason` required + DELETE trigger NF525 |
| 10 | K-001 | B | P0 | 0.5j | Retirer locale selector drawer kiosk (FR-lock ADR-007) |

**Total effort estimé** : ~11 jours-agent (sans le risque heal-loop).

---

## Plan heal séquentiel proposé (owner-gate avant exécution)

### Sprint 1 — NF525 + Cash drawer (5 jours) — BLOQUANT V1
1. Wave F P0-F1 : UI `PosCashDrawerSessionDialog` (pre-shift modal + numpad amount)
2. Wave F P0-F3 + Wave A P0-1/2 : block POS sale si session not OPEN + write CashMovement sur cash direct + split tranches
3. Wave F P0-F4 + F-5 : variance gate (manager approval si écart >X€) + `variance_reason` required + DB trigger BEFORE DELETE
4. Wave F P0-F2 : table `payment_terminals` + model + jointure `order_payments.terminal_id` + UI config par branche

### Sprint 2 — Delivery operationnel (2 jours) — BLOQUANT delivery
5. Wave E P0-DEL-3 : enrich `KDSOrderDetailsResource` + `SimpleOrderResource` avec `order_address` + `user.phone` + `user.name` ; UI KDS affiche
6. Wave E P0-DEL-1 + DEL-2 : ajouter colonne `geocode_status` migration + valider `OrderAddress::create` mandatory (throw au lieu de skip)
7. Wave E P0-DEL-4 : `User.phone` required signup + AddressRequest + validation E.164

### Sprint 3 — Sync + KDS + Kiosk (3 jours) — GO-CONDITIONAL → GO
8. Wave D P1-SYNC-01 : Stripe + SenangPay webhook idempotency via `WebhookEvent::firstOrCreate`
9. Wave D P1-SYNC-02 : ajouter `foodking:outbox:retry-failed` au Kernel schedule
10. Wave C P0 W3-001 + W3-002 : flip `useV2Layout` default=true OR fix accordéon legacy + banners stack
11. Wave B P0 K-001 : retirer `selectLocale` du `KsA11ySettings.vue` drawer (restore FR-lock ADR-007)

### Sprint 4 — Hardening V1.0.1 (1 jour) — POST-V1
12. Wave A POS-A3 : `permission:pos` middleware sur `quote` + `walk-in-customer`
13. Wave F P1-F8 : binding `AuditLogService` sur cash events (HMAC chain NF525)
14. Wave E P1-DEL-9 : auto-dispatch livreur + push notification
15. Wave C P1-W3-005 + W3-006 + W3-007 + W3-008 : aria-expanded réactif + bump server-side + 44px + i18n

---

## STOP GATE — décisions owner attendues avant heal

1. **Sprint 1 OK ?** Commencer par UI fond de caisse + cash trail + TPE rates (5 jours) ?
2. **Sprint 2 OK ?** Delivery operationnel ensuite (2 jours) ?
3. **TPE rates modélisation** : préfères-tu (a) `payment_terminals` table avec `fee_percent` + `fee_fixed` par terminal, (b) `payment_gateway.fee_percent` global par gateway, ou (c) attendre V1.0.1 ?
4. **KDS V2 flip** : on flip `useV2Layout` default=true (risque légère régression sur Dine-in/Online/Takeaway/Kiosk lanes) OU on heal legacy P0 ?
5. **Backup pre-heal** : on crée `backup/pre-ultra-review-heal-2026-05-16` + DB dump avant Sprint 1 ?

---

## Sources des findings (read-only audit)

- Wave A : `/private/tmp/.../tasks/a22c6154e7e92ce01.output`
- Wave B : `/private/tmp/.../tasks/a223ecd005fcd08c9.output`
- Wave C : `/private/tmp/.../tasks/a79859fc9d1736ebb.output`
- Wave D : `/private/tmp/.../tasks/af4ab934ea16aaec8.output`
- Wave E : `/private/tmp/.../tasks/a1dc7ddbde3a0d706.output`
- Wave F : `/private/tmp/.../tasks/add20ebb418a1a82b.output`

**Méthode** : 6 sub-agents read-only parallèles, file:line citations obligatoires, anti-fabrication strict, NO heal pendant audit. Cross-validation Wave A ↔ Wave F sur 4 P0.

---

## Frozen-zones — diff status

Aucun frozen-zone touché pendant cet audit (read-only). Pour le heal Sprint 1-4 :
- Pas de touch nécessaire `KioskWizardComponent.vue` / `pos-wizard.js` / `pos-wizard.css` / `admin-pos-v4.blade.php` / fiscal services
- Touch attendu : `PaymentService.php` (déjà touché iter11) + `OrderService.php` (déjà touché iter11) + `PricingService` éventuellement → LOCK_*.md à créer si besoin

NF525 chain intacte — pas de write sur `fiscal_sequence_no`, `audit_logs`, `z_reports`.
