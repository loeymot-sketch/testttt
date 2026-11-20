Plan : `plans/PLAN_ORDER-INTEGRITY-KDS-LOYALTY-20260914_2026-09-14.md`. Sous-systèmes autorisés : Pricing/order sealing, POS, Kiosk Loyalty, KDS/ticket, tests et copie web. Gate approuvé : `docs/gates/GATE_ORDER-INTEGRITY-KDS-LOYALTY-20260914_2026-09-14.md`.

Invariants : prix exclusivement serveur ; isolation `branch_id` ; snapshot fiscal immuable ; dispatch après commit ; parité `OrderService`/`FrontendOrderService` ; aucune activation de livraison web.
