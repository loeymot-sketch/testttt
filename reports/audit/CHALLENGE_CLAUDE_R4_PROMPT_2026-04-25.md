Orchestrateur final FoodKing — consolide la dispute Codex / Claude.

Contexte et limites :
- Date challenge : 2026-04-25.
- Ceci est un audit / pre-cycle hors run-cycle produit actif. Ne démarre pas de cycle, ne propose pas de patch direct, ne self-approuve aucun gate.
- Respecte AGENTS.md : backend pricing SSOT, OrderStatus enum, branch_id strict, dispatch après commit, frozen zones, symétrie OrderService / FrontendOrderService.
- Lis dans cet ordre :
  1. reports/audit/CHALLENGE_CODEX_R1_2026-04-25.md
  2. reports/audit/CHALLENGE_CLAUDE_R2_2026-04-25.md
  3. reports/audit/CHALLENGE_CODEX_R3_2026-04-25.md
- Si une preuve test manque dans les rapports propres, tu peux consulter reports/audit/CHALLENGE_CODEX_R3_2026-04-25_TRACE.md seulement pour confirmer les commandes/tests exécutés.

Objectif :
Produis le rapport final consolidé, en français, dense et exploitable comme entrée `PRIOR_CONTEXT` ou section "Audit externe" d'un futur plan `plans/PLAN_*`. Le rapport doit arbitrer : ce qui est validé, ce qui est contesté, ce qui est faux positif, ce qui reste à prouver, et l'ordre d'exécution V1.

Définition de V1 à trancher explicitement :
- V1 fonctionnelle minimale candidate = backend + surfaces POS + Borne/Kiosk + KDS, avec intégrité prix/statuts/branch_id/events, sans conformité fiscale NF525 complète sauf si tu décides qu'elle est nécessaire au gate.
- Si tu changes cette définition, dis-le en 2 phrases maximum et justifie par invariant ou preuve.

Format obligatoire :

## 1) Tableau d'arbitrage
Tableau : `thème | validé (qui) | contesté (qui) | tranché (Claude final) | priorité | preuve attendue`.
Inclure au minimum : payment-confirm, KDS transitions, OrderStatusRequest, expected_status, branch_id list/show/report, promo borne, no-op side effects, idempotency POS catch, TPE accepted/backend confirm failed, POS cash via KDS endpoint, OrderService/FrontendOrderService symmetry, NF525 sealed-Z, outbox, EventContract frontend, variation quantity preview.

## 2) P0 V1 — ordre d'exécution
Liste ordonnée P0, avec pour chaque item :
- objectif concret
- fichiers/surfaces probables
- preuve attendue (test/doc)
- routing recommandé (`codex-extension` primaire, fallback seulement si CLI Codex indisponible)

## 3) P1 / P2
Deux listes séparées. Ne mélange pas dette utile et blocage V1.

## 4) Faux positifs écartés
Liste les points que le rapport final exclut, avec une phrase de justification chacun.

## 5) Risque résiduel + preuves attendues
Tableau : `risque | pourquoi il reste ouvert | preuve minimale | bloquant V1 ?`.

## 6) Tests et preuves déjà vus
Récapitule les tests explicitement exécutés/mentionnés pendant R3 et leur résultat si disponible.

## 7) Décision finale
Une ligne finale exacte parmi :
`CONSOLIDATED_VERDICT: READY_TO_PLAN`
`CONSOLIDATED_VERDICT: NEEDS_EVIDENCE`
`CONSOLIDATED_VERDICT: HUMAN_SPLIT`

Règles de sortie :
- Cite chemins:line quand les rapports les fournissent. Si tu infères sans preuve directe, marque `inférence`.
- Ne répète pas longuement le narratif des rounds ; tranche.
- Si une priorité dépend du scope NF525 ou fiscal légal, sépare clairement "V1 opérationnelle" et "V1 fiscale".
- Ne modifie aucun fichier : écris uniquement dans le flux sortant.
