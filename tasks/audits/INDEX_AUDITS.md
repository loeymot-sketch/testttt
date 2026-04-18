# INDEX_AUDITS — FoodKing Audits Ciblés (POS · Menu · Kiosk)

> Dossier : `tasks/audits/`
> Créé : 2026-04-17
> Auteur : Claude (Cowork orchestrator) pour consommation par Claude-in-Cursor (re-planification) → exécution GPT-5.4 / Composer.
> Doctrine : **zéro modification de code**, lecture + inspection statique uniquement. Chaque audit produit un verdict (PASS / WARN / BLOCKED) et, si nécessaire, une task d'action dans `tasks/`.

---

## Règles de consommation par Claude-in-Cursor

1. Lire ce fichier avant tout plan.
2. Choisir un audit (P0 d'abord).
3. Re-planifier le brief : challenger questions, enrichir les greps, ajouter des fichiers oubliés, ajuster la grille de verdict.
4. Déléguer l'exécution à un sous-agent (Composer si lecture mécanique, GPT-5.4 si analyse complexe).
5. Rapport déposé dans `reports/review/AUDIT_<ID>_<DATE>.md`.
6. Si verdict = WARN/BLOCKED → créer une `TASK_*` correctrice dans `tasks/` et mettre à jour `tasks/INDEX_V1.md`.

---

## Convention de format (par audit)

Chaque fichier suit :
- **Meta** : Priority, PRIMARY_MODEL=Claude, TEST_STRATEGY=static-inspection, DEPENDS_ON, Estimation
- **Contexte** : pourquoi cet audit existe, risque métier
- **Scope** (SUBSYSTEMS_TOUCHED / SUBSYSTEMS_OFF_LIMITS)
- **Invariants at Risk**
- **Questions d'audit** (numérotées, check-list)
- **Fichiers à lire** (chemins exacts)
- **Grep patterns** (commandes prêtes à copier)
- **Evidence required**
- **Grille de verdict** (PASS / WARN / BLOCKED)
- **Livrable attendu**

---

## Vague A — POS (caisse) — 10 audits

| # | ID | Priorité | Objet | Dépendances |
|---|---|---|---|---|
| A1 | AUDIT_POS_ORDER_CREATION_001 | P0 | Cycle de création commande POS (posOrderStore → Order → events) | — |
| A2 | AUDIT_POS_PAYMENT_CASH_CARD_002 | P0 | Paiements cash / carte / split / changePaymentStatus | A1 |
| A3 | AUDIT_POS_STATUS_TRANSITIONS_003 | P0 | Transitions d'états POS (changeStatus, OrderStateMachine) | A1 |
| A4 | AUDIT_POS_BRANCH_ISOLATION_004 | P0 | Isolation branch_id côté POS (BranchScope, middleware, scopes Eloquent) | — |
| A5 | AUDIT_POS_COUPON_LOYALTY_005 | P1 | Coupons + loyalty points côté POS (application, plafonds, invariants prix) | A1 |
| A6 | AUDIT_POS_WIZARD_CART_006 | P1 | Wizard panier POS (options, variations, addons, supplements, consistency Vue↔API) | A1 |
| A7 | AUDIT_POS_IDEMPOTENCY_RETRIES_007 | P1 | Idempotency-Key + retries + double-submit côté POS | A1 |
| A8 | AUDIT_POS_AUTH_SESSION_REFRESH_008 | P1 | Auth POS (Sanctum, session admin, refresh, logout, CSRF) | — |
| A9 | AUDIT_POS_RECEIPT_INSTRUCTIONS_009 | P2 | Ticket de caisse, impression, instructions spéciales, notes cuisine | A1, A3 |
| A10 | AUDIT_POS_AMEND_ORDER_GAP_010 | P2 | Modification d'une commande existante (items, quantités, prix, annulation partielle) | A1, A3 |

## Vague B — Menu (catégories / produits) — 5 audits

| # | ID | Priorité | Objet | Dépendances |
|---|---|---|---|---|
| B1 | AUDIT_MENU_ITEM_CRUD_011 | P0 | CRUD Items (création, édition, suppression, soft-delete, branch_id) | — |
| B2 | AUDIT_MENU_CATEGORY_SUPPLEMENTS_012 | P1 | Catégories + supplements : hiérarchie, ordre d'affichage, visibilité par surface | B1 |
| B3 | AUDIT_MENU_VARIATIONS_ADDONS_EXTRAS_013 | P0 | Variations / addons / extras : modèle, pricing, stock, règles min/max | B1 |
| B4 | AUDIT_MENU_TAX_PRICING_CASCADE_014 | P0 | TVA + cascade de prix item → variation → addon (cohérence backend/frontend) | B1, B3 |
| B5 | AUDIT_MENU_AVAILABILITY_86_015 | P1 | "86" (rupture), disponibilité par créneau, sync POS↔Kiosk↔KDS | B1 |

## Vague C — Kiosk (borne) — 10 audits

| # | ID | Priorité | Objet | Dépendances |
|---|---|---|---|---|
| C1 | AUDIT_KIOSK_ORDER_CREATION_016 | P0 | Cycle création commande kiosk (myOrderStore → FrontendOrder → outbox) | — |
| C2 | AUDIT_KIOSK_PAYMENT_CASH_017 | P0 | Cash kiosk : PAID immédiat + auto-ACCEPT + finalizePaidKioskOrder | C1 |
| C3 | AUDIT_KIOSK_PAYMENT_DEFERRED_CARD_TR_018 | P0 | Carte / TicketRestaurant : paymentConfirm différé, race conditions TPE | C1 |
| C4 | AUDIT_KIOSK_AUTH_TOKEN_ABILITY_019 | P0 | Sanctum token + ability `kiosk:order`, rotation, revoke, fuite | — |
| C5 | AUDIT_KIOSK_WIZARD_UX_IDLE_020 | P1 | Wizard kiosk + idle timeout 180s + reset panier + UX friction | C1 |
| C6 | AUDIT_KIOSK_HARDWARE_BRIDGE_021 | P1 | window.borne.* : imprimante, tiroir, TPE, fallback, erreurs matérielles | — |
| C7 | AUDIT_KIOSK_BRANCH_ISOLATION_022 | P0 | Branch isolation kiosk (channels.php, auth, BranchScope) | — |
| C8 | AUDIT_KIOSK_LOYALTY_UPSELL_023 | P2 | Loyalty + upsell kiosk (opt-in, consent RGPD, prix SSOT) | C1 |
| C9 | AUDIT_KIOSK_REALTIME_ECHO_POLLING_024 | P1 | Temps réel kiosk (Echo subscribe, fallback polling, reconnection) | C1 |
| C10 | AUDIT_KIOSK_CANCEL_REFUND_025 | P2 | Annulation / remboursement kiosk (post-paiement, avant préparation) | C1, C2, C3 |

---

## Ordre d'exécution recommandé

**Sprint 1 — P0 critiques** (8 audits) :
A1 → A4 → A2 → A3 → B1 → B4 → C1 → C7

**Sprint 2 — P0 suite + P1 prioritaires** (9 audits) :
C4 → C2 → C3 → B3 → A6 → A5 → C5 → C9 → B5

**Sprint 3 — P1 + P2 finition** (8 audits) :
A7 → A8 → B2 → C6 → C8 → A9 → A10 → C10

---

## Livrable global

Une fois les 25 audits consommés :
1. Synthèse dans `reports/review/AUDIT_SUMMARY_2026-XX-XX.md`.
2. Liste des tasks correctrices créées.
3. Matrice de risques résiduels.
4. Mise à jour `docs/PROJECT_CONTINUITY_AND_VISION.md` (section "État système post-audit").

---

## Status
- [x] Index créé
- [ ] Audits rédigés (26/26)
- [ ] Consommation Cursor démarrée
- [ ] Synthèse finale produite
