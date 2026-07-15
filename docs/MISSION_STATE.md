# MISSION_STATE — Registre maître durable FoodKing V1 LOCAL Le Cayenne

> **But de ce fichier** (recommandation /insights « Context-Resilient Long-Running Missions ») :
> registre unique, versionné, qui survit aux resets de contexte. Toute session lit CE résumé
> + le fichier de l'item courant — jamais tout l'historique. Mis à jour à chaque item clos.
>
> **Bootstrap** : lire d'abord `CONSTITUTION.md` → `PROJECT_BRAIN.md §2` → ce fichier.
> Dernière mise à jour : 2026-07-15. Branche : `pos/category-first-caisse-2026-06-23`.
> Remote testttt : `git@github.com:loeymot-sketch/testttt.git` (push = gate owner §10, NON poussé).

---

## ✅ FAIT (livré + testé, cette campagne + sessions parallèles)

| ID | Résumé | Preuve |
|----|--------|--------|
| KDS-01 | Commande web acceptée sans encaissement → board-released (PENDING_COUNTER) | e2e #5714 |
| KDS-02 | Commande web encaissée → PREPARING → écran cuisine | OrderService |
| STOCK-01 | Lane janitor 'web' contre déplétion stock fantôme | CleanupStalePendingKioskOrders |
| CASH-01 + REFUND-01 | Cash-trail NF525 encaissement web (CashMovement IN + pos_payment_method=CASH gaté, refund cash couplé) | 589 tests, frozen 0 |
| RBAC-online-orders | Migration : permission 'online-orders' au rôle POS Operator | migration 2026_07_15_170000 |
| LOY-WEB-01 + REFUND-02 | Fidélité invité web (loyalty_code post-create) + dashboard commandes annulées | user 217→F4657626 |
| COMPO-SAUCE-backend | ItemExtra 'Sauce supplémentaire' @0,50 (14 extras, couche DATA seule, PricingService intouché) | migration 2026_07_15_180000, Tacos M→#431 |
| COMPO-SAUCE-web | menu.js SUPPLEMENT_SAUCE_PRICE + wizard-v2 étape sauce multi + api.js mapping 1ère→attr5 / reste→extra | commit web 05cd406, sauce×2=7,40 validé |
| COMPO-SNAPSHOT-TRIGGER | Trigger DB immutabilité composition_snapshot vivant (régression 2026-07-11 healée) | 6/6 |
| HYG-SENTINEL-NF525 | 3 sentinelles NF525 renommées `*SentinelTest.php` (étaient jamais exécutées) → **a révélé+corrigé dérive doc Z-cron 23:55/00:05→23:59/00:01** | 17/17 verts |
| COMPO-VIANDE-web | Étape viande fusionnée min:N max:N+2, bandeau +2,50 au dépassement, étape séparée supprimée | web `f853a37`, Playwright Tacos M 6,90/9,40/11,90 |
| COMPO-SAUCE-borne | Sauce en plus → vraie ligne ItemExtra dans normalizedExtras (fin display≠sealed), frozen §7 sous LOCK owner | testttt `d753f924b`, LIVE borne preview 200 extras_total 0.5 total 7.4 |
| HEAL-double-release-r2b | P1 double-release isToday (ledger) + Carte 0€ + fat-finger cap 9999.99 | — |
| HEAL-supervisor-destroy | P1 destroy libérait pas stock physique (withTrashed) + 5 P2/P3 + 6 tests | — |
| HEAL-reconciliation-quota | P1 réconciliation libérations perdues + P2 quota + P2 tx carnet | — |
| CARNET-mini-app | Mini-app carnet PIN (dépenses/acomptes/notes+photo) backend+frontend /carnet | — |
| RUPTURE-panel86 | Panel 86 partagé caisse+KDS temps réel + RBAC availability_t | — |
| GOAL-W6-13heals | 13 findings adversarial healés (3 P1) | — |
| DEPLOY-tooling | Rebuild bundles prod + tools/deploy-lecayenne.sh (smoke Host-aware 8766) | — |
| DOCS-secu | FINAL_SECURITY_PHASE_CHECKLIST.md (A-E) + HANDOVER_SECRETS_REGISTRY.md | — |
| NETTOYAGE + ARCHIVE | 453 captures retirées + gitignore ; plans/rapports archivés ; test KDS authz réparé | — |

---

## 🔧 RESTANT — Claude peut faire (non-gated, scope-minimal)

| ID | Sév | Résumé | Note |
|----|-----|--------|------|
| REFUND-SPLIT | P1 | Refund SPLIT tranche-blind : refundGateway lit pos_payment_method → 0 sortie tiroir pour tranches CASH d'une commande multi-tender | breakdown par tranche |
| HYG-SQL-DUMPS | P1 | 7 dumps SQL ~29 Mo trackés dans git (viole §3quater) → git rm --cached + gitignore | |
| HYG-WORKTREES | P2 | 23 worktrees (~15 zombies) + gitlink `.claude/worktrees/clever-hypatia` → prune | |
| COMPO-BOLS | P2 | Bols (items 41/45) sauce-extra hors périmètre v1 — câblage step composer dédié | |
| HYG-MIX-MANIFEST | P3 | public/mix-manifest.json tracké (l'audit 'tracké+gitignoré' était inexact) | |

---

## 🔒 RESTANT — Gate owner (NE PAS exécuter sans autorisation §10)

| ID | Sév | Résumé |
|----|-----|--------|
| OWNER-BOX-INSTALL | P0 | Mac restaurant sans crontab/launchd/worker → 22 lanes scheduler dormantes (backups, moniteurs fiscaux). Décision persistance owner-only |
| GATE-SITE-URL-P0 | P0 | api-base-url/menu-image-base site prod = 127.0.0.1:8766 → funnel mort/mixed-content en prod. Poser URL backend prod repo Vercel |
| OWNER-OUTBOX-FLUSH | P1 | ~10373 events outbox re-queués redis — NE PAS démarrer worker queue avant flush/tri |
| GATE-PUSH-G1 | P1 | Push branche partagée vers origin (multi-sessions) |
| OWNER-DEPLOY-VPS | P1 | Redeploy VPS via `tools/deploy-lecayenne.sh` (avec triggers NF525, PAS deploy-vps.sh) |
| FROZEN-COUPON-SCOPE | P2 | Coupon scopé surface/branche = touche PricingService (SSOT frozen §7) |
| OWNER-PIN-PROD | P2 | Carnet PIN prod : changer DAILY_BOOK_PIN (≠2468) |
| SITE-WEB-CONTENT | P2 | Site Vercel : lien Uber Eats vide, 26 [À COMPLÉTER] LCEN |
| OPS-SOKETI-NODE18 | P2 | soketi Node 18 + vraies clés Pusher + alerte externe MonitorOutbox |

**Sécu différée go-live** (docs/FINAL_SECURITY_PHASE_CHECKLIST.md volets A-E) : SEC-A secrets forts S1-S11, SEC-B kiosk123 public seeder, SEC-C réconciliation chaîne NF525 (human gate), SEC-D topologie VPS php-fpm, SEC-E preflight `app:preflight-production --strict`.

---

## 🎯 DEFINITION OF DONE (10 gates go-live)

1. **Intégrité CI NF525** — 3 sentinelles renommées *SentinelTest.php + vertes ✅ (2026-07-15)
2. **Preflight** — `app:preflight-production --strict` = exit 0 (owner)
3. **Sécu borne** — kiosk123 → secret fort unique (owner)
4. **Persistance box** — crontab + launchd + worker APRÈS flush outbox (owner)
5. **Deploy** — push origin autorisé + redeploy deploy-lecayenne.sh (owner)
6. **Site web prod** — URL backend prod + menu-image-base réels repo Vercel (owner)
7. **Parité facturation compo** — UI web + borne câblées sur ItemExtra ✅ (2026-07-15 : sauce web+borne + viande web ; borne sous LOCK_COMPO_SAUCE_BORNE, prouvé live 7,40). Reste optionnel : viande borne déjà OK (source='extra' existant), frites 2e sauce payante = follow-up si voulu (créer ItemExtra frites)
8. **Refund split** — tiroir = somme réelle tranches CASH (breakdown) ← REFUND-SPLIT restant
9. **Hygiène git** — 0 dump SQL tracké, worktrees prunés, gitlink retiré ← HYG-* restants
10. **Ops temps-réel** — soketi Node 18, BROADCAST_DRIVER≠null, alerte externe (owner)

**Invariants permanents** (re-vérifier à chaque gate) : frozen-zones 0 ligne sans LOCK+gate ; PricingService SSOT ; fiscal_sequence_no gap-free ; chaîne HMAC audit+Z ; isolation branche 20 models.
