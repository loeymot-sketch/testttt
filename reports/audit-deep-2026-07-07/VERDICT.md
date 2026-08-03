# Audit PROFOND interne (mobile + web + DB + internals POS/kiosk) — 2026-07-07

Audit MIEN (pas externe), calibré V1, verify-before-trust : 13 lentilles + réfutation.
Contraste : celui-ci a trouvé du VRAI (les 2 audits externes étaient 85-90% faux).

## Décompte : 60 findings → 21 REAL, 27 BY_DESIGN, 4 FALSE, 8 NOT_APPLICABLE_V1

## Backend V1-pertinent (testttt)
- **label.note (P2 raw label) — CORRIGÉ `d4a5877d0`** : `KioskWizardComponent.vue:2161` (frozen) fait `$t('label.note')` sur le ticket cuisine ; clé ABSENTE en fr.json (seul `label.notes`) → clé brute « label.note » imprimée. Fix 0-frozen = ajout clé i18n FR (parité EN existait). 3 tests.
- **Cache::lock idempotence « jamais libéré » (P3) — FAUX** : mon propre audit s'est trompé, `OrderService.php:698` fait `$idempotencyLock?->release()`. Verify-before-trust a attrapé mon faux positif.
- **DB KDS board full-scan (P2) — EN COURS** : idx_orders_branch_status bypassé (OR advance non-sargable + ORDER BY id), FORCE INDEX 3132→534 rows. Fix backend non-frozen.
- **db-schema 3 tables append-only sans garde DELETE (P3)** : z_reports A des triggers (2 migrations), order_status_transitions/transactions/cash_* à durcir — BACKLOG (défense en profondeur, aucun code ne les supprime).
- **kiosk-offline abandoned order (P3)** : commande cash abandonnée après 10 échecs sync — connu, backlog (borne surface, escalade staff = amélioration).

## Standalone WEB (/Downloads/web/) — EN COURS
- Promo fantôme −10% affichée jusqu'au paiement mais couponId=null → affiché≠facturé (P2).
- Adresse retrait incohérente (437 Rue Élie Gruyelle vs 14 rue de la République canonique) (P2).
- P3 : stepper « Retrait » en mode Livraison, OTP +33 06 mal formaté, onglet connexion cul-de-sac.

## Standalone MOBILE (mobile/) — EN COURS
- Prix commandes mock périmés vs canon (P3) ; ratio fidélité 10 vs 1 pt/€ (P3) ; « Payé en caisse » pour commande non payée (P3) ; bandeau « 153 pts » codé en dur (P3) ; CONNECTION_PLAN.md référence produits FANTÔMES interdits (Box Familiale/Nashville) (P3).

## BY_DESIGN / NOT_APPLICABLE_V1 (exemples)
Mobile/web non câblés backend = design V1 (pas des bugs) ; concerns multi-branche/scale = V2.

## Conclusion
Contrairement aux audits externes, l'audit interne calibré V1 sort du VRAI : 1 P2 backend corrigé (label.note), 1 P2 DB en cours, + standalone web/mobile réels en cours. Aucun P0/P1. Le backend production V1 reste sain.
