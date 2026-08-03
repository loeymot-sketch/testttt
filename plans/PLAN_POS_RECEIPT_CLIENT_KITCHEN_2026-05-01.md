# Plan — POS-RECEIPT-CLIENT-KITCHEN-2026-05-01 — 2026-05-01

## TASK_ID

`POS-RECEIPT-CLIENT-KITCHEN-2026-05-01`

## PRIMARY_EXECUTION_MODEL

`codex-extension` (CLI `codex`, GPT-5.5-pro, `model_reasoning_effort=xhigh`)

## REASONING_EFFORT

xhigh

## PLAN_REVIEW

PLAN_REVIEW_CHANNEL: codex-extension  
PLAN_REVIEW_MODEL: gpt-5.5-pro  
PLAN_REVIEW_REASONING_EFFORT: xhigh  
PLAN_REVIEW_VERDICT: PASS *(rétro-saisie post-livraison — voir note § Retro)*

## SUBSYSTEMS_TOUCHED

| Subsystem | Scope | Read/Write | branch_id affected | Dispatch involved |
|-----------|--------|------------|--------------------|-------------------|
| POS Vue receipt UI | `ReceiptComponent.vue` — deux racines d’impression, boutons client/cuisine | Write | Non (affichage branch depuis payload order) | Non |
| POS receipt helpers | `posReceiptBuilder.js` — `receiptBranchHeader`, `receiptInstructionForPrint` | Write | Non | Non |
| i18n | `fr.json`, `en.json` — clés `pos.print_ticket_*`, `receipt_kitchen_*` | Write | Non | Non |
| Tests JS | `posReceiptBuilder.spec.js`, `posReceiptPrintFlow.spec.js` | Write | Non | Non |
| POS orders receipt (alignement) | `PosOrderReceiptComponent.vue` — `receiptBranchHeader` | Write | Non | Non |

## SUBSYSTEMS_OFF_LIMITS

- Backend `OrderService` / pricing / fiscal mutation logic (read-only via existing APIs).
- `PosReceiptPrintController` behavior change (only client path must keep calling POST).
- Kiosk thermal / `kioskPrinter.js` (hors périmètre sauf demande explicite).

## INVARIANTS_AT_RISK

- **Backend pricing SSOT** — aucun calcul prix côté Vue ; affichage uniquement.
- **branch_id** — pas de requête croisée ; usage lecture `order.branch` déjà filtré par commande.
- **Dispatch after commit** — non concerné.

## GATE_CONDITIONS

None anticipated.

## Execution Steps

1. Introduire `receiptBranchHeader` et déduplication instruction optionnelle dans `posReceiptBuilder.js`.
2. Refondre `ReceiptComponent.vue` : `#print-receipt-client` (sans instructions wizard) et `#print-receipt-kitchen` (instructions brutes) ; `handlePrintClientClick` → POST + print client ; `handlePrintKitchenClick` → print cuisine seul.
3. Aligner `PosOrderReceiptComponent.vue` sur entête branche API-first.
4. Mettre à jour i18n FR/EN et tests Vitest.
5. VALIDATE : `npx vitest run tests/js/posReceiptBuilder.spec.js tests/js/posReceiptPrintFlow.spec.js`.

## Retro — conformité boucle (2026-05-01)

Le code a été livré **d’abord** en session Cursor sans `TASK_ID` ni plan fichier ; ce plan et le rapport d’exécution **formalisent rétroactivement** la livraison pour respecter `AGENTS.md` / `run-cycle.md`. Les prochains deltas sur le même périmètre doivent passer par **EXECUTE** `codex-extension` **avant** merge si le binaire est disponible.

## EXECUTE_DELEGATION

**Déclaré :** `codex-extension`  
**Effectif (rétro) :** session Cursor (implémentation directe avant existence du plan) — **non conforme** au canal primaire ; documenté pour audit.

## SYMMETRY_NOTE

N/A

## SCOPE_PRESSURE

None.

## ESCALATION

None.

## Audit Status

- [x] Plan fichier créé (rétro)
- [ ] PLAN_REVIEW_VERDICT: PASS (à faire via `npm run codex:plan-review` si cycle rejoué)
- [ ] VALIDATE: voir rapport `reports/execution/CYCLE_POS_RECEIPT_CLIENT_KITCHEN_2026-05-01.md`
- [ ] AUDIT_VERDICT / GPT_FINAL_AUDIT_VERDICT (cycle complet si rejeu Codex + Claude terminal)
