# VALIDATION FINALE 2026-07-30 — Findings consolidés (5 auditeurs read-only)

Baseline VÉRIFIÉE : vitest 371/371 · 2653 verts (déterministe, flake happy-dom fixé `eb5236c`) · PHPUnit large 2623 · 0 échec. Les 3 commits laissés par l'agent précédent (email-otp authz, lien historique, beacon) inclus & verts. **0 P0 sur les 5 systèmes.** Money-path / fiscal / synchro / auth prouvés sains.

## P1 réels (à corriger — tous non-frozen)
- **P1-A · BORNE extras inactifs** — `KioskMenuService.php:400` filtre les extras par visibilité seule (jamais `status`), + jumeau `kioskExtrasPartition.js:79`. → options désactivées ressuscitées + doublons + **422 au paiement**. Repro : Bol Frites (item 41) montre « Boule gratinée »/« Option Gratiné » inactifs. Fix : filtrer `status == ACTIVE(5)` côté backend + JS twin + tests.
- **P1-B · WEB bypass OTP DEMO** — `OtpManagerService.php:82` retourne `true` pour tout token si `DEMO=true` ; ses 3 jumeaux sont gardés par le boot-guard prod, pas lui. → `.env DEMO=true` en prod = prise de compte via `/guest-signup/verify`. Fix : ajouter DEMO au set interdit `AppServiceProvider` (boot guard) + `PreflightProductionCommand` + test.
- **P1-C · STOCK/BOM conso aveugle** — `ConsumeRawMaterialsOnOrderCreated.php:39` garde `instanceof Order` → ignore FrontendOrder (borne+web) → vue « À acheter » sous-compte, owner sous-commande. Hors-NF525. Fix : consommer aussi FrontendOrder (listener/garde) + test.

## P2 à corriger (haute valeur, cheap)
- **P2-menu-ES2020** — `data/menu.js:334-335` utilise `??` (non transpilé, boot-critique) → écran blanc vieux mobiles. Fix ES5.
- **P2-coupon-park** — `PosComponent.vue:~4277` : `coupon_id` absent du payload park → remise perdue à la reprise (client lésé). Fix : réinjecter coupon_id.
- **P2-kds-legende** — `KitchenDisplaySystemComponent.vue:1389` : légende omet FRITES/BOISSON pourtant émis. Fix trivial.

## P2 → CHECKLIST GO-LIVE OWNER (documenter, pas bloquant)
- Fidélité mineur : fallback ppe=1 vs 10 (`api.js`), `doRedeem` mort, toggle « SMS » fantôme, commentaire prix périmé.
- BOM compta : refund post-Z libère stock_levels sans reprise matière ; `recent_consumption` ignore les reversals → signal achat gonflé après annulations.
- /m : session déverrouillée survit à la rotation PIN ~12h ; PIN 4 chiffres backstop throttle XFF-spoofable.
- Sync doc : `SYNC_CONTRACT.md` documente 4 events vs ~13 réels (fix S3 sur worktree non-mergé).
- KDS legacy `?v2=0` : variations brutes (rollback-only, cosmétique).
- Sécu go-live générale : clé API front `change-me`, secrets chat à roter, TAMPER staging id=1 (secret fiscal).

## Rejetés (faux positifs, bien filtrés par les auditeurs)
Viande Hachée « parity break » (restore 07-27), formule 422 (ratios 0.76), ingredient-no-broadcast, double-broadcast advisory, clean-orphan timeout Pusher, OrderCanceled (couvert par paire StatusChanged+deleted_ids).
