# ULTRA A→Z — Wave 5 : GÉRANCE DB + HISTORIQUE + SYNC + INTÉGRITÉ — 2026-07-04
**Goal** (nouvelle dimension explicite : « gérance de la base de données et historique, toute la gestion »).
Audit adversaire + raisonnement (Wave 5 + 5b, ~18 agents) + **ground-truth live queries** sur la vraie base
(foodking_e2e). HEAD `59c1279e8`. Verdict : **la couche gérance est MATURE et bien instrumentée.**

## 1. HEALÉS (TDD, non-frozen, commités)
| Commit | Le défaut + le fix |
|---|---|
| `ef960fcf9` | **`iter15:cleanup-test-orders` hard-deletait des ordres FISCALISÉS sans garde** → un n° de séquence fiscale alloué disparaissait physiquement = rupture gap-free NF525 (ZReportService agrège `withTrashed` : soft-delete survit, hard-delete disparaît). Ajout `whereNull('fiscal_sequence_no')` (miroir du jumeau sûr `CleanupWebTestOrdersCommand`) + cascade `cash_movements` (orphelins trail-caisse) + `order_payments` (FK RESTRICT → sinon rollback total). Outil test-only (bloque prod, tokens test only, non schedulé) → P3, mais footgun réel + polluait la DB e2e. TDD 2/2. |
| `59c1279e8` | **`create_media_table` = seule des 178 migrations sans `down()`** → rollback impossible. Ajout du drop. **178/178 réversibles.** |

## 2. VÉRIFIÉ MATURE (ground-truth live + adversaire — la gérance est solide)
| Dimension | Preuve |
|---|---|
| **Index** | Toutes les requêtes chaudes couvertes : `idx_orders_branch_payment` (counter-collect/encaissement), `idx_orders_datetime`+`idx_orders_status` (fenêtre KDS), `idx_orders_status_updated` (sync delta, migration durabilité 2026-07-03), + unique fiscal/queue/idempotency. |
| **Migrations** | **178/178 réversibles** (après fix). Migrations récentes (durability, kitchen-timing) correctes. |
| **Historique/audit/rétention** | `PruneOutboxCommand` (90j) + `webhook:prune` + `order_quotes` purge **ne touchent JAMAIS `audit_logs`/`z_reports`** (rétention 6 ans NF525 garantie). |
| **Outbox/synchro** | **ROBUSTE** (confirmé adversaire) : fan-out COMPLET (create/status/payment/table/cancel/refund/kds-recall), swallow escalation branchée fiscal-critical, tout sur la queue **high**, rescue(attempts<5) ⟂ retry-failed(≥5), **staleness monitor PAGE réellement (exit 1)**, ordre FIFO afterCommit sûr en mono-worker. |
| **Intégrité données** | Split-tranche **PARFAIT** (0/259 écart somme=total) ; totaux commande **99,6 %** cohérents (12/3094 vieux cas remise/coupon, non systémique) ; **0 order_items/order_payments orphelins** ; domain_events cruft auto-prunée. |
| **Ops** | `foodking:backup-daily` + **`backup:verify-restore`** (backup ET vérif-restore schedulés) ; `storage/backups/db-daily/` peuplé ; 28 `withoutOverlapping` sur les crons ; garde boot prod AppServiceProvider. |
| **Visuel gérance** | Vue Caisse Unifiée (réconciliation `Fond 150€ + encaissé 347,20€ = attendu 497,20€` — math juste) + Encaissement (file 11 commandes, Encaisser) — **0 raw label, propres**. |

## 3. DOCUMENTÉ — Uber workstream go-live (dormant, à traiter en bloc à l'activation)
- **P3 Uber `OrderCreated` bypass** (`UberWebhookController::createFromUber`) : ne dispatche pas `OrderCreated` → **pas de décrément stock/disponibilité = SURVENTE SILENCIEUSE quand Uber sera live** (un article épuisé sur Uber reste dispo borne/POS). Réfuté : les impressions sont déjà `source_surface`-gated kiosk (ne fireraient pas), et le KDS delta-poll montre quand même la commande (ACCEPT). Le fond = le décrément stock. Fix = `OrderCreated::dispatch($order)` après commit (DispatchableAfterCommit sûr). **S'ajoute aux items Uber Waves 2-3** (article-non-mappé, UNIQUE transaction_id, is_advance_order, routing d'event) — à faire + tester ENSEMBLE au go-live Uber.
- **P3 minor** : poison-rows outbox non-remédiées avant le prune 90j → le monitor reste FAILURE (fatigue d'alerte). + 18 vieux ordres enum-drift (donnée héritée, code actuel propre) + 21 pending-orphan events triviaux.

## 4. GATES
- Iter15 guard TDD 2/2 · 178/178 migrations réversibles · gérance layer vérifiée mature (live + adversaire) · frozen 0 · NF525 CHAIN OK.

## 5. CONVERGENCE gérance
La gestion base de données / historique / synchronisation / intégrité / ops est **MATURE et validée** : bien
indexée, réversible, rétention-safe, outbox robuste (monitor qui page), intégrité prouvée (split parfait), backups
vérifiés-restore. 2 vrais défauts trouvés+healés (Iter15, migration). Résidu = Uber dormant + micro-hygiène.
Mes propres hypothèses initiales (trou d'observabilité) ont été **réfutées par la vérification** — l'instrumentation existe déjà.
