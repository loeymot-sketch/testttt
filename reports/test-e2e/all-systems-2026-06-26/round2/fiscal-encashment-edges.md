# RE-ATTAQUE NF525 — Fiscal : edges du chemin d'encaissement (counter-collect)

**Label** : fiscal-encashment-edges
**Cible** : `PaymentService::confirmCounterPayment` (chemin qui vient d'allouer le fiscal_sequence_no **2574**)
**DB** : foodking_e2e (branch 1) — lecture seule
**Verdict global** : **HOLD** — les défenses fiscales du chemin counter-collect tiennent sur (a), (b), (c) et (d).
Les anomalies observées en DB (3 gaps + 63 orphelins cross-Z + 19 PAID-sans-fiscal) sont **toutes expliquées** : artefacts de test-DB (hard-delete de fixtures, prod-guardé) ou items **connus/déférés/monitorés** appartenant à d'AUTRES chemins. Aucune faille **neuve** du chemin d'encaissement.

---

## [HOLD] (a) Échec partiel (collect OK, broadcast/notif KO) → ni gap ni double — `app/Services/PaymentService.php:219-448`

**Défense prouvée par le code.**
- `fiscal_sequence_no` est **alloué (L322) et persisté via `$locked->save()` (L362) À L'INTÉRIEUR du `DB::transaction` (L219-406)**. `$paid=true` n'est posé qu'à L405, dans la transaction.
- `OrderPaidAtCounter::dispatch` (L409) et le broadcast `OrderStatusChanged` (L422-433, **enveloppé try/catch** best-effort) s'exécutent **APRÈS le commit**. Un échec broadcast/notif post-commit ne peut **pas** rollback la ligne déjà commitée → **pas de gap, pas de double**.
- Le `recordCashOrderMovement` strict (L443) peut throw **avant** commit → toute la transaction rollback ensemble (PAID + fiscal + status). Cohérent.
- `FiscalSequenceService::next()` (`app/Services/Fiscal/FiscalSequenceService.php:97-103`) = `MAX(fiscal_sequence_no)+1` avec `withTrashed()`, sous `Cache::lock` + `lockForUpdate`. **Propriété auto-réparatrice** : une transaction rollbackée ne commite jamais le numéro → MAX inchangé → l'appel suivant **réémet le même numéro** → impossible de créer un gap par rollback.
- **Double-collect concurrent** : `lockForUpdate` (L222) + garde already-PAID (L278-310) → le perdant de la course reçoit `PaymentAlreadyCollectedException` (409), **aucune 2ᵉ allocation fiscale** → pas de double.

## [HOLD] (b) Séquence monotone + gap-free par branche — DB branch 1

```
min_seq=1  max_seq=2574  count=2571  distinct=2571  → 0 doublon (strictement monotone)
```
- **0 duplicate** `fiscal_sequence_no` (GROUP BY HAVING COUNT>1 = vide).
- **3 gaps** : 2506, 2507, 2508. Absents même en raw-SQL (qui voit les soft-deleted) → lignes **physiquement supprimées (hard-delete)**.
- **Cause prouvée (PAS le chemin d'encaissement)** : `app/Console/Commands/Iter15CleanupTestOrdersCommand.php:97` fait `DB::table('orders')->whereIn('id',$ids)->delete()` (raw = hard-delete bypass soft-delete). Cette commande **REFUSE la production** (L64-67 `app()->environment('production')` → return 2) et ne cible que des **tokens de test** (`AUDIT-%`,`RED-TEAM-%`,`ZZ-TEST-%`,`TEST-%`,`E2E-%` — L44-50) en statut KDS actif [1,4,7,8].
- Le chemin d'encaissement, lui, ne peut **ni** créer un gap (alloc MAX-based auto-réparatrice) **ni** un double (unique key `orders_branch_fiscal_seq_unique` + garde already-PAID + lock). → **défense intacte ; les 3 gaps = artefact test-DB prod-guardé.**

## [HOLD] (c) composition_snapshot alloué AVANT le fiscal — preuve data ordre 2574

- `order_items.composition_snapshot` est figé à la **création** de la commande ; le counter-collect alloue le fiscal au **paiement** (plus tard). Ordre temporel garanti par construction.
- Preuve ordre 2574 (id 5179) : item 4937 `composition_snapshot IS NOT NULL` créé **14:21:20** ; `fiscal_sequence_no=2574` + PAID à `updated_at` **15:28:31**. Snapshot précède le fiscal de ~1h. ✓

## [HOLD] (d) Un Z fermé inclut bien 2574 — `app/Services/Fiscal/ZReportService.php:337-346`

- Ordre 2574 (id 5179) créé **2026-06-26 14:21:20**, dans la fenêtre du **Z-24 OUVERT** (opened_at 2026-06-25 14:09:07). À la fermeture, `aggregate()` fenêtre `(opened_at, closedAt]` sur `created_at` + `withTrashed` + `whereNotNull(fiscal_sequence_no)` + `payment_status != UNPAID` → **2574 sera inclus**. ✓ pour 2574.

---

## Items re-confirmés mais **CONNUS / DÉFÉRÉS / MONITORÉS** (à dédupliquer par le parent — PAS des findings neufs)

### (B-note) 63 commandes fiscalisées orphelines de tout Z — connue, **monitorée par cron dédié**
- Le Z fenêtre sur `created_at` borne basse = `opened_at`. Tout ordre créé dans le **temps mort entre un Z fermé et le Z suivant ouvert** est orphelin de tous les Z.
- foodking_e2e : Z-23 fermé `2026-06-19 12:34:58`, Z-24 ouvert `2026-06-25 14:09:07` → **dead-zone 6 jours**. **63 ordres PAID+fiscalisés** (fiscal 2073, 2509-2570, **~427,75 €**) créés dedans → actuellement dans **AUCUN Z**.
- **Connu et OUTILLÉ** : le projet ship déjà un détecteur read-only `fiscal:verify-z-membership` (`app/Console/Commands/VerifyZMembershipCommand.php`, planifié `app/Console/Kernel.php:91` dailyAt 06:05) dont le commentaire (Kernel L84-90) décrit exactement « any fiscally-numbered order at risk of appearing in NO signed Z (cross-Z-window orphan) ». SPOF open/close documenté `reports/audits/Z-REPORT-DRY-RUN-2026-05-25.md` (F-5, SPOF-07).
- **Artefact opérationnel de test-DB** (aucun cron daily open/close n'a tourné ; des ordres fiscalisés ont été pris sans Z ouvert). **PAS causé par le chemin counter-collect** (l'essentiel des 63 sont `source=pos` direct-sale). Mémoire : « Z-window connu/déféré ». → **dédup, pas un P0 neuf.**

### (FISCAL-CPS) 19 commandes PAID sans fiscal_sequence_no — connue, owner-gated
- branch 1 : 19 ordres `payment_status=5` (PAID) avec `fiscal_sequence_no IS NULL` (échantillon = `source=delivery`/NULL, `fiscal_alloc_error_at` NULL).
- = signature **FISCAL-CPS-01** (`OrderService::changePaymentStatus` →PAID sans `FiscalSequenceService::next()` pour delivery/COD), **déjà owner-gated G-FISC-CPS** (mémoire).
- **PAS le chemin counter-collect** : `confirmCounterPayment` alloue TOUJOURS le fiscal si null (`PaymentService.php:321-323`) → 0 contribution à ces 19. → **autre chemin, item connu.**

---

## Conclusion
**HOLD.** Le chemin d'encaissement comptoir (`confirmCounterPayment`) est fiscalement solide : alloc+persist transactionnels, broadcast/notif post-commit best-effort, alloc MAX-based auto-réparatrice, snapshot pré-fiscal, inclusion Z correcte pour 2574. Les 3 gaps = hard-delete de fixtures (commande prod-guardée). Les 63 orphelins cross-Z et 19 PAID-sans-fiscal appartiennent à d'autres chemins déjà connus/déférés/monitorés (verify-z-membership / G-FISC-CPS) — **à dédupliquer, aucun finding neuf du chemin counter-collect.**

**Heal autonome** : aucun. Les zones concernées par les items connus (`ZReportService`, `FiscalSequenceService`) sont **frozen NF525** et les items sont owner-gated/déférés → escalade owner requise, pas de heal autonome.
