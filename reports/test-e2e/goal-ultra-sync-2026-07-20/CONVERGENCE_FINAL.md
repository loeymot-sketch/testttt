# CONVERGENCE FINALE — GOAL ultra-profond 12 vagues · 2026-07-20

## Verdict : CONVERGÉ. 12/12 vagues avec preuves réelles. Lot déployé VPS + validé post-deploy.

Double-vérification adversaire+capture appliquée partout (mandat owner « validé techniquement ≠ validé
vraiment »). **2 P0 attrapés par les adversaires que les tests verts masquaient** : (a) programmée créée >8h
avant sa cible = invisible cuisine ; (b) bypass OTP (tout code accepté si phone_verification OFF). Les 2 fermés TDD.

| Vague | Verdict | Preuve maîtresse |
|---|---|---|
| W1 audit total web | ✅ | parité 38/38 + ~45 captures + heals fidélité 10pts déployés |
| W2 calculs (trauma borne) | ✅ | 10/10 AU CENTIME (7 cat borne + 3 web) attendu=quote=scellé=snapshot |
| W3 connectivité | ✅ | propagation +458ms mesurée · « Prête »→compte client (status8+scheduled_at ISO) |
| W4 programmées | ✅ | code 5 lanes + P1 adversaire fixé TDD + 2 CAPTURES KDS réelles |
| W5 Mollie | ✅ struct | fail-closed 8/8 · webhook re-fetch · montant scellé · 0 nouveau chemin NF525 |
| W6 fidélité | ✅ | VPS 0/8 anomalies · unicité tél verrouillée · config alignée prod |
| W8-borne | ✅ | 7/7 catégories, total wizard=récap=panier au centime, 14 captures |
| W8-web | ✅ | 6/7 + P0 OTP fermé (verify réel + preflight CRITICAL) |
| W9 chaos | ✅ | 6/6 survit (429/idempotence/minuit/fiscal/backend-down), 0 P0/P1/P2 |
| W10 cohérence transverse | ✅ | 7/7 égal + fallbacks fidélité healés |
| W11 perf | ✅ | 0 P0/P1 réactivité (N+1 propres ×3), leviers P2 chiffrés |
| W12 double-boucle + deploy | ✅ | 48/48 + 469/469 + 19 JS, NF525 4br OK, frozen 0 → deploy VPS 1ae914d5 |

## Post-deploy VPS validé (2026-07-20)
- **P0 OTP FERMÉ EN LIGNE** : POST verify code faux → HTTP 422 (avant 200). Sécurité prouvée sur le déployé.
- Migration scheduled_at : colonne OK · KDS lead 20 · Mollie enabled=false (fail-closed) · CORS smoke OK.
- NF525 chaîne = TAMPER historique connu (Workstream A, pré-existant, non introduit par ce deploy, gated).

## Structures livrées (fail-closed, plug-and-play aux clés owner)
Commandes programmées (T-20min gate + bandeau ⏰ + « Prête » compte) · Mollie carte web · SMS (gateways existants).

## RESTE STRICTEMENT OWNER (impossible côté agent — pas de fabrication de clés / création de comptes)
1. Clés Mollie test puis live + activation flag (structure prête, 1 décision fiscal_seq web-pur documentée).
2. Provider SMS + clés (préflight bloque le go-live tant que OTP OFF ; validé dès clés).
3. 3 décisions produit : 2ᵉ sauce bols/menu-enfant (existe DB hors profil, 3 vagues convergent) · créneaux web à
   re-proposer sur scheduled_at réel · lead 20min à confirmer.
4. Contrainte DB UNIQUE phone (après dédup) · ~15 champs entité légale.
