## Cible audit
- plans/PLAN_POS_V4_IMPL_EXEC_FINAL_2026-04-26.md (Claude terminal — sections 0–8, gates G0–G8, KPI, STOP)
- plans/PLAN_POS_V4_IMPL_MASTER_2026-04-26.md (granularité 14 zones + §15 Claude amendements)
- reports/audit/REAUDIT_G55PRO_POS_V4_PRECLAUDE_2026-04-26.md (proxy GPT 503)

## Contrainte
- Exécution = template + style + pos-v4.css. Script gelé. Invariants: pricing_ssot, order_status, branch_id, commit_before_dispatch.
- 9 SFC réels dans resources/js/components/admin/pos: ReceiptComponent, PosComponent, ItemComponent, ParkedOrdersComponent, FloorplanComponent, PaymentComponent, ReceiptDuplicataMarker, SkeletonGrid, CreateCustomerAddressComponent.
- KIOSK + KDS + Branch banner + Fiscal sont à intégrer mentalement dans l'audit.

## Mission
Adopter le rôle GPT-5.5 critique (pro indisponible, base 5.5 utilisé en repli) et produire un re-audit incisif, aligné FoodKing, qui peut servir de second avis API au plan Claude.
