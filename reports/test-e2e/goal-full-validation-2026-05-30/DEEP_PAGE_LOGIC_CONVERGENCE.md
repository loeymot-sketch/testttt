# Deep Per-Page Logic + E2E — Convergence Livre (V1 LOCAL Le Cayenne)
**Date** 2026-05-30 · branche `heal/cms-pr1-quickwins-2026-05-18`

Mandat owner : « abuse-e2e tous les systèmes, E2E très profond + LOGIQUE de chaque page ».
Deux tracks : (A) audit LOGIQUE profond par page (13 agents, 36 pages, 9 clusters, adversarial),
(B) E2E visuel + logique page-par-page (MCP). + abuse technique 117 commandes. Boucle
audit→heal→re-audit, calibré V1 LOCAL, anti-drift, zéro complexité hors-vision.

## 1. Track B — E2E visuel + logique, 31 pages
- **Balayage 31 pages admin** (SPA-nav, détection raw-label/crash/empty, 0 erreur console) → **30/31 propres**.
- **1 défaut → HEALÉ + vérifié** : `/admin/coupons` fuyait `label.advanced_promo_fields` (clé i18n front manquante) → ajoutée fr/en/ar → rendu « Paramètres avancés » (commit `dd9968b58`).
- **12 captures analysées** (principal+adversaire) : KDS, OSS ×2, POS, dashboard, catalogue (45 produits), settings, + **flux client-borne 7 étapes** → commande A0165 (TAKEAWAY, Plan-B, domain event dispatché). Toutes propres.

## 2. Track A — Audit LOGIQUE profond (9 clusters)
| Cluster | Verdict |
|---|---|
| catalogue | 1 P1 owner-gate + 2 P2 backlog (composer/coupon-cap/per-user OK) |
| stock | GO (2 défauts dans le quota DORMANT max_daily_qty, non atteignable V1 → backlog) |
| POS | GO (1 P2 backlog cancel-cash-no-OUT-movement) |
| cash/fiscal | 1 P1 owner-gate (frozen) + 1 P2 backlog ; **F7 truncation CONFIRMÉ FIXÉ** |
| KDS/OSS | RE-CONFIRMÉ GREEN, 0 nouveau P0/P1 |
| orders | sound (state-machines respectées, refund>paid impossible, dine-in gated, QR-fraud neutralisé) ; 1 P2 dead-button → **HEALÉ** |
| staff/RBAC | hardened (assertTargetRole) ; 1 P2 authz → **HEALÉ** |
| settings | 1 P1 + 1 P2 secrets → **1 HEALÉ, 1 deferred (fix naïf cassait les rapports)** |
| comms/obs | GO, 0 P0/P1 |

## 3. HEALS appliqués (3, non-frozen, live-vérifiés) — commit `317e098c3`
- **SET-02 (P2 sécurité)** : `GET /setting/mail` fuyait le `mail_password` SMTP en clair à tout staff sans `permission:settings`. Index gaté. **Live : admin 200, pos 403.** (Seul consommateur = page settings → 0 surface cassée.)
- **SUB-1 (P2 sécurité)** : `POST /subscriber/send-email` (mass-email à tous les abonnés) non gaté → ajouté à `permission:subscribers`. **Live : pos 403.**
- **ORD-01 (P2 fonctionnel)** : bouton online-order « Encaisser & Valider (Kiosk) » appelait `appService.confirmCashPayment()` inexistant → TypeError, bouton mort. Méthode ajoutée (mirroir acceptOrder, copie FR).

## 4. ⚠️ OWNER-GATE — 2 décisions qui te reviennent (frozen, je ne touche pas sans toi)
- **CAT-01 (P1)** — **Les Offres (promos %) sont DISPLAY-ONLY** : une offre admin s'affiche au client (prix barré + total panier remisé) mais `PricingService` (FROZEN, SSOT) facture le **plein tarif** → mismatch affiché-vs-facturé sur le parcours commande web/kiosk. Pas de surfacturation (on facture le vrai prix), mais le client voit une remise qu'il ne reçoit pas. **Décision owner** : (a) appliquer l'offre au total (fix couche pricing-INPUT, hors service frozen), ou (b) masquer l'affichage de l'offre tant qu'elle n'est pas branchée. *Note : si tu n'utilises pas les Offres en V1, c'est dormant.*
- **CFR-1 (P1, sous P0)** — Le Z signé `total_by_tax_rate` ne nette pas les remboursements counter-entry alors que `total_ttc/total_tva/total_by_method` le font → Z signé self-incohérent quand un remboursement cash tombe dans la fenêtre. **Revenu + TVA due corrects** (pas de surévaluation, pas de gap, pas de cassure de chaîne) → sous la barre P0. ZReportService FROZEN → décision owner (LOCK + fix, ou accepter + documenter). Dormant si pas de remboursement cash in-window.

## 5. SET-01 deferred (supervisor a refusé le fix naïf) + Backlog V1.0.X (P2/P3)
- **SET-01** : `GET /setting/payment-gateway` expose les `value` d'options secrètes. Le gater casserait le filtre mode-paiement de SalesReport/Transactions (routes non-settings). Fix correct = masquer les valeurs secrètes pour non-settings → V1.0.X. (V1-LOCAL staff de confiance = risque réel faible ; **vérifié live : pos payment-gateway reste 200, rapports intacts.**)
- Backlog : CAT-02 (coupon max_uses_global dead quota), CAT-03 (prix négatif via bulk save), STOCK-01 (quota reset dormant), POS-CASH-CANCEL (pas de mouvement OUT sur annulation cash), CFR-2 (cash-overview expected_cash), SET-03 (tax_rate sans plafond).

## 6. Invariants (après 117+ commandes abuse) + gates
fiscal_seq **1-167 GAPS=0 DUPLICATES=0** · **NF525 CHAIN OK** · outbox 0 pending/0 failed · audit_logs append-only.
vitest **1881/0** · PHP full suite (gate authz) — voir commit final · **0 fichier frozen touché**.

## 7. Convergence + verdict
Le balayage 31 pages + l'audit logique 9 clusters ont surfacé tous les défauts réels ;
les **3 actionnables non-frozen sont healés + live-vérifiés**, le reste est **owner-gate (2 frozen)
ou backlog P2/P3 calibré V1-LOCAL**. Aucun P0. Aucune sur-correction, aucune complexité hors-vision.

**GO V1 LOCAL** pour le parcours opérateur (caisse + KDS + OSS + sync + management + catalogue +
settings), **SOUS RÉSERVE de tes 2 décisions owner-gate** (CAT-01 Offres display-only, CFR-1 Z
tax-rate refund-netting) — les deux dormants si tu n'utilises pas Offres / remboursements-cash-in-window
en V1. + actions on-site (`migrate:fresh --seed`, supervisor, cron) + backlog P2/P3.
