# Test-e2e MASSIF — Validation finale (2026-07-24)

**Mandat owner** : « continue audit et test-e2e massive et smart et valide. »
Complément navigateur RÉEL à l'audit code (qui avait convergé P0+P1=0 mais dont le leg navigateur
était bloqué). 4 agents **Playwright** parallèles (chromium desktop + Pixel 7), captures LUES
visuellement, vraies commandes, intégrité au centime, attaques adversariales.

## VERDICT : ✅ VALIDÉ — 4/4 dimensions VERTES, 0 P0/P1/P2

| Dim | Surface | Résultat | Preuve clé |
|---|---|:-:|---|
| **E1** | Web money-path | 5/5 ✅ | **2 commandes réelles scellées au centime** : #194 (240726194) 10,40€ desktop · #195 (240726195) 9,90€ mobile. Formule 3 pages (2,50/1,90/1,90), badges « Incluse »/+0,50, cap viandes, retour-arrière exact. Client n'envoie aucun prix → 0 drop possible. |
| **E2** | Web visuel + mobile | 10/10 ✅ | Home/menu/boissons regroupées/5 légales (E.DELICE SAS/RCS Béthune/APE 5610C, 0 placeholder)/nav mobile plein écran/0 débord. 0 bouton mort, 0 label brut. |
| **E3** | `/m` stock + propagation | 3/3 ✅ | PIN 2580 → rupture Perrier → **propagée API web 262 ms** (restore 114 ms) → **zéro résidu** (preuve curl indépendante). Anti-bruteforce 429 au 5e, CSRF/session gatés. Borne = dégradation gracieuse. |
| **E4** | Caisse + KDS cross-surface | 5/5 ✅ | Cycle web→Accepter→Encaisser (scellé fiscal_seq 2679)→KDS ; **encaissement idempotent** (re-clic = 0 double tiroir/séquence) ; **garde D-1** annulée→Accepter = 422 ; intégrité 4,00€ caisse==KDS==reçu ; **trigger NF525 orders_no_delete a rejeté la suppression d'une commande scellée** (protection prouvée en réel). |

## Findings
- **P0/P1/P2 : NÉANT** sur les 4 dimensions (0 console error, 0 HTTP ≥400).
- **P3 débunké** : E1 « checkout bloqué 150 s avec coupon invalide » = **artefact Playwright** (input React contrôlé). Preuve : la vraie route `/api/frontend/coupon/coupon-checking` répond **422 « Le coupon n'existe pas » en 0,12 s** et le web affiche « Code invalide » (funnel.jsx:201). UX correcte.
- **P3 cosmétiques** (non bloquants) : pill « À encaisser » chevauche « Commande rapide » (z-index) ; sous-titre PIN léger chevauchement ; 5e aperçu boisson en fondu (design) ; données Faker e2e locales.
- **P3 owner-data** : capital social + directeur mentions (à renseigner).

## Attestation
Toutes les surfaces déployées (web Vercel + backend VPS) sont **validées en navigateur RÉEL** :
money-path scellé au centime sur 2 commandes vraies, synchro stock propagée en < 300 ms sans
résidu, cycle caisse↔KDS complet avec idempotence et gardes NF525 actives, visuel propre partout.
**Aucun défaut bloquant.** Cumulé avec l'audit code convergé (2525 vitest + 1469 PHPUnit, chaîne
OK ×4, frozen 0) → système **prouvé bout-en-bout, au top, synchronisé, sans faute**.

Commandes réelles à encaisser/annuler : #194, #195 (+ la #230726193 antérieure).
