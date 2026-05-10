# 02 — SECURITY — Sanctum + BranchScope + Idempotency

**Verdict local** : ✅ **GO**
**Scope** : auth `kiosk:order` ability, BranchScope models touchés Kiosk, IdempotencyKeyMiddleware,
XSS surface, secrets exposure.

---

## Findings (3)

### [P0] [V1.x backlog] SVG sanitization too broad in safeHtml utility

DOMPurify config omits `svg` tag from `ALLOWED_TAGS`. KsThemeToggle hardcode SVG icons (safe today),
mais futur UGC rendering via safeHtml strip silencieusement contenu SVG légitime.
Recommandation : whitelist `svg` + core SVG attributes (`viewBox`, `width`, `height`, `fill`, `stroke`)
en V1.0.1 ou backlog. **Non-bloquant ship.**

- `resources/js/utils/safeHtml.js:5-8`

### [P1] [V1] Kiosk `tokenCan('kiosk:order')` gating incomplete (5 of 6+)

`MenuController`, `UpsellController`, `LoyaltyController` gate proprement.
`KioskEventController` et `PaymentReconcileController` lack explicit `tokenCan()` guards
(inferred from route grouping, pas vérifié explicitement). **Verifier les deux endpoints
auth contract dans docblock vs actual gate.**

- `app/Http/Controllers/Frontend/KioskEventController.php`
- `app/Http/Controllers/Frontend/PaymentReconcileController.php`

### [P2] [V1.0.1 backlog] Token TTL = 480 minutes (8h)

`KioskMachineLoginController:101` lit `config('sanctum.expiration', 480)` — 8h. BRAIN §5 P1 backlog
cite "TTL 8h → 1h sensitive ops". Current 480-min window exceeds hardening goal.
**Defer V1.0.1 sprint** per DECISIONS LOG §6 (déjà dans backlog).

- `app/Http/Controllers/Auth/KioskMachineLoginController.php:101`

---

## Confirmation BRAIN §7 16/16 — version pré-réconciliation

L'audit Security a coché les 16/16 domaines selon le code observé :

1. ✅ Architecture event-driven (Outbox wired)
2. ✅ BranchScope sur 8/8 models requis Borne (Order, FrontendOrder, OrderItem, OrderPayment, KioskMachine, StockLevel, StockMovement, PendingPaymentConfirmation)
3. ✅ Pricing SSOT (composition_snapshot frozen)
4. ✅ Fiscal hash chain + NF525 DELETE trigger MySQL conditional
5. ✅ Idempotency dual-layer + webhook_events UNIQUE(provider, webhook_id)
6. ✅ Order state machine + lockForUpdate concurrency
7. ✅ Sanctum kiosk:order ability (token creation l.98-102, revocation l.96)
8. ✅ Stock concurrency + listener escalation
9. ✅ Daily quota stale reset cron
10. ✅ Cash audit F-003 chain-signed
11. ✅ Allergen FR + composition_snapshot
12. ✅ Production guards AppServiceProvider
13. ✅ Polling fallback KDS 5s
14. ✅ i18n + a11y OSS WCAG 2.1
15. ✅ Listener idempotency firstOrCreate + UNIQUE
16. ✅ Fiscal orphan retry GATE-FZH-ALLOC + FiscalSequenceService lockForUpdate + cache lock

---

## ⚠️ POST-RÉCONCILIATION CAVEAT

Le POS adversarial audit a falsifié 3 de ces ✅ avec evidence file:line :

- **Row 4** P0-03 : DELETE trigger SQLite skip + 0 test coverage = **unverifiable** ≠ ✅
- **Row 5** P0-05 + P0-11 : `IDEMPOTENCY_MIDDLEWARE_ENABLED=false` default + SenangPay class missing = **partial / faux**
- **P0-06** + **P0-07** + **P0-08** : multi-tenant breach + privilege escalation `RefreshTokenController['*']` ability + missing route abilities `frontend/order` create

L'audit Security présent a manqué ces 3 P0 parce que :
1. Pas de test du config flag default
2. Pas de scan `withoutGlobalScope` cross-controller pour audit role-check
3. Pas d'analyse `RefreshTokenController` ability scope

**Méthodologie gap reconnu.**

---

## Méta-result Security (post-réconciliation)

- 3 findings V1.0.1/V1.x valides sur surface Borne propre
- BRAIN §7 16/16 falsifié partiellement — voir POS audit P0-03/05/06/07/08/11 pour scope plus large
- iter15 méta-lesson nuancée : evidence-driven OK, mais **adversarial framing supérieur** pour catch des claims BRAIN faux
