# VALIDATION FINALE — CONVERGENCE (2026-07-30)

Campagne intégrateur (S6) : prise de contrôle après l'agent précédent (terrain
confirmé calme), audit 5 systèmes, heals, re-validation, deploy. Autonome.

## Verdict : **0 P0 · 3 P1 tous fermés+testés+déployés · P2 documentés go-live**

## Baseline établie (vérifiée, pas « on m'a dit »)
- Les 3 commits laissés par l'agent précédent (email-otp authz, lien historique, beacon) : validés, verts.
- **Flake vitest neutralisé** (`eb5236c`) : racine = happy-dom partage `document` entre fichiers parallèles → observers pos-wizard.js s'accumulent. Fix `fileParallelism:false`. Code produit PROUVÉ sain (isolé + groupé passent). Preuve : parallèle=1-3 flaky ; sérialisé=371/371 ×3.

## Audit adversarial 5 systèmes (read-only, findings vérifiés/rejetés)
| Système | P0 | P1 | Cœur |
|---|---|---|---|
| Synchro | 0 | 0 | outbox atomique, anti-doublage airtight, n° commande 100% serveur, scheduler sain |
| KDS/OSS/ticket | 0 | 0 | parité PHP↔JS prouvée end-to-end |
| Caisse money-path | 0 | 0 | split/refund/fiscal sains, nav 100% |
| Web | 0 | 1 | bypass OTP DEMO |
| Borne | 0 | 1 | extras inactifs (faux-positif live + vrai trou offline) |
| Stock/BOM | 0 | 1 | conso aveugle borne/web |

Faux positifs bien rejetés par les auditeurs (Viande Hachée, formule 422, etc.).

## Heals (tous testés TDD, 0 frozen, commités+déployés)
- **P1-A** `7edb6ac` — extras status=ACTIVE (twin JS offline + defense-in-depth). Kiosk 453/0 + vitest 81/0.
- **P1-B** `b48009e` — DEMO=true INTERDIT en prod (boot guard + preflight + sentinelle). Boot|Preflight|Demo|Otp|Security 243/0.
- **P1-C** `4c1776d` — conso+reverse matière FrontendOrder (symétrie fermée). RawMaterial|Consum|Stock 239/0.
- **P2** `8f7fcca`/`2a65e3d` — légende KDS FRITES/BOISSON + coupon_id park/reprise + résolveur sentinel (plus récent par mtime).
- **P2** web `ea8ef55` — `??`→ES5 dans data/menu.js (boot-critique vieux mobiles).
- **fix outil** `6dcdb73` — smoke CORS deploy teste la 1ʳᵉ origine de la liste (faux « CORS cassé »).

## Preuves finales
- PHPUnit large **2737 · 12676 assert · 0 échec**.
- vitest full **371/371 · 2654 · 0 échec** (déterministe).
- Frozen diff **0** sur toute la plage. Chaîne NF525 OK (TAMPER staging id=1 = connu documenté).
- Deploy VPS `2a65e3d` (healthz vert, bundle frais, **CORS 3 origines prouvé**). Web Vercel `ea8ef55` (menu.js ES5 live).
- Live nav-smoke www.lecayenne.fr **13/13 · 0 erreur JS**.

## Reste → CHECKLIST GO-LIVE OWNER (P2 non bloquants, documentés FINDINGS_CONSOLIDATED.md)
Fidélité mineur ; BOM refund-post-Z reversal + recent_consumption reversals ;
/m session vs rotation PIN + throttle XFF ; SYNC_CONTRACT doc drift (fix S3 worktree) ;
sécu go-live (clé API front `change-me`, secrets chat à roter, secret fiscal TAMPER).
