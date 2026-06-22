# RAPPORT DE TEST RÉEL — Genie Masterplan campaign
**Date:** 2026-06-16/17 · Branche `goal/wizard-wysiwyg-builder-2026-06-14` HEAD `e18b9ad2a` (9 commits, LOCAL, push=owner gate).
**Question owner:** « est-ce que ça fonctionne de toutes les manières, techniques ET interfaces, ou il y a des problèmes ? »

## ✅ RÉPONSE : OUI — ça fonctionne. Les fixes tiennent, 0 régression, NF525 intact. Les seuls "problèmes" résiduels sont PRÉ-EXISTANTS (pas causés par la campagne) ou owner-gated/plan.

---

## 1. CE QUI A ÉTÉ FAIT + VÉRIFIÉ (Waves 0-4, 9 commits, chacun TDD)
| Wave | Fix | Preuve technique | Frozen |
|---|---|---|---|
| 0 | **FISCAL-CPS-01 P0** changePaymentStatus alloue fiscal_seq→PAID | ChangePaymentStatusFiscalAllocTest 4/4 | 0 |
| 0 | **FISCAL-DELIV-COD-01 P0** (jumeau trouvé par audit) deliveryBoy alloue | DeliveryBoyChangeStatusFiscalAllocTest 3/3 | 0 |
| 0 | FISCAL-SIMULATE-01 dev refuse prod | SimulateKioskOrdersProdGuardTest 1/1 | 0 |
| 1 | CC-4 BranchStatus cascade isolation | BranchStatusCascadeIsolationTest 1/1 + pinning 5/5 | 0 |
| 2 | **A1 jsonError trait + 404 sites / 99 contrôleurs** | JsonErrorTraitSentinel 3/3 + KdsHttpException 6/6 | 0 |
| 3 | B9 a11y (64 boutons close aria-label + 6 for=password) | passwordLabelForId 12/12, build clean | 0 |
| 4 | B6/B7 perf (eager-load mort + SQL count) | report sentinels 42/804 | 0 |

## 2. TEST RÉEL — TECHNIQUE (agent validation, tout LANCÉ)
- **Vitest : 1988 pass / 3 skip / 0 échec.**
- **Feature : 2702 tests, 228 échecs** — **TOUS pré-existants** : la classe harness `MissingIdempotencyKeyException` (« Header X-Idempotency-Key requis », `IdempotencyKeyMiddleware.php:54` = **frozen, intouché, diff vide**) ; ~229 sur le parent. **Les 6 fichiers de test de la campagne PASSENT tous dans le run complet** → 0 attribution campagne (prouvé, pas supposé).
- **Unit : 4 échecs** = `RateLimiterConfigTest` (override `.env` local `POS_RATE_LIMIT_QUOTE=1000` ; test + provider byte-identiques parent↔HEAD) — drift env pré-existant.
- **NF525 chain : `fiscal:verify-chain --all` → CHAIN OK.**
- **Frozen diff (15 fichiers §7) : VIDE / CLEAN.**
- **100 contrôleurs modifiés : 0 erreur de syntaxe**, même forme de réponse (`['status'=>false,'message'=>...]`, code préservé).

## 3. TEST RÉEL — INTERFACE (live :8766, captures)
- **POS** (`realtest-01-pos.png`) : rendu propre, **0 erreur console**, money **FR `Sous-total 0,00 €` / `Total 0,00 €`** (le fix A2 tient live), file BORNE `5,30 €`/`10,00 €`, menu réel.
- **KDS** (`realtest-02-kds.png`) : rendu propre, empty-state gracieux « Aucune commande en cours », « récemment servies » N°A0030…, bannière LOCAL — **le contrôleur KDS A1-converti fonctionne live** (aurait erroré si cassé).
- **Dashboard / login** : auth propre (login→dashboard 0 erreur), session-expiry gérée gracieusement.

## 4. PROBLÈMES ? — distinction honnête
- ❌ **Aucun problème NOUVEAU causé par la campagne** (0 régression nette, prouvé par baseline revert-and-rerun à chaque wave + l'agent global).
- ⚠️ **Pré-existants (PAS la campagne)** : harness X-Idempotency-Key (228 Feature), RateLimiter env-drift (4 Unit) — environnement de test, pas le code applicatif.
- 🔒 **Owner-gated / plan (le RESTE du plan, à relancer avec ta validation)** :
  - **Wave 0b** : backfill des ~52 orphelins fiscaux HISTORIQUES (les fixes empêchent les NOUVEAUX ; le passé = mutation données fiscales rétroactive → **ta décision**).
  - **Dormant-Stripe** (FISCAL-GATEWAY/DRAIN) : même garde à poser à l'activation du paiement online (inactif V1).
  - **Wave 5 bundles** : `npm run production` (SAFE, `pos-wizard.js` hors-Mix confirmé) — étape deploy.
  - **Wave 6** : B3 KDS dual-poll dedup (SYNC_CONTRACT LOCK) + **G-PAYMENT-DISPLAY** (l'écran paiement gelé affiche désormais FR — à valider).
  - **Wave 7 SaaS-prep** (plan-only) : scoper 10 modèles BranchScope, UNI-03 cache-guard, mysql.timezone, Sanctum TTL.
  - **B8** settings-cache (latent, cache OFF) + A1-bis (`Handler.php:133/143`).

## 5. VERDICT
**Le scope EXÉCUTABLE no-harm (Waves 0-4) est FAIT, vérifié technique + interface, 0 régression, frozen 0, NF525 OK.** La boucle a convergé sur tout ce qui est faisable sans gate owner. Le reste (§4 owner-gated/plan) attend tes décisions — dis « go » sur l'un d'eux (ex: 0b backfill, ou ouvrir le LOCK Wave 6) et je relance avec la même discipline.
