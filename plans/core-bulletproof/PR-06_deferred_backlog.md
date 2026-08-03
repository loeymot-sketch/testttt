# PR-06 — Backlog durcissement DIFFÉRÉ (inventaire, non exécuté)

**Gravité (mandat owner)** : P2-P3 — **secondaire**, hors flux cœur. Amélioré au fil du temps.
**Risque d'exécution** : n/a (doc only ; rien exécuté maintenant).

---

## §1 — Principe
Le mandat owner : seul le cœur (commande→validation→transfert→sync) est obligatoire maintenant. Ces items sont **hors** ce flux → différés, listés pour traçabilité. **Chacun a été vérifié comme réellement différable sans risque cœur.**

## §2 — Inventaire (vérifié adversarialement)
| Item | Ce que c'est | file:line | Pourquoi différable sans risque cœur |
|---|---|---|---|
| **ZRPT refund countersign** | Mirror TVA-netting refund dans Z ; **code+test déjà implémentés**, attend la **signature physique** owner | `plans/LOCK_ZREPORT_REFUND_DISCOUNT_TVA_NETTING_2026-06-01.md:5` ; gate G3 | Concerne la clôture Z (reporting), pas le flux live. Le refund lui-même marche. Différé = gouvernance, pas logique manquante. |
| **COUPON-CAP-01** | ⚠️ **DÉJÀ SHIPPÉ + verrouillé** (ma ligne GOAL était périmée) | `app/Services/CouponService.php` + sentinel `tests/Feature/Coupon/CouponMaxUsesGlobalEnforcementTest.php` ; `PROJECT_BRAIN.md:55` | N'est PAS différé — c'est **fait**. Le résiduel coupon différé = **CAP-02** (redemption non-atomique, P3). Coupons hors chemin capture/transfert (remise recalculée serveur). |
| **Brute-force lockout boot-guard** | Le lockout login est **LIVE** (`throttle:login-lockout`, défaut 10/10min) ; différé = seulement le **boot-guard** refuse-de-booter sur misconfig | limiter `RouteServiceProvider.php:247` ; `config/auth.php:125-126` ; `routes/api.php:161` | Sûr car le limiter tourne déjà avec défaut sécurisé. Auth hors flux commande. |
| **FormRequest authz ratchet** | Resserrer les `return true;` restants vers `can()` + baisser le plafond sentinel | `tests/Feature/Sentinels/FormRequestAuthzDriftSentinelTest.php:65` (BASELINE=66) | Le sentinel **bloque déjà la croissance** (count>baseline = CI rouge) ; différé = resserrer le plafond, pas combler un trou ouvert. CRUD admin, hors flux commande. |

## §3 — NE PAS toucher / RESPECTER
- ZRPT touche le territoire `ZReportService` (FROZEN NF525) → reste derrière LOCK + countersign owner (G3).
- Ne rien exécuter de ce backlog tant que le cœur (PR-01..PR-04) n'est pas validé.

## §4 — Statut
**Documenté, non exécuté.** À reprendre fonction par fonction, à la demande owner, post-validation cœur.
