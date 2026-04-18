# VISION — POS final (POS-9.5 → POS-9.10 et au-delà) — 2026-04-18

**Auteur** : Track B (POS orchestrator).
**Période** : post-Phase H Hardening, post-Kiosk P9.5 (main @ `2df255140`).
**Horizon** : livrer un POS FoodKing **production-grade NF525**, cohérent avec le Kiosk,
auditable de bout en bout, multi-tender, multi-actor, résilient aux pannes réseau / caisse /
imprimante / écran. Ce document trace **la thèse** ; les plans détaillés (POS-9.2/9.3, puis
9.5+) en découlent et s'y rattachent explicitement.

> Règle cardinale : **une seule `OrderStateMachine` pour toutes les surfaces**
> (POS, Kiosk, frontend web, KDS, OSS). Si une nouvelle transition doit exister, elle
> passe d'abord dans la machine, **puis** dans chaque surface. Jamais l'inverse.

---

## 1. Lecture stratégique du chemin restant

### 1.1 Ce que POS est aujourd'hui (post-Phase H + P9.5 merged)

Forces consolidées pendant POS-9.1 + POS-9.4 + Phase H :

- **SSOT pricing** : `PricingService::calculateOrder(forPos)` + cross-item guard actif sur toutes les surfaces (P9.5.6). Le payload POS ne peut plus fabriquer de prix.
- **`branch_id` serveur uniquement** : CI invariants (6/6 green) garantit l'absence de `$request->input('branch_id')` dans tout le order-flow.
- **Permissions Spatie** fines : `pos-apply-discount`, `pos-destroy-paid`, `pos-manage-fiscal`, `pos-reopen-z` (POS-9.4.12).
- **NF525 backend** : Z/X reports fonctionnels, audit_logs immuables + chaîne HMAC + unique index + lock concurrent (Phase H.2), fiscal_sequence sur chaque order fiscalement signé, archive 6 ans memory-bounded (H.3.3), runbook secrets rotation (H.3.9).
- **Event Contract V1** canonique : envelope stricte, broadcast after-commit, outbox pattern.
- **Observabilité fiscale** : log channel dédié `fiscal` (H.3.2), rate-limit sur `/z-report/{open,close}` (H.3.1).
- **Intégrité agrégats** : `Order::restore()` bloqué (H.3.5), `destroy()` hard-delete children contrôlé.

### 1.2 Ce que POS n'est PAS encore

Failles résiduelles qui empêchent la mise en production :

1. **State machine non-canonique** — 11 call-sites écrivent `$order->status = X; save()` directement (inventaire POS-GA-F-09). La machine existe mais 4 chemins critiques la contournent (changeStatus, changePaymentStatus, FrontendOrderService L550/L661/L736). C'est le **plus grand risque audit NF525** : un cancel après Z-report qui ne passe pas par la machine ne logge pas, ne signe pas, ne refuse pas.

2. **Paiement binaire** — `payment_status` ∈ {UNPAID, PAID} uniquement. Aucune notion de tender, d'acompte, de split-bill, de remboursement partiel. Impossible d'encaisser 30 € en CB + 20 € en espèces sur une addition de 50 €.

3. **Events canoniques manquants** — `OrderCancelled`, `PaymentRecorded`, `OrderRefunded` n'existent pas. Annulation et remboursement diffusent `OrderStatusChanged` générique, ce qui empêche les consumers (Analytics, comptabilité) de déclencher des règles métier spécifiques.

4. **`correlation_id` régénéré** à chaque event — impossible de tracer un scénario bout-en-bout dans les logs.

5. **3 wire-ins BLOCKER ouverts** depuis POS-9.4 (zone frozen pendant P9.5) :
   - **POS-9.4.2b** : `FiscalSequenceService::next($branchId)` non appelé dans `posOrderStore` / `myOrderStore` → ordres produits sans identité fiscale.
   - **POS-9.4.5** : `AuditLogService::write()` non appelé sur cancel/destroy/discount/refund/changePaymentStatus → trail d'audit incomplet.
   - **POS-9.4.10** : `destroy()` ne refuse pas un ordre scellé par un Z clos → violation NF525 (un ticket archivé ne peut plus être supprimé).

6. **Fin de journée incomplète** — Z-report existe mais n'est pas intégré à un flux "clôture caisse" : tiroir, dépôt, archivage, impression, reset compteurs.

7. **Tiroir caisse (cash drawer)** — POS-9.5 prévu pour ouverture tiroir, comptage, écarts, transferts (POS-GA-F-53). Actuellement : PaymentService génère la ligne comptable mais ne commande pas l'ouverture hardware et ne trace pas les mouvements intermédiaires.

8. **Split-bill UX** — endpoint backend absent, UI absente. Actuellement un groupe de 6 avec 3 additions doit payer en 3 transactions séparées (aberrant).

9. **Outbox hardening** — les 7 erreurs Menu/Pusher résiduelles (baseline pré-Phase H) montrent que le dispatcher outbox n'est pas idempotent en mode test et peut leaker. POS-9.10 y répond.

### 1.3 Principes directeurs de la suite

Pour chaque vague à venir :

- **L'état mute toujours via `OrderStateMachine::apply`** — aucune exception.
- **Tout dispatch d'event est `DB::afterCommit()`** — aucune exception.
- **Toute écriture sensible (monétaire, fiscale, statut) loggue via `AuditLogService::write()`** — aucune exception.
- **Frozen zone `OrderService.php`** : dérogation possible mais doit (a) avoir un scope déclaré avant la modification, (b) ne toucher AUCUNE logique métier (pricing, stock, état, branch), (c) être documentée dans le commit + RUN. Phase H.1.1 est le précédent de référence.
- **Invariants CI** : chaque vague ajoute ses propres greps si elle introduit une nouvelle forme d'invariant (ex : "pas de `$order->payment_status = X` hors `OrderPaymentStateMachine`").
- **Zéro régression baseline** prouvée par checkout `main` + re-test (méthode Phase H §6).

---

## 2. Carte des vagues restantes

| Vague | Scope | Durée estimée | Dépendances | Deliverables |
|---|---|---|---|---|
| **POS-9.4.BL** | Clôturer les 3 BLOCKER POS-9.4 (fiscal seq wire-in, audit log call-sites, destroy-after-Z guard) | 1j | P9.5 mergé ✅ | RUN + VERIFY + BLOCKER files `RESOLVED` |
| **POS-9.2** | State machine canonique (10 items) + CI grep anti-`$order->status =` | 1.5j | POS-9.4.BL mergé | `STATE_MACHINE_CALLSITES_INVENTORY.md` + RUN + VERIFY |
| **POS-9.3** | Multi-tender (table `order_payments`) + events canoniques + correlation_id propagation (10 items) | 1.5j | POS-9.2 mergé | Migration + enum étendu + OrderPaymentStateMachine + RUN + VERIFY |
| **POS-9.5** | Cash drawer hardware : ouverture ESC/POS + comptage début/fin + écarts + mouvements | 1j | POS-9.3 mergé | `CashDrawerService` + endpoints + UI + tests |
| **POS-9.6** | Drawer POS "Gestion commande Kiosk en caisse" — lifecycle actions (accept/preparing/ready/delivered/cancel) + impression ticket côté caisse | 1j | POS-9.2 mergé (endpoints admin cancel/accept/preparing/ready/delivered déjà livrés en 9.2.10) | UI drawer + E2E kiosk+pos |
| **POS-9.7** | Paiement UX complet : split-bill UI + multi-tender UI + pourboires + remises ligne + réduction totale + surcharge | 2j | POS-9.3 mergé | PaymentModal v2 + SplitBillModal + tests |
| **POS-9.8** | Fin de journée production : clôture caisse end-to-end, impression Z, archive chiffrée auto, rapport vente jour, export comptable FR (Sage, Cegid) | 2j | POS-9.5 + POS-9.7 | `DailyClosureService` + rapports + archive cron |
| **POS-9.9** | Offline mode : queue des commandes en l'absence de réseau, reprise auto à la reconnexion, conflits de séquence fiscale | 2j | POS-9.8 | Service Worker + IndexedDB + backend sync endpoint |
| **POS-9.10** | Outbox hardening — idempotent dispatch, replay cursor, retry backoff, DLQ, observabilité Sentry, tests Menu/Pusher → green | 1j | any | Fix des 7 baseline errors + metrics Prometheus |

**Décision 2026-04-18** : la **Vague H.4 optionnelle est supprimée**. D-1 (ticket PDF réel mpdf) et D-2 (chiffrement archive GPG/openssl) sont **absorbés dans POS-9.8** (fin de journée + archive). Raison : anti scope drift — un ticket PDF et une archive chiffrée sont des deliverables naturels de la clôture journée, pas un hotfix séparé. Tracking dans `FINDINGS_POS_TRACKER.md` sous wave POS-9.8.

**Total restant estimé** : ~12-13 jours ingénieur avec discipline Phase H.

---

## 3. Cohérence inter-surfaces (POS ↔ Kiosk)

### 3.1 Zones partagées (SYNC_PROTOCOL §2)

Chaque modification shared (ex: `OrderService`, `OrderStateMachine`, `OrderItemResource`, `EventContract`, migrations `orders`/`order_items`) doit :

1. Poser un `LOCK_B_<file>_<date>.md` après vérification absence `LOCK_A_*`.
2. Relire le BROADCAST Kiosk le plus récent pour absorber les shapes.
3. Déclencher un `BROADCAST_POS_<date>.md` en fin de vague si shared est modifié, avec liste des diffs, shapes nouvelles, impacts sur Track A.

### 3.2 Tests E2E croisés obligatoires

À POS-9.6 (drawer kiosk en caisse) : test E2E "kiosk place commande → POS la voit → POS la passe ACCEPT → KDS la voit → POS la passe DELIVERED → Kiosk front voit 'prête à emporter' → Z report inclut le ticket".

Fichier cible : `tests/e2e/cross-surface-order-lifecycle.spec.js`.

### 3.3 Invariants partagés à maintenir

| Invariant | Kiosk P9.5 | POS-9.2+ | Mécanisme |
|---|---|---|---|
| SSOT pricing | forKiosk cross-item guard | forPos cross-item guard | `PricingService::calculateOrder` + `PricingRequest` |
| IDs-only payload | Kiosk strict | POS peut encore envoyer `total` (nullable) → serveur valide ou recompute | `OrderRequest` / `PosOrderRequest` |
| Idempotency | `(branch_id, idempotency_key)` composite | Même clé côté `posOrderStore` | Migration `2026_04_18_140003` |
| Allergens snapshot | Auto-rempli dans `myOrderStore` | Doit l'être aussi dans `posOrderStore` (POS-9.4.BL.X) | `FrontendOrderService::hydrateAllergenSnapshots` → extraire en helper partagé |
| State machine | `CleanupStalePendingKioskOrders` utilise `apply()` | Tous les sites POS doivent l'utiliser (POS-9.2.1-9.2.4) | Grep CI `$order->status =` = 0 |

---

## 4. Vision produit (côté utilisateur)

Ce que le personnel doit pouvoir faire quand les 10 vagues sont closes :

### 4.1 Caissier (profil courant)

- Ouvrir sa session → pointer son tiroir (comptage d'ouverture).
- Prendre une commande sur place ou à emporter → l'ordre reçoit un `fiscal_sequence_no`, un `correlation_id`, et émet `OrderCreated` + `OrderStatusChanged(PENDING→ACCEPT)`.
- Voir les commandes Kiosk arriver en live (ding visuel + audio, respectant self-exclusion H.3.4) et pouvoir les accepter, préparer, prêtes, livrer.
- Encaisser en mono-tender OU multi-tender (CB + espèces + ticket restaurant) avec reçu détaillé.
- Appliquer des remises ligne ou globales avec justification (loggé `AuditLog`).
- Rembourser partiellement ou totalement un ticket avec motif → `OrderRefunded` + Z-report incrémenté.
- Annuler un ticket avant / après encaissement (selon permission) → `OrderCancelled` + audit trail.
- Fermer sa session → comptage fermeture, écart éventuel, impression Z, archive déposée.
- En cas de coupure réseau : continuer à encaisser en mode offline (POS-9.9), reprise auto.

### 4.2 Manager (profil rare, privilège haut)

- Réouvrir un Z clos par erreur → audit trail + 2FA (POS-9.4.12 permission `pos-reopen-z`).
- Consulter l'archive fiscale 6 ans (archive chiffrée, déchiffrement GPG — POS-9.H.4).
- Exporter comptable mensuel (Sage / Cegid) — POS-9.8.
- Transférer du cash entre tiroirs (inter-caissier) — POS-9.5.

### 4.3 Audit externe (NF525)

- Pointer sur n'importe quelle action sensible → `AuditLogService` a l'empreinte avec chain hash.
- Vérifier la continuité de la chaîne `audit_logs` → `verifyChain()` passe, zéro fork (H.2.2 unique index).
- Vérifier la continuité de la séquence fiscale → `orders.fiscal_sequence_no` monotone sans gap par branch (H.2.10 lockForUpdate).
- Récupérer l'archive 6 ans → scellée, signée, chiffrée, réimpressible.

---

## 5. Dette à absorber

| Dette | Wave cible | Action |
|---|---|---|
| 7 tests baseline Menu/Pusher errors | POS-9.10 | Idempotent dispatch + DLQ |
| Scope drift D-1 PDF réel | POS-9.8 | mpdf + signature embarquée (absorbé, pas de vague séparée — décision 2026-04-18) |
| Scope drift D-2 chiffrement archive | POS-9.8 | GPG + clé publique stockée HSM (absorbé, pas de vague séparée — décision 2026-04-18) |
| OrderService line numbers drift (plan 04-18 stale) | POS-9.2.1 | Refresh dans `STATE_MACHINE_CALLSITES_INVENTORY.md` |
| `OrderItemResource` expose `allergens_snapshot` mais pas de rendu client POS | POS-9.6 | Ajouter chips allergènes dans drawer kiosk en caisse |
| `correlation_id` régénéré par listener | POS-9.3.9 | Middleware + request attribute |
| `FrontendOrderService::hydrateAllergenSnapshots` dupliqué si POS appelle aussi | POS-9.4.BL | Extraire en `OrderItemHydrator` partagé |

---

## 6. Décisions produit en attente

À remonter humain dès que la vague concernée démarre :

1. **POS-9.6 — ownership écran "Gestion commande Kiosk en caisse"** : drawer intégré au POS actuel ou composant dédié `kiosk-cash-manager` ? Le drawer actuel (P9.5.7) est expandable mais reste passif. POS-9.6 le rend actif.
2. **POS-9.7 — pourboires** : pourcentage ou montant libre ? Fiscalisation TVA applicable en France (généralement hors TVA) — valider avec comptable.
3. **POS-9.8 — format export comptable** : Sage (ligne par ligne), Cegid (récapitulatif), FEC (obligatoire DGFiP) ? Défaut : FEC + CSV Sage.
4. **POS-9.9 — politique offline** : combien d'heures on autorise en déconnecté ? Risque : Z en prod déconnecté = trou fiscal. Default : 2 h max, ensuite blocage.
5. **POS-9.H.4 — clé GPG archive** : stockage HSM ou vault ? PGP key rotation ?

---

## 7. Risques stratégiques

### 7.1 Risque "Order state machine divergente"

Symptôme : Kiosk et POS implémentent leur propre transition derrière `apply()`.
Contre-mesure : CI guard `grep "->status\s*=\s*" app/` + tests E2E cross-surface POS-9.6.

### 7.2 Risque "Fiscal sequence gap après crash"

Symptôme : `posOrderStore` alloue un `fiscal_sequence_no` puis crash avant `$order->save()` → gap dans la chaîne.
Contre-mesure : `DB::transaction(fn() => next() + save())` ATOMIQUE, jamais deux transactions.
Mécanisme déjà en place via `FiscalSequenceService::next()` + `lockForUpdate` (H.2.10) mais le wire-in POS-9.4.BL.2b doit garantir l'atomicité côté appelant.

### 7.3 Risque "Outbox leak after rollback"

Symptôme : `Event::dispatch()` dans bloc `DB::transaction` sans `afterCommit` → dispatch même en cas de rollback.
Contre-mesure : invariant CI existant + POS-9.2.6 canonicalise le pattern.

### 7.4 Risque "Double Z close concurrent"

Symptôme : deux managers ouvrent `/z-report/close` en même temps → double clôture.
Contre-mesure : rate-limit existant (H.3.1 10/1 min) + UNIQUE index `(branch_id, z_sequence_no)` déjà présent + `Cache::lock('z_report_close_b{bid}', 30)` dans le service (à vérifier lors de POS-9.8).

### 7.5 Risque "Allergen drift POS vs Kiosk"

Symptôme : POS appelle pas `hydrateAllergenSnapshots` → order POS enregistré sans snapshot → KDS voit `null` → personnel décrit "pas d'info allergène" alors qu'il y en avait une.
Contre-mesure : POS-9.4.BL.1 extrait l'helper + `posOrderStore` l'appelle.

---

## 8. Carnet de route (vision agenda)

```
Semaine A : POS-9.4.BL (1j) ──┐
                              ├── rebase main + commit plan + 3 fixes atomiques
Semaine A : POS-9.2 (1.5j)   ─┘
Semaine B : POS-9.3 (1.5j)   + broadcast sync
Semaine B : POS-9.6 (1j)     (parallélisable avec 9.5 car UI seule)
Semaine C : POS-9.5 (1j)     hardware + tests
Semaine C : POS-9.7 (2j)     payment UX
Semaine D : POS-9.8 (2j)     fin de journée
Semaine E : POS-9.9 (2j)     offline
Semaine E : POS-9.10 (1j)    outbox hardening + résolution baseline

Handoff : #P-1 (stock sync POS↔Kiosk↔KDS) après POS-9.INT et rapport consolidé.

Total : ~12 j ingénieur.
```

---

## 9. Appendice — Références

- Plan master original : `reports/execution/PLAN_PHASE_POS_9_2026-04-18.md`.
- Plan Phase H : `reports/execution/PLAN_PHASE_POS_9_HARDENING_2026-04-18.md`.
- Findings : `tasks/phase9-pos/FINDINGS_POS_TRACKER.md`.
- Sync : `tasks/phase9-pos/SYNC_PROTOCOL_KIOSK_POS.md`.
- Invariants : `tasks/phase9-pos/POS_INVARIANTS_AND_GATES.md`.
- BROADCAST P9.5 : `tasks/phase9-sync/BROADCAST_P9_5_MERGED_2026-04-18.md` (visible sur `main`, pas encore pullé sur `feat/pos-phase-9-hardening`).
- BLOCKERs POS-9.4 : `tasks/phase9-sync/BLOCKER_POS_9_4_{2b,5,10}_*.md`.
- Phase H RUN : `reports/execution/RUN_POS_9_H_2026-04-18.md`.
- Phase H VERIFY : `reports/execution/VERIFY_POS_9_H_2026-04-18.md`.

---

*Vision révisable à chaque fin de vague. La mise à jour est un acte de discipline, pas un acte de compliance.*
